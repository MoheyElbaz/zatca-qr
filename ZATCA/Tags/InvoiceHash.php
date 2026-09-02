<?php

namespace ZATCA\Tags;

use ZATCA\Tag;

/**
 * Phase 2 QR tag 6 — the hash of the signed XML invoice.
 */
class InvoiceHash extends Tag
{
    public function __construct($value)
    {
        parent::__construct(6, $value);
    }
}
