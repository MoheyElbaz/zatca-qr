<?php

use ZATCA\ZATCASimplifiedTaxInvoice;

/**
 * @return DOMXPath An XPath bound to the invoice with the UBL prefixes registered.
 */
function invoice_xpath(DOMDocument $document): DOMXPath
{
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
    $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

    return $xpath;
}

test('a second invoice built in the same process still has its line items', function () {
    // Regression: the invoice line template used require_once, so every invoice after the
    // first got empty <cac:InvoiceLine> blocks while the totals stayed non zero.
    $builder = new ZATCASimplifiedTaxInvoice();

    foreach ([1, 2, 3] as $round) {
        $document = $builder->simplifiedTaxInvoice(sample_invoice(), sample_egs_unit());
        $xpath = invoice_xpath($document);

        same(1, $xpath->query('//cac:InvoiceLine')->length, "Invoice #{$round} should have one invoice line");
        same('1', $xpath->evaluate('string(//cac:InvoiceLine/cbc:ID)'), "Invoice #{$round} line id");
        same('46.00', $xpath->evaluate('string(//cac:InvoiceLine/cbc:LineExtensionAmount)'), "Invoice #{$round} line extension amount");
        same('TEST NAME', $xpath->evaluate('string(//cac:InvoiceLine/cac:Item/cbc:Name)'), "Invoice #{$round} item name");
    }
});

test('the unit price on the line is the real price, not the template sample', function () {
    $document = (new ZATCASimplifiedTaxInvoice())->simplifiedTaxInvoice(
        sample_invoice([[
            'id' => '1',
            'name' => 'Chair',
            'quantity' => 2,
            'tax_exclusive_price' => 250,
            'VAT_percent' => 0.15,
        ]]),
        sample_egs_unit()
    );

    $xpath = invoice_xpath($document);

    same('250.00', $xpath->evaluate('string(//cac:InvoiceLine/cac:Price/cbc:PriceAmount)'), 'PriceAmount must reflect tax_exclusive_price');
    same('500.00', $xpath->evaluate('string(//cac:InvoiceLine/cbc:LineExtensionAmount)'), 'LineExtensionAmount must stay consistent with the price');
});

test('a line item without discounts or other taxes is accepted', function () {
    $document = (new ZATCASimplifiedTaxInvoice())->simplifiedTaxInvoice(
        sample_invoice([[
            'id' => '1',
            'name' => 'Plain item',
            'quantity' => 2,
            'tax_exclusive_price' => 10,
            'VAT_percent' => 0.15,
        ]]),
        sample_egs_unit()
    );

    $xpath = invoice_xpath($document);

    same('20.00', $xpath->evaluate('string(//cac:InvoiceLine/cbc:LineExtensionAmount)'), 'Line extension amount');
    same('3.00', $xpath->evaluate('string(//cac:InvoiceLine/cac:TaxTotal/cbc:TaxAmount)'), 'Line tax amount');
    same('23.00', $xpath->evaluate('string(//cac:LegalMonetaryTotal/cbc:PayableAmount)'), 'Payable amount');
    same(0, $xpath->query('//cac:InvoiceLine/cac:Price/cac:AllowanceCharge')->length, 'No allowance charges expected');
});

test('a taxable amount equal to a template literal is not overwritten', function () {
    // Regression: the tax subtotal template was filled by replacing its sample numbers in
    // sequence, so a taxable amount of exactly 15.00 was rewritten by the VAT percentage.
    $document = (new ZATCASimplifiedTaxInvoice())->simplifiedTaxInvoice(
        sample_invoice([[
            'id' => '1',
            'name' => 'Item',
            'quantity' => 1,
            'tax_exclusive_price' => 15,
            'VAT_percent' => 0.05,
        ]]),
        sample_egs_unit()
    );

    $xpath = invoice_xpath($document);

    same('15.00', $xpath->evaluate('string(//cac:TaxSubtotal/cbc:TaxableAmount)'), 'The taxable amount must survive');
    same('0.75', $xpath->evaluate('string(//cac:TaxSubtotal/cbc:TaxAmount)'), 'The subtotal tax amount');
    same('5.00', $xpath->evaluate('string(//cac:TaxSubtotal/cac:TaxCategory/cbc:Percent)'), 'The VAT percentage');
});

test('a zero rated line is categorised as O', function () {
    $document = (new ZATCASimplifiedTaxInvoice())->simplifiedTaxInvoice(
        sample_invoice([[
            'id' => '1',
            'name' => 'Exempt item',
            'quantity' => 1,
            'tax_exclusive_price' => 100,
            'VAT_percent' => 0,
        ]]),
        sample_egs_unit()
    );

    $xpath = invoice_xpath($document);

    same('O', $xpath->evaluate('string(//cac:TaxSubtotal/cac:TaxCategory/cbc:ID)'), 'Zero rated lines use category O');
    same('0.00', $xpath->evaluate('string(//cac:TaxTotal/cbc:TaxAmount)'), 'Total VAT is zero');
    same('100.00', $xpath->evaluate('string(//cac:LegalMonetaryTotal/cbc:PayableAmount)'), 'Payable amount equals the net total');
});

test('special characters are escaped instead of breaking the document', function () {
    $document = (new ZATCASimplifiedTaxInvoice())->simplifiedTaxInvoice(
        sample_invoice([[
            'id' => '1',
            'name' => 'Acme & Sons <deluxe>',
            'quantity' => 1,
            'tax_exclusive_price' => 10,
            'VAT_percent' => 0.15,
            'discounts' => [['amount' => 1, 'reason' => 'Ramadan "special" & more']],
        ]]),
        sample_egs_unit(['VAT_name' => 'Qr & Co <KSA>'])
    );

    $xpath = invoice_xpath($document);

    same('Acme & Sons <deluxe>', $xpath->evaluate('string(//cac:InvoiceLine/cac:Item/cbc:Name)'), 'The item name round trips');
    same('Ramadan "special" & more', $xpath->evaluate('string(//cac:AllowanceCharge/cbc:AllowanceChargeReason)'), 'The discount reason round trips');
    same('Qr & Co <KSA>', $xpath->evaluate('string(//cac:PartyLegalEntity/cbc:RegistrationName)'), 'The seller name round trips');
    contains('&amp;', $document->saveXML(), 'The ampersand must be escaped in the serialised XML');
});

test('markup in a value cannot inject elements into the signed invoice', function () {
    $document = (new ZATCASimplifiedTaxInvoice())->simplifiedTaxInvoice(
        sample_invoice([[
            'id' => '1',
            'name' => '</cbc:Name><cbc:Note>injected</cbc:Note><cbc:Name>x',
            'quantity' => 1,
            'tax_exclusive_price' => 10,
            'VAT_percent' => 0.15,
        ]]),
        sample_egs_unit()
    );

    $xpath = invoice_xpath($document);

    same(0, $xpath->query('//cbc:Note')->length, 'No element may be injected through a value');
    same('</cbc:Name><cbc:Note>injected</cbc:Note><cbc:Name>x', $xpath->evaluate('string(//cac:InvoiceLine/cac:Item/cbc:Name)'), 'The value stays text');
});

test('a value that looks like a template placeholder is left alone', function () {
    $document = (new ZATCASimplifiedTaxInvoice())->simplifiedTaxInvoice(
        sample_invoice(null, ['invoice_serial_number' => 'SET_VAT_NAME-1']),
        sample_egs_unit()
    );

    $xpath = invoice_xpath($document);

    same('SET_VAT_NAME-1', $xpath->evaluate('string(/ubl:Invoice/cbc:ID)'), 'A placeholder-like value must not be re-substituted');
});

test('each invoice carries its own UUID', function () {
    $builder = new ZATCASimplifiedTaxInvoice();
    $egs_unit = sample_egs_unit();

    $with_uuid = $builder->simplifiedTaxInvoice(sample_invoice(null, ['uuid' => '11111111-2222-4333-8444-555555555555']), $egs_unit);
    same('11111111-2222-4333-8444-555555555555', invoice_xpath($with_uuid)->evaluate('string(/ubl:Invoice/cbc:UUID)'), 'The invoice UUID is used when given');

    $without_uuid = $builder->simplifiedTaxInvoice(sample_invoice(), $egs_unit);
    same($egs_unit['uuid'], invoice_xpath($without_uuid)->evaluate('string(/ubl:Invoice/cbc:UUID)'), 'It falls back to the EGS unit UUID');
});

test('a cancelation adds the billing reference', function () {
    $document = (new ZATCASimplifiedTaxInvoice())->simplifiedTaxInvoice(
        sample_invoice(),
        sample_egs_unit(['cancelation' => ['cancelation_type' => 'CREDIT_NOTE', 'canceled_invoice_number' => 'EGS1-1 & 2']])
    );

    $xpath = invoice_xpath($document);

    same('381', $xpath->evaluate('string(/ubl:Invoice/cbc:InvoiceTypeCode)'), 'A credit note is type 381');
    same('Invoice Number: EGS1-1 & 2', $xpath->evaluate('string(//cac:BillingReference//cbc:ID)'), 'The canceled invoice number is referenced and escaped');
});

test('missing or unusable input is reported clearly', function () {
    $builder = new ZATCASimplifiedTaxInvoice();

    $thrown = throws(InvalidArgumentException::class, function () use ($builder) {
        $builder->simplifiedTaxInvoice(['invoice_serial_number' => 'x'], sample_egs_unit());
    }, 'A half filled invoice must be rejected');
    contains('issue_date', $thrown->getMessage(), 'The exception should name the missing keys');

    throws(InvalidArgumentException::class, function () use ($builder) {
        $builder->simplifiedTaxInvoice(sample_invoice(), sample_egs_unit(['cancelation' => ['cancelation_type' => 'NOPE']]));
    }, 'An unknown invoice type must be rejected');

    throws(InvalidArgumentException::class, function () use ($builder) {
        $builder->simplifiedTaxInvoice(sample_invoice([]), sample_egs_unit());
    }, 'An invoice without line items must be rejected');
});

test('the invoice hash excludes the extensions, signature and QR reference', function () {
    $builder = new ZATCASimplifiedTaxInvoice();
    $document = $builder->simplifiedTaxInvoice(sample_invoice(), sample_egs_unit());

    $hash = $builder->getInvoiceHash($document);

    same(32, strlen(base64_decode($hash, true)), 'The hash is a 32 byte SHA-256 digest');
    // Hashing must not mutate the document it was handed.
    contains('SET_QR_CODE_DATA', $document->saveXML(), 'The source document keeps its QR placeholder');
    same($hash, $builder->getInvoiceHash($document), 'Hashing is deterministic');
});
