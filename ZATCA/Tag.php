<?php

namespace ZATCA;

use LengthException;

class Tag
{
    /**
     * ZATCA stores the length in a single byte, so a field value can never
     * exceed 255 bytes once UTF-8 encoded.
     *
     * @see docs/20220624_ZATCA_Electronic_Invoice_Security_Features_Implementation_Standards.pdf
     *      section 4.1: "The length shall be stored in one byte."
     */
    const MAX_VALUE_LENGTH = 255;

    /**
     * The tag is stored in a single byte too. Kept separate from
     * MAX_VALUE_LENGTH so that narrowing one limit later cannot silently
     * narrow the other.
     */
    const MAX_TAG = 255;

    protected $tag;

    protected $value;

    protected $fallback;

    /**
     * @param  int  $tag
     * @param  string  $value
     * @param  string|null  $fallback  Used in place of $value when $value does
     *         not fit in the single length byte. For tag 1 this is the English
     *         trade name from the VAT certificate: an Arabic letter is two
     *         bytes in UTF-8, so a long registered name can overflow while its
     *         English equivalent fits.
     */
    public function __construct($tag, $value, $fallback = null)
    {
        $this->tag = $tag;
        $this->value = $value;
        $this->fallback = $fallback;
    }

    /**
     * Whether the fallback is standing in for the original value.
     *
     * @return bool
     */
    public function usedFallback()
    {
        return $this->fallback !== null
            && trim((string) $this->fallback) !== ""
            && strlen($this->value === null ? "" : (string) $this->value) > self::MAX_VALUE_LENGTH;
    }

    /**
     * @return int
     */
    public function getTag()
    {
        return $this->tag;
    }

    /**
     * @return string
     */
    public function getValue()
    {
        if ($this->usedFallback()) {
            return (string) $this->fallback;
        }

        // "0" is a legitimate value for a zero-rated or exempt invoice, so only
        // null is treated as absent. A falsy check here would emit tag 5 with
        // length 0 and no value at all, which ZATCA rejects.
        return $this->value === null ? "" : (string) $this->value;
    }

    /**
     * its important to get the number of bytes of a string instated of number of characters
     *
     * @return false|int
     */
    public function getLength()
    {
        return strlen($this->getValue());
    }

    /**
     * @return string Returns a string representing the encoded TLV data structure.
     *
     * @throws LengthException If the value does not fit in the single length byte
     *         ZATCA allows, which would otherwise emit a malformed TLV.
     */
    public function __toString()
    {
        $value = $this->getValue();

        return $this->toTagByte()
            . $this->toLengthByte($value)
            . $value;
    }

    /**
     * Encode the tag as the single unsigned byte ZATCA mandates.
     *
     * @return string
     *
     * @throws LengthException If the tag cannot be held in one byte.
     */
    protected function toTagByte()
    {
        $tag = $this->getTag();

        if ($tag < 0 || $tag > self::MAX_TAG) {
            throw new LengthException(sprintf(
                'Tag id %d is out of range, ZATCA stores the tag in a single byte (0 to %d).',
                $tag,
                self::MAX_TAG
            ));
        }

        return chr($tag);
    }

    /**
     * Explains, in the exception, why the fallback did not rescue this tag.
     *
     * @return string
     */
    protected function fallbackHint()
    {
        if ($this->fallback === null) {
            return 'Pass a shorter fallback value, such as the English trade name on the VAT certificate.';
        }

        if (trim((string) $this->fallback) === '') {
            return 'The fallback is empty, so it was not used.';
        }

        return 'The fallback does not fit either.';
    }

    /**
     * Encode the value length as the single unsigned byte ZATCA mandates.
     *
     * @param  string  $value  The exact string being written.
     *
     * @return string
     *
     * @throws LengthException If the value is too long for one length byte.
     */
    protected function toLengthByte($value)
    {
        // Measured from the string __toString() is about to emit, not from a
        // second getValue() call: an override that is not idempotent would
        // otherwise declare a length for one string and write another,
        // misaligning every tag that follows.
        $length = strlen($value);

        if ($length > self::MAX_VALUE_LENGTH) {
            throw new LengthException(sprintf(
                'Tag %d: value is %d bytes once UTF-8 encoded, but ZATCA stores the TLV length in a single byte (max %d). %s',
                $this->getTag(),
                $length,
                self::MAX_VALUE_LENGTH,
                $this->fallbackHint()
            ));
        }

        return chr($length);
    }
}
