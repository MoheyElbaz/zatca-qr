<?php

$invoice_line = <<<XML

    <cac:InvoiceLine>
        <cbc:ID>SET_LINE_ID</cbc:ID>
        <cbc:InvoicedQuantity unitCode="PCE">SET_LINE_QUANTITY</cbc:InvoicedQuantity>
        <cbc:LineExtensionAmount currencyID="SAR">SET_LINE_EXTENSION_AMOUNT</cbc:LineExtensionAmount>
        <cac:TaxTotal>
            <cbc:TaxAmount currencyID="SAR">SET_LINE_TAX_AMOUNT</cbc:TaxAmount>
            <cbc:RoundingAmount currencyID="SAR">SET_LINE_ROUNDING_AMOUNT</cbc:RoundingAmount>
        </cac:TaxTotal>
        <cac:Item>
            <cbc:Name>SET_LINE_ITEM_NAME</cbc:Name>SET_CLASSIFIED_TAX_CATEGORIES
        </cac:Item>
        <cac:Price>
            <cbc:PriceAmount currencyID="SAR">SET_LINE_PRICE_AMOUNT</cbc:PriceAmount>SET_ALLOWANCE_CHARGES
        </cac:Price>
    </cac:InvoiceLine>
XML;

$invoice_item = <<<XML

            <cac:ClassifiedTaxCategory>
                <cbc:ID>SET_ITEM_TAX_CATEGORY_ID</cbc:ID>
                <cbc:Percent>SET_ITEM_TAX_PERCENT</cbc:Percent>
                <cac:TaxScheme>
                    <cbc:ID>VAT</cbc:ID>
                </cac:TaxScheme>
            </cac:ClassifiedTaxCategory>
XML;

$invoice_price = <<<XML

            <cac:AllowanceCharge>
                <cbc:ChargeIndicator>false</cbc:ChargeIndicator>
                <cbc:AllowanceChargeReason>SET_ALLOWANCE_REASON</cbc:AllowanceChargeReason>
                <cbc:Amount currencyID="SAR">SET_ALLOWANCE_AMOUNT</cbc:Amount>
            </cac:AllowanceCharge>
XML;


return [
    'invoice_line' => $invoice_line,
    'invoice_item' => $invoice_item,
    'invoice_price' => $invoice_price,
];
