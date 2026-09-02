<?php

namespace ZATCA\Tags;

use ZATCA\Tag;

/**
 * Phase 2 QR tag 7 — the ECDSA signature of the invoice hash.
 */
class DigitalSignature extends Tag
{
    public function __construct($value)
    {
        parent::__construct(7, $value);
    }
}
