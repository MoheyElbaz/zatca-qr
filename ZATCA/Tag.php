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

    public function __construct($tag, $value)
    {
        $this->tag = $tag;
        $this->value = $value;
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
        return $this->value ?: "";
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
                'Tag %d: value is %d bytes once UTF-8 encoded, but ZATCA stores the TLV length in a single byte (max %d). Shorten the field before generating the QR code.',
                $this->getTag(),
                $value,
                self::MAX_VALUE_LENGTH
            ));
        }

        return chr($value);
    }
}
