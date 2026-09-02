<?php

namespace ZATCA\Tags;

use ZATCA\Tag;

/**
 * Phase 2 QR tag 8 — the public key of the cryptographic stamp certificate.
 */
class PublicKey extends Tag
{
    public function __construct($value)
    {
        parent::__construct(8, $value);
    }
}
