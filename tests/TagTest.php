<?php

namespace ZATCA\Tests;

use LengthException;
use PHPUnit\Framework\TestCase;
use ZATCA\GenerateQrCode;
use ZATCA\Tag;
use ZATCA\Tags\InvoiceDate;
use ZATCA\Tags\InvoiceTaxAmount;
use ZATCA\Tags\InvoiceTotalAmount;
use ZATCA\Tags\Seller;
use ZATCA\Tags\TaxNumber;

class TagTest extends TestCase
{
    /**
     * Decode a TLV byte string back into [tag => value], reading the length
     * as the single unsigned byte ZATCA specifies.
     *
     * @return array
     */
    private function decodeTlv(string $tlv): array
    {
        $out = [];
        $i = 0;
        $len = strlen($tlv);

        while ($i < $len) {
            $this->assertLessThanOrEqual($len, $i + 2, 'truncated TLV header');
            $tag = ord($tlv[$i]);
            $length = ord($tlv[$i + 1]);
            $value = substr($tlv, $i + 2, $length);

            $this->assertSame($length, strlen($value), sprintf('tag %d declares %d bytes but only %d remain', $tag, $length, strlen($value)));

            $out[$tag] = $value;
            $i += 2 + $length;
        }

        return $out;
    }

    public function test_it_encodes_tag_and_length_as_single_bytes(): void
    {
        $tag = (string) new Seller('Qr');

        $this->assertSame('01025172', strtoupper(bin2hex($tag)));
        $this->assertSame(4, strlen($tag));
    }

    /**
     * Regression guard: the fix must not alter output for anything under the
     * one-byte ceiling, since those QR codes are already in production.
     *
     * @dataProvider lengthsThatFitInOneByte
     */
    public function test_length_below_the_ceiling_is_one_byte(int $length): void
    {
        $tag = (string) new Seller(str_repeat('a', $length));

        $this->assertSame($length + 2, strlen($tag), 'expected tag byte + length byte + value');
        $this->assertSame($length, ord($tag[1]), 'length byte must hold the value verbatim');
    }

    public static function lengthsThatFitInOneByte(): array
    {
        return [
            'empty'           => [0],
            'short'           => [5],
            'below 0x80'      => [127],
            'at 0x80'         => [128],
            'above 0x80'      => [200],
            'at the ceiling'  => [255],
        ];
    }

    /**
     * Issue #74 upstream claims a 138-byte Arabic trade name breaks the QR
     * because BER would read 0x8A as a multi-byte length prefix. ZATCA does not
     * use BER: section 4.1 mandates an unsigned 8-bit integer, so 0x8A means
     * 138 and this name encodes correctly.
     */
    public function test_long_arabic_trade_name_encodes_correctly(): void
    {
        $name = 'مؤسسة التقنية المتقدمة للتجارة والمقاولات العامة بالمنطقة الوسطى المحدودة';

        $this->assertSame(73, mb_strlen($name), 'sanity: character count');
        $this->assertSame(138, strlen($name), 'sanity: UTF-8 byte count');

        $tag = (string) new Seller($name);

        $this->assertSame(0x8A, ord($tag[1]), 'length byte is 138, not a BER prefix');
        $this->assertSame($name, $this->decodeTlv($tag)[1]);
    }

    public function test_it_rejects_a_value_that_overflows_the_length_byte(): void
    {
        $this->expectException(LengthException::class);
        $this->expectExceptionMessageMatches('/256 bytes.*single byte/');

        (string) new Seller(str_repeat('a', 256));
    }

    /**
     * Before the fix, sprintf('%02X', 300) produced "12C", which pack('H*')
     * padded to the two bytes 0x12 0xC0 — a silently malformed QR rather than
     * an error.
     */
    public function test_it_does_not_silently_emit_a_multi_byte_length(): void
    {
        foreach ([256, 300, 512] as $length) {
            try {
                $tag = (string) new Seller(str_repeat('a', $length));
            } catch (LengthException $e) {
                continue;
            }

            $this->fail(sprintf(
                '%d-byte value produced a %d-byte tag instead of throwing',
                $length,
                strlen($tag)
            ));
        }

        $this->expectNotToPerformAssertions();
    }

    public function test_full_phase_one_qr_round_trips(): void
    {
        $name = 'مؤسسة التقنية المتقدمة للتجارة والمقاولات العامة بالمنطقة الوسطى المحدودة';

        $base64 = GenerateQrCode::fromArray([
            new Seller($name),
            new TaxNumber('323457892389823'),
            new InvoiceDate('2021-07-12T14:25:09Z'),
            new InvoiceTotalAmount('100.00'),
            new InvoiceTaxAmount('15.00'),
        ])->toBase64();

        $decoded = $this->decodeTlv(base64_decode($base64, true));

        $this->assertSame($name, $decoded[1]);
        $this->assertSame('323457892389823', $decoded[2]);
        $this->assertSame('2021-07-12T14:25:09Z', $decoded[3]);
        $this->assertSame('100.00', $decoded[4]);
        $this->assertSame('15.00', $decoded[5]);
        $this->assertLessThanOrEqual(700, strlen($base64), 'ZATCA caps the QR payload at 700 characters');
    }

    public function test_it_falls_back_to_the_english_name_when_the_arabic_is_too_long(): void
    {
        $arabic = str_repeat('ش', 130);   // 260 bytes
        $english = 'Al Waed Al Afdal Trading Co';

        $this->assertGreaterThan(Tag::MAX_VALUE_LENGTH, strlen($arabic), 'sanity: the Arabic name overflows');

        $tag = new Seller($arabic, $english);

        $this->assertTrue($tag->usedFallback());
        $this->assertSame($english, $this->decodeTlv((string) $tag)[1]);
    }

    public function test_it_keeps_the_arabic_name_when_it_fits(): void
    {
        $arabic = 'شركة الواعد الافضل';
        $tag = new Seller($arabic, 'Al Waed Al Afdal Trading Co');

        $this->assertFalse($tag->usedFallback(), 'the fallback must not be used when the Arabic fits');
        $this->assertSame($arabic, $this->decodeTlv((string) $tag)[1]);
    }

    public function test_it_still_throws_when_the_fallback_also_overflows(): void
    {
        $this->expectException(LengthException::class);
        $this->expectExceptionMessageMatches('/fallback does not fit/');

        (string) new Seller(str_repeat('ش', 130), str_repeat('a', 256));
    }

    /**
     * A zero-rated or exempt invoice legitimately carries "0". A falsy check
     * would emit the tag with length 0 and no value, which ZATCA rejects.
     *
     * @dataProvider zeroValues
     */
    public function test_it_encodes_zero_values(string $value): void
    {
        $tag = (string) new InvoiceTaxAmount($value);

        $this->assertSame($value, $this->decodeTlv($tag)[5]);
        $this->assertSame(strlen($value), ord($tag[1]));
    }

    public static function zeroValues(): array
    {
        return [
            'bare zero' => ['0'],
            'zero with decimals' => ['0.00'],
            'zero float string' => ['0.0'],
        ];
    }

    public function test_an_empty_value_is_still_encoded_as_empty(): void
    {
        $tag = (string) new Seller('');

        $this->assertSame('0100', strtolower(bin2hex($tag)));
    }

    public function test_a_blank_fallback_is_not_used_in_place_of_the_name(): void
    {
        $this->expectException(LengthException::class);
        $this->expectExceptionMessageMatches('/fallback is empty/');

        (string) new Seller(str_repeat('ش', 130), '   ');
    }

    /**
     * __toString() must measure the exact string it writes. Taking the length
     * from a second getValue() call let a non-idempotent override declare one
     * length and emit another, misaligning every following tag.
     */
    public function test_it_measures_the_same_call_it_writes(): void
    {
        $tag = new class(1, 'ignored') extends Tag
        {
            private int $calls = 0;

            public function getValue()
            {
                return str_repeat('x', 10 - (2 * $this->calls++));
            }
        };

        $encoded = (string) $tag;

        $this->assertSame(strlen($encoded) - 2, ord($encoded[1]));
    }

    public function test_tag_number_is_also_bounded(): void
    {
        $this->expectException(LengthException::class);
        $this->expectExceptionMessageMatches('/Tag id 256 is out of range/');

        (string) new Tag(256, 'x');
    }
}
