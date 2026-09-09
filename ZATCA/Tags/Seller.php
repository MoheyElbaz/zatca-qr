<?php

namespace ZATCA\Tags;

use ZATCA\Tag;

class Seller extends Tag
{
    /**
     * @param  string  $value  The registered Arabic trade name.
     * @param  string|null  $fallback  The English trade name from the VAT
     *         certificate, used only when the Arabic name is longer than the
     *         255 bytes ZATCA's length byte allows.
     */
    public function __construct($value, $fallback = null)
    {
        parent::__construct(1, $value, $fallback);
    }
}
