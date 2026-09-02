<?php

$tax_total = <<<XML
<cac:TaxTotal>
        <cbc:TaxAmount currencyID="SAR">SET_TAX_TOTAL_AMOUNT_1</cbc:TaxAmount>SET_TAX_SUBTOTALS
    </cac:TaxTotal>
    <cac:TaxTotal>
        <cbc:TaxAmount currencyID="SAR">SET_TAX_TOTAL_AMOUNT_2</cbc:TaxAmount>
    </cac:TaxTotal>
XML;

$tax_sub_total = <<<XML

        <cac:TaxSubtotal>
            <cbc:TaxableAmount currencyID="SAR">SET_TAXABLE_AMOUNT</cbc:TaxableAmount>
            <cbc:TaxAmount currencyID="SAR">SET_SUBTOTAL_TAX_AMOUNT</cbc:TaxAmount>
            <cac:TaxCategory>
                <cbc:ID schemeAgencyID="6" schemeID="UN/ECE 5305">SET_TAX_CATEGORY_ID</cbc:ID>
                <cbc:Percent>SET_TAX_CATEGORY_PERCENT</cbc:Percent>
                <cac:TaxScheme>
                    <cbc:ID schemeAgencyID="6" schemeID="UN/ECE 5153">VAT</cbc:ID>
                </cac:TaxScheme>
            </cac:TaxCategory>
        </cac:TaxSubtotal>
XML;

return [
    'tax_total' => $tax_total,
    'tax_sub_total' => $tax_sub_total,
];
