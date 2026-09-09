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
            && strlen($this->value ?: "") > self::MAX_VALUE_LENGTH;
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
        return $this->usedFallback() ? $this->fallback : ($this->value ?: "");
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
        return $this->toByte($this->getTag())
            . $this->toByte($this->getLength())
            . $this->getValue();
    }

    /**
     * Encode a tag or length as the single unsigned byte ZATCA mandates.
     *
     * @param  int  $value
     *
     * @return string
     *
     * @throws LengthException If the value cannot be held in one byte.
     */
    protected function toByte($value)
    {
        if ($value < 0 || $value > self::MAX_VALUE_LENGTH) {
            throw new LengthException(sprintf(
                'Tag %d: value is %d bytes once UTF-8 encoded, but ZATCA stores the TLV length in a single byte (max %d). %s',
                $this->getTag(),
                $value,
                self::MAX_VALUE_LENGTH,
                $this->fallback === null
                    ? 'Pass a shorter fallback value, such as the English trade name on the VAT certificate.'
                    : 'The fallback does not fit either.'
            ));
        }

        return chr($value);
    }
}
