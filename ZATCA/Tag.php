<?php

namespace ZATCA;

use LengthException;

class Tag
{
    /**
     * The TLV length field is a single byte, so a value can never exceed 255 bytes.
     */
    public const MAX_VALUE_LENGTH = 255;

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
     * Null coalescing (not `?:`) so that a legitimate zero value — a VAT amount of
     * "0" on an exempt or zero rated invoice — is kept instead of being emitted as
     * an empty field.
     *
     * @return string
     */
    public function getValue()
    {
        return (string) ($this->value ?? '');
    }

    /**
     * its important to get the number of bytes of a string instead of number of characters
     *
     * @return int
     */
    public function getLength()
    {
        return strlen($this->getValue());
    }

    /**
     * @return string Returns a string representing the encoded TLV data structure.
     *
     * @throws LengthException If the value does not fit in a single length byte.
     */
    public function __toString()
    {
        $value = $this->getValue();
        $length = strlen($value);

        if ($length > self::MAX_VALUE_LENGTH) {
            throw new LengthException(sprintf(
                'TLV tag %s carries %d bytes. The ZATCA QR length field is a single byte, so a value may not exceed %d bytes (note that Arabic characters take 2 bytes each).',
                $this->getTag(),
                $length,
                self::MAX_VALUE_LENGTH
            ));
        }

        return $this->toHex($this->getTag()) . $this->toHex($length) . $value;
    }

    /**
     * To convert a byte value to its binary representation.
     *
     * @param  int  $value
     *
     * @return string
     */
    protected function toHex($value)
    {
        return pack('C', (int) $value);
    }
}
