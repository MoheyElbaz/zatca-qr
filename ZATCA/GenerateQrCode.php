<?php

namespace ZATCA;

use InvalidArgumentException;

class GenerateQrCode
{
    /**
     * @var Tag[] $data The list of tags
     */
    protected $data = [];

    /**
     * @param  Tag[]  $data  The list of tags
     *
     * @throws InvalidArgumentException If the TLV data structure
     *         contains other data than Tag instances.
     */
    private function __construct(array $data)
    {
        foreach ($data as $index => $tag) {
            if (!$tag instanceof Tag) {
                throw new InvalidArgumentException(sprintf(
                    'malformed data structure: entry %s is a %s, expected a %s instance.',
                    (string) $index,
                    get_debug_type($tag),
                    Tag::class
                ));
            }
        }

        if (count($data) === 0) {
            throw new InvalidArgumentException('malformed data structure: no tags given.');
        }

        $this->data = $data;
    }

    /**
     * Initial the generator from list of tags.
     *
     * @param  Tag[]  $data  The list of tags
     *
     * @return GenerateQrCode
     */
    public static function fromArray(array $data): GenerateQrCode
    {
        return new self($data);
    }

    /**
     * Encodes an TLV data structure.
     *
     * @return string Returns a string representing the encoded TLV data structure.
     */
    public function toTLV(): string
    {
        return implode('', array_map(function ($tag) {
            return (string) $tag;
        }, $this->data));
    }

    /**
     * Encodes an TLV as base64
     *
     * @return string Returns the TLV as base64 encode.
     */
    public function toBase64(): string
    {
        return base64_encode($this->toTLV());
    }
}
