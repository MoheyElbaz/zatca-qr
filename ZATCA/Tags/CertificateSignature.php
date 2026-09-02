<?php

namespace ZATCA\Tags;

use ZATCA\Tag;

/**
 * Phase 2 QR tag 9 — the ZATCA CA signature of the cryptographic stamp certificate.
 */
class CertificateSignature extends Tag
{
    public function __construct($value)
    {
        parent::__construct(9, $value);
    }
}
