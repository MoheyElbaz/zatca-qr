<?php

use ZATCA\GenerateQrCode;
use ZATCA\Tag;
use ZATCA\Tags\InvoiceDate;
use ZATCA\Tags\InvoiceTaxAmount;
use ZATCA\Tags\InvoiceTotalAmount;
use ZATCA\Tags\Seller;
use ZATCA\Tags\TaxNumber;

test('phase 1 TLV matches the byte layout ZATCA specifies', function () {
    $tlv = GenerateQrCode::fromArray([
        new Seller('Bobs Records'),
        new TaxNumber('310175397400003'),
        new InvoiceDate('2022-04-25T15:30:00Z'),
        new InvoiceTotalAmount('1000.00'),
        new InvoiceTaxAmount('150.00'),
    ])->toTLV();

    $expected = "\x01\x0C" . 'Bobs Records'
        . "\x02\x0F" . '310175397400003'
        . "\x03\x14" . '2022-04-25T15:30:00Z'
        . "\x04\x07" . '1000.00'
        . "\x05\x06" . '150.00';

    same(bin2hex($expected), bin2hex($tlv), 'The encoded TLV does not match the expected tag/length/value layout');
    same(base64_encode($expected), GenerateQrCode::fromArray([
        new Seller('Bobs Records'),
        new TaxNumber('310175397400003'),
        new InvoiceDate('2022-04-25T15:30:00Z'),
        new InvoiceTotalAmount('1000.00'),
        new InvoiceTaxAmount('150.00'),
    ])->toBase64(), 'toBase64() must be the base64 of the TLV bytes');
});

test('a zero VAT amount is encoded instead of being dropped', function () {
    // Zero rated and exempt simplified invoices are ordinary in KSA: tag 5 must still
    // carry the value "0", not an empty field.
    foreach (['0', 0, '0.00', 0.0] as $zero) {
        $tags = decode_tlv(GenerateQrCode::fromArray([
            new Seller('Qr'),
            new TaxNumber('301121971500003'),
            new InvoiceDate('2022-04-25T15:30:00Z'),
            new InvoiceTotalAmount('100.00'),
            new InvoiceTaxAmount($zero),
        ])->toBase64());

        same((string) $zero, $tags[5], 'A zero tax amount must survive the TLV encoding');
    }
});

test('the length field counts bytes, not characters', function () {
    $arabic = 'مؤسسة'; // 5 characters, 10 bytes in UTF-8

    same(5, mb_strlen($arabic, 'UTF-8'), 'sanity check on the fixture');

    $tag = new Seller($arabic);

    same(10, $tag->getLength(), 'The TLV length must be the UTF-8 byte length');
    same("\x01\x0A" . $arabic, (string) $tag, 'The encoded tag must announce 10 bytes');
});

test('a value that cannot fit in the single length byte is rejected', function () {
    $too_long = str_repeat('a', 256);

    $thrown = throws(LengthException::class, function () use ($too_long) {
        (string) new Seller($too_long);
    }, 'A 256 byte value cannot be expressed by a one byte length field');

    contains('256 bytes', $thrown->getMessage(), 'The exception should say how long the value was');

    // 255 bytes is still fine.
    same(255, (new Seller(str_repeat('a', 255)))->getLength(), '255 bytes is the largest encodable value');
    same(255, Tag::MAX_VALUE_LENGTH, 'The documented maximum should match the encoder');
});

test('a null value encodes as an empty field', function () {
    $tag = new Seller(null);

    same('', $tag->getValue(), 'A null value reads back as an empty string');
    same("\x01\x00", (string) $tag, 'A null value encodes as a zero length field');
});

test('the QR generator rejects anything that is not a Tag', function () {
    $thrown = throws(InvalidArgumentException::class, function () {
        GenerateQrCode::fromArray([new Seller('Qr'), 'junk', null]);
    }, 'Non-Tag entries must be rejected rather than silently dropped');

    contains('entry 1', $thrown->getMessage(), 'The exception should point at the offending entry');

    throws(InvalidArgumentException::class, function () {
        GenerateQrCode::fromArray([]);
    }, 'An empty tag list is malformed');
});
