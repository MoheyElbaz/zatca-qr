<?php

namespace ZATCA;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use RuntimeException;
use ZATCA\Tags\CertificateSignature;
use ZATCA\Tags\DigitalSignature;
use ZATCA\Tags\InvoiceDate;
use ZATCA\Tags\InvoiceHash;
use ZATCA\Tags\InvoiceTaxAmount;
use ZATCA\Tags\InvoiceTotalAmount;
use ZATCA\Tags\PublicKey;
use ZATCA\Tags\Seller;
use ZATCA\Tags\TaxNumber;

class ZATCASimplifiedTaxInvoice
{
    private $ZATCAInvoiceTypes = [
        'INVOICE' => 388,
        'DEBIT_NOTE' => 383,
        'CREDIT_NOTE' => 381,
    ];

    public function __construct()
    {
    }

    /**
     * Absolute path of a template shipped with this package. Resolved from this file so
     * that the library keeps working when installed into a `vendor/` directory.
     */
    private static function template(string $name): string
    {
        return __DIR__ . '/templates/' . $name;
    }

    /**
     * Every caller supplied value ends up inside an XML document that is then
     * cryptographically stamped, so it has to be escaped: an unescaped `&` breaks the
     * document, and unescaped markup would be smuggled into the signed invoice.
     */
    private static function escape($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function assertKeys(array $source, array $keys, string $label): void
    {
        $missing = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                $missing[] = $key;
            }
        }

        if ($missing) {
            throw new InvalidArgumentException(sprintf(
                '%s is missing the required key(s): %s.',
                $label,
                implode(', ', $missing)
            ));
        }
    }

    private static function opensslErrors(): string
    {
        $errors = [];
        while ($error = openssl_error_string()) {
            $errors[] = $error;
        }

        return $errors ? implode('; ', $errors) : 'no OpenSSL error reported';
    }

    public function simplifiedTaxInvoice(array $invoice, array $egs_unit): DOMDocument
    {
        self::assertKeys($invoice, [
            'invoice_serial_number', 'issue_date', 'issue_time',
            'previous_invoice_hash', 'invoice_counter_number', 'line_items',
        ], 'The invoice');
        self::assertKeys($egs_unit, [
            'uuid', 'CRN_number', 'VAT_number', 'VAT_name', 'location',
        ], 'The EGS unit');
        self::assertKeys($egs_unit['location'], [
            'street', 'building', 'plot_identification', 'city_subdivision', 'city', 'postal_zone',
        ], 'The EGS unit location');

        $cancelation = $egs_unit['cancelation'] ?? [];
        $invoice_type = $cancelation['cancelation_type'] ?? 'INVOICE';

        if (!isset($this->ZATCAInvoiceTypes[$invoice_type])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown invoice type "%s". Expected one of: %s.',
                (string) $invoice_type,
                implode(', ', array_keys($this->ZATCAInvoiceTypes))
            ));
        }

        // if canceled (BR-KSA-56) set reference number to canceled invoice
        $billing_reference = '';
        if (!empty($cancelation['canceled_invoice_number'])) {
            $billing_reference = $this->defaultBillingReference($cancelation['canceled_invoice_number']);
        }

        $populated_template = trim(require self::template('simplified_tax_invoice_template.php'));

        // strtr() replaces each placeholder exactly once and never re-scans what it just
        // inserted, so a value that happens to contain a placeholder name is left alone.
        $populated_template = strtr($populated_template, [
            'SET_INVOICE_TYPE' => (string) $this->ZATCAInvoiceTypes[$invoice_type],
            'SET_BILLING_REFERENCE' => $billing_reference,
            'SET_INVOICE_SERIAL_NUMBER' => self::escape($invoice['invoice_serial_number']),
            // KSA-1: every invoice carries its own UUID, not the terminal's.
            'SET_INVOICE_UUID' => self::escape($invoice['uuid'] ?? $egs_unit['uuid']),
            'SET_ISSUE_DATE' => self::escape($invoice['issue_date']),
            'SET_ISSUE_TIME' => self::escape($invoice['issue_time']),
            'SET_PREVIOUS_INVOICE_HASH' => self::escape($invoice['previous_invoice_hash']),
            'SET_INVOICE_COUNTER_NUMBER' => self::escape($invoice['invoice_counter_number']),
            'SET_COMMERCIAL_REGISTRATION_NUMBER' => self::escape($egs_unit['CRN_number']),
            'SET_STREET_NAME' => self::escape($egs_unit['location']['street']),
            'SET_BUILDING_NUMBER' => self::escape($egs_unit['location']['building']),
            'SET_PLOT_IDENTIFICATION' => self::escape($egs_unit['location']['plot_identification']),
            'SET_CITY_SUBDIVISION' => self::escape($egs_unit['location']['city_subdivision']),
            'SET_CITY' => self::escape($egs_unit['location']['city']),
            'SET_POSTAL_NUMBER' => self::escape($egs_unit['location']['postal_zone']),
            'SET_VAT_NUMBER' => self::escape($egs_unit['VAT_number']),
            'SET_VAT_NAME' => self::escape($egs_unit['VAT_name']),
            'PARSE_LINE_ITEMS' => $this->parseLineItems($invoice['line_items']),
        ]);

        return $this->loadXML($populated_template, 'invoice');
    }

    /**
     * @throws RuntimeException If the populated template is not well formed XML.
     */
    private function loadXML(string $xml, string $what): DOMDocument
    {
        $previous_state = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument();
        $loaded = $document->loadXML($xml);

        $errors = array_map(function ($error) {
            return trim($error->message) . ' (line ' . $error->line . ')';
        }, libxml_get_errors());

        libxml_clear_errors();
        libxml_use_internal_errors($previous_state);

        if (!$loaded) {
            throw new RuntimeException(sprintf(
                'The generated %s XML is not well formed: %s',
                $what,
                $errors ? implode('; ', $errors) : 'unknown parse error'
            ));
        }

        return $document;
    }

    private function defaultBillingReference(string $invoice_number): string
    {
        $populated_template = require self::template('invoice_billing_reference_template.php');

        return strtr($populated_template, ['SET_INVOICE_NUMBER' => self::escape($invoice_number)]);
    }

    public function getInvoiceHash(DOMDocument $invoice_xml): string
    {
        $pure_invoice_string = $this->getPureInvoiceString($invoice_xml);

        $pure_invoice_string = str_replace('<?xml version="1.0" encoding="UTF-8"?>' . "\n", '', $pure_invoice_string);
        $pure_invoice_string = str_replace('<cac:AccountingCustomerParty/>', '<cac:AccountingCustomerParty></cac:AccountingCustomerParty>', $pure_invoice_string);

        $hash = hash('sha256', trim($pure_invoice_string));
        $hash = pack('H*', $hash);

        return base64_encode($hash);
    }

    private function getPureInvoiceString(DOMDocument $invoice_xml)
    {
        $document = new DOMDocument();
        $document->loadXML($invoice_xml->saveXML());

        while ($element = $document->getElementsByTagName('UBLExtensions')->item(0))
            $element->parentNode->removeChild($element);

        while ($element = $document->getElementsByTagName('Signature')->item(0))
            $element->parentNode->removeChild($element);

        // The QR reference is excluded from the signed data (ds:Transform XPath
        // not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])).
        // Match it by its ID rather than by position, so an added reference cannot
        // silently make us strip the wrong element.
        foreach (iterator_to_array($document->getElementsByTagName('AdditionalDocumentReference')) as $reference) {
            /** @var DOMElement $reference */
            $id = $reference->getElementsByTagName('ID')->item(0);
            if ($id && trim($id->textContent) === 'QR') {
                $reference->parentNode->removeChild($reference);
            }
        }

        return $document->saveXML();
    }

    public function getCertificateInfo(string $certificate_string): array
    {
        $cleaned_certificate_string = $this->cleanUpCertificateString($certificate_string);
        $wrapped_certificate_string = "-----BEGIN CERTIFICATE-----\n{$cleaned_certificate_string}\n-----END CERTIFICATE-----";

        $hash = $this->getCertificateHash($cleaned_certificate_string);

        $x509 = openssl_x509_parse($wrapped_certificate_string);
        if ($x509 === false) {
            throw new RuntimeException('Unable to parse the cryptographic stamp certificate: ' . self::opensslErrors());
        }

        // Signature, and public key extraction from x509 PEM certificate (asn1 rfc5280)
        // https://linuxctl.com/2017/02/x509-certificate-manual-signature-verification/

        $res = openssl_get_publickey($wrapped_certificate_string);
        if ($res === false) {
            throw new RuntimeException('Unable to read the certificate public key: ' . self::opensslErrors());
        }

        $cert = openssl_pkey_get_details($res);
        $public_key = str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----'], '', $cert['key']);

        return [
            $hash,
            $this->formatDistinguishedName($x509['issuer'] ?? []),
            $x509['serialNumber'],
            base64_decode($public_key),
            $this->getCertificateSignature($wrapped_certificate_string),
        ];
    }

    /**
     * Rebuild an RFC 2253 style distinguished name ("CN=…, OU=…, O=…, C=SA") from the
     * relative distinguished names returned by openssl_x509_parse(). The attribute keys
     * have to be kept: the result goes into <ds:X509IssuerName>, which ZATCA compares
     * against the real issuer of the certificate.
     */
    private function formatDistinguishedName(array $relative_names): string
    {
        $parts = [];

        foreach (array_reverse($relative_names, true) as $key => $value) {
            foreach (array_reverse((array) $value) as $single) {
                $parts[] = $key . '=' . $single;
            }
        }

        return implode(', ', $parts);
    }

    public function getCertificateSignature(string $cer): string
    {
        $x509 = openssl_x509_read($cer);
        if ($x509 === false) {
            throw new RuntimeException('Unable to read the cryptographic stamp certificate: ' . self::opensslErrors());
        }

        if (!openssl_x509_export($x509, $out, false)) {
            throw new RuntimeException('Unable to export the certificate: ' . self::opensslErrors());
        }

        // Everything before the PEM block is OpenSSL's human readable dump; the very last
        // run of colon separated hex bytes in it is the certificate signature. Reading it
        // backwards keeps this working regardless of how many lines the signature spans or
        // how the surrounding labels are worded across OpenSSL versions.
        $text = explode('-----BEGIN CERTIFICATE-----', $out)[0];

        $hex = '';
        foreach (array_reverse(explode("\n", $text)) as $line) {
            $line = trim($line);

            if (preg_match('/^(?:[0-9a-fA-F]{2}:)*[0-9a-fA-F]{2}:?$/', $line) === 1) {
                $hex = str_replace(':', '', $line) . $hex;
                continue;
            }

            if ($hex !== '') {
                break;
            }
        }

        if ($hex === '') {
            throw new RuntimeException('No signature found in the certificate dump.');
        }

        return pack('H*', $hex);
    }

    /**
     * ZATCA expects the base64 of the *hex* digest here, which is why the digest is not
     * converted to raw bytes first.
     */
    private function getCertificateHash($cleanup_certificate_string): string
    {
        $hash = openssl_digest($cleanup_certificate_string, 'sha256');

        return base64_encode($hash);
    }

    public static function cleanUpCertificateString(string $certificate_string): string
    {
        $certificate_string = str_replace('-----BEGIN CERTIFICATE-----', '', $certificate_string);
        $certificate_string = str_replace('-----END CERTIFICATE-----', '', $certificate_string);

        return trim($certificate_string);
    }

    /**
     * The cryptographic stamp is an ECDSA signature over the *bytes* of the invoice hash,
     * so the base64 hash has to be decoded first.
     */
    public function createInvoiceDigitalSignature(string $invoice_hash, string $private_key): string
    {
        $invoice_hash_bytes = base64_decode($invoice_hash, true);
        if ($invoice_hash_bytes === false) {
            throw new InvalidArgumentException('The invoice hash is not valid base64.');
        }

        $key = openssl_pkey_get_private(self::wrapPrivateKeyString($private_key));
        if ($key === false) {
            throw new RuntimeException('Unable to read the EGS private key: ' . self::opensslErrors());
        }

        if (!openssl_sign($invoice_hash_bytes, $binary_signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the invoice hash: ' . self::opensslErrors());
        }

        return base64_encode($binary_signature);
    }

    /**
     * Accepts a PEM key as generated by this library (SEC1 "EC PRIVATE KEY"), a bare
     * base64 body, or any other PEM the caller already holds (e.g. PKCS#8).
     */
    public static function wrapPrivateKeyString(string $private_key): string
    {
        $private_key = trim($private_key);

        if (str_contains($private_key, '-----BEGIN')) {
            return $private_key;
        }

        return "-----BEGIN EC PRIVATE KEY-----\n{$private_key}\n-----END EC PRIVATE KEY-----";
    }

    public static function cleanUpPrivateKeyString(string $private_key)
    {
        $private_key = str_replace('-----BEGIN EC PRIVATE KEY-----', '', $private_key);
        $private_key = str_replace('-----END EC PRIVATE KEY-----', '', $private_key);

        return trim($private_key);
    }

    public function generateQR(DOMDocument $invoice_xml, string $digital_signature, $public_key, $signature, string $invoice_hash): string
    {
        // Extract required tags
        $seller_name = $invoice_xml->getElementsByTagName('AccountingSupplierParty')[0]
            ->getElementsByTagName('RegistrationName')[0]->textContent;

        $VAT_number = $invoice_xml->getElementsByTagName('CompanyID')[0]->textContent;

        $invoice_total = $invoice_xml->getElementsByTagName('TaxInclusiveAmount')[0]->textContent;

        $VAT_total = '0';
        if ($tax_amount = $invoice_xml->getElementsByTagName('TaxTotal')[0]) {
            $VAT_total = $tax_amount->getElementsByTagName('TaxAmount')[0]->textContent;
        }

        $issue_date = trim($invoice_xml->getElementsByTagName('IssueDate')[0]->textContent);
        $issue_time = trim($invoice_xml->getElementsByTagName('IssueTime')[0]->textContent);

        // The QR timestamp must mirror the invoice's own IssueDate/IssueTime, so the two
        // are concatenated rather than round tripped through the server's timezone.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date) || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $issue_time)) {
            throw new InvalidArgumentException(sprintf(
                'Expected issue_date as YYYY-MM-DD and issue_time as HH:MM:SS, got "%s" and "%s".',
                $issue_date,
                $issue_time
            ));
        }

        $formatted_datetime = "{$issue_date}T{$issue_time}Z";

        return GenerateQrCode::fromArray([
            new Seller($seller_name),
            new TaxNumber($VAT_number),
            new InvoiceDate($formatted_datetime),
            new InvoiceTotalAmount($invoice_total),
            new InvoiceTaxAmount($VAT_total),
            new InvoiceHash($invoice_hash),
            new DigitalSignature($digital_signature),
            new PublicKey($public_key),
            new CertificateSignature($signature),
        ])->toBase64();
    }

    public function defaultUBLExtensionsSignedPropertiesForSigning(array $signed_properties_props): string
    {
        return $this->populateSignedProperties(
            require self::template('ubl_signature_signed_properties_for_signing_template.php'),
            $signed_properties_props
        );
    }

    public function defaultUBLExtensionsSignedProperties(array $signed_properties_props): string
    {
        return $this->populateSignedProperties(
            require self::template('ubl_signature_signed_properties_template.php'),
            $signed_properties_props
        );
    }

    private function populateSignedProperties(string $template, array $signed_properties_props): string
    {
        return strtr($template, [
            'SET_SIGN_TIMESTAMP' => self::escape($signed_properties_props['sign_timestamp']),
            'SET_CERTIFICATE_HASH' => self::escape($signed_properties_props['certificate_hash']),
            'SET_CERTIFICATE_ISSUER' => self::escape($signed_properties_props['certificate_issuer']),
            'SET_CERTIFICATE_SERIAL_NUMBER' => self::escape($signed_properties_props['certificate_serial_number']),
        ]);
    }

    public function defaultUBLExtensions(string $invoice_hash, string $signed_properties_hash, string $digital_signature, string $cleanUpCertificateString, string $ubl_signature_signed_properties_xml_string): string
    {
        $populated_template = require self::template('ubl_signature.php');

        return strtr($populated_template, [
            'SET_INVOICE_HASH' => $invoice_hash,
            'SET_SIGNED_PROPERTIES_HASH' => $signed_properties_hash,
            'SET_DIGITAL_SIGNATURE' => $digital_signature,
            'SET_CERTIFICATE' => $this->cleanUpCertificateString($cleanUpCertificateString),
            'SET_SIGNED_PROPERTIES_XML' => $ubl_signature_signed_properties_xml_string,
        ]);
    }

    private function parseLineItems(array $line_items): string
    {
        if ($line_items === []) {
            throw new InvalidArgumentException('The invoice has no line items.');
        }

        // BT-110
        $total_taxes = 0;
        $total_subtotal = 0;

        $invoice_line_items = [];

        foreach ($line_items as $line_item) {
            list($line_item_xml, $line_item_totals) = $this->constructLineItem($line_item);

            $total_taxes += $line_item_totals['taxes_total'];
            $total_subtotal += (float) $line_item_totals['subtotal'];

            $invoice_line_items[] = $line_item_xml;
        }

        /*
         * <cac:TaxTotal>
         *      </cac:TaxSubtotal> ...
         * set invoice lines
         */
        $tax_total_template = require self::template('tax_total_template.php');

        $item_lines = $this->constructTaxTotal($line_items);

        $lines = '';
        foreach ($item_lines[0]['cac:TaxSubtotal'] as $line) {
            $lines .= strtr($tax_total_template['tax_sub_total'], [
                'SET_TAXABLE_AMOUNT' => $line['cbc:TaxableAmount']['#text'],
                'SET_SUBTOTAL_TAX_AMOUNT' => $line['cbc:TaxAmount']['#text'],
                'SET_TAX_CATEGORY_ID' => $line['cac:TaxCategory']['cbc:ID']['#text'],
                'SET_TAX_CATEGORY_PERCENT' => $line['cac:TaxCategory']['cbc:Percent'],
            ]);
        }

        $tax_total = strtr($tax_total_template['tax_total'], [
            'SET_TAX_TOTAL_AMOUNT_1' => $item_lines[0]['cbc:TaxAmount']['#text'],
            'SET_TAX_TOTAL_AMOUNT_2' => $item_lines[1]['cbc:TaxAmount']['#text'],
            'SET_TAX_SUBTOTALS' => $lines,
        ]);

        /*
         * <cac:LegalMonetaryTotal>
         * $legal_monetary_total_template tags set
         */
        $legal_monetary_total_template = require self::template('legal_monetary_total_template.php');

        $constructLegalMonetaryTotal = $this->constructLegalMonetaryTotal($total_subtotal, $total_taxes);

        $legal_monetary_total = strtr($legal_monetary_total_template, [
            '_LineExtensionAmount' => $constructLegalMonetaryTotal['cbc:LineExtensionAmount']['#text'],
            '_TaxExclusiveAmount' => $constructLegalMonetaryTotal['cbc:TaxExclusiveAmount']['#text'],
            '_TaxInclusiveAmount' => $constructLegalMonetaryTotal['cbc:TaxInclusiveAmount']['#text'],
            '_AllowanceTotalAmount' => $constructLegalMonetaryTotal['cbc:AllowanceTotalAmount']['#text'],
            '_PrepaidAmount' => $constructLegalMonetaryTotal['cbc:PrepaidAmount']['#text'],
            '_PayableAmount' => $constructLegalMonetaryTotal['cbc:PayableAmount']['#text'],
        ]);

        /*
         * <cac:InvoiceLine> ...
         * set invoice lines
         */
        $invoice_line_template = require self::template('invoice_line_template.php');

        $invoice_line = '';
        foreach ($invoice_line_items as $item) {

            $classified_tax_categories = '';
            foreach ($item['cac:Item']['cac:ClassifiedTaxCategory'] as $ClassifiedTaxCategory) {
                $classified_tax_categories .= strtr($invoice_line_template['invoice_item'], [
                    'SET_ITEM_TAX_CATEGORY_ID' => $ClassifiedTaxCategory['cbc:ID'],
                    'SET_ITEM_TAX_PERCENT' => $ClassifiedTaxCategory['cbc:Percent'],
                ]);
            }

            $allowance_charges = '';
            foreach ($item['cac:Price']['cac:AllowanceCharge'] as $AllowanceCharge) {
                $allowance_charges .= strtr($invoice_line_template['invoice_price'], [
                    'SET_ALLOWANCE_REASON' => self::escape($AllowanceCharge['cbc:AllowanceChargeReason']),
                    'SET_ALLOWANCE_AMOUNT' => $AllowanceCharge['cbc:Amount']['#text'],
                ]);
            }

            $invoice_line .= strtr($invoice_line_template['invoice_line'], [
                'SET_LINE_ID' => self::escape($item['cbc:ID']),
                'SET_LINE_QUANTITY' => self::escape($item['cbc:InvoicedQuantity']['#text']),
                'SET_LINE_EXTENSION_AMOUNT' => $item['cbc:LineExtensionAmount']['#text'],
                'SET_LINE_TAX_AMOUNT' => $item['cac:TaxTotal']['cbc:TaxAmount']['#text'],
                'SET_LINE_ROUNDING_AMOUNT' => $item['cac:TaxTotal']['cbc:RoundingAmount']['#text'],
                'SET_LINE_ITEM_NAME' => self::escape($item['cac:Item']['cbc:Name']),
                'SET_LINE_PRICE_AMOUNT' => $item['cac:Price']['cbc:PriceAmount']['#text'],
                'SET_CLASSIFIED_TAX_CATEGORIES' => $classified_tax_categories,
                'SET_ALLOWANCE_CHARGES' => $allowance_charges,
            ]);
        }

        return $tax_total . $legal_monetary_total . $invoice_line;
    }

    private function constructLineItem($line_item): array
    {
        self::assertKeys($line_item, ['id', 'name', 'quantity', 'tax_exclusive_price', 'VAT_percent'], 'A line item');

        [
            $cacAllowanceCharges,
            $cacClassifiedTaxCategories, $cacTaxTotal,
            $line_item_total_tax_exclusive,
            $line_item_total_taxes,
            $line_item_total_discounts
        ] = $this->constructLineItemTotals($line_item);

        return [
            /*'line_item_xml' => */ [
                'cbc:ID' => $line_item['id'],
                'cbc:InvoicedQuantity' => [
                    '@_unitCode' => 'PCE',
                    '#text' => $line_item['quantity']
                ],
                // BR-DEC-23
                'cbc:LineExtensionAmount' => [
                    '@_currencyID' => 'SAR',
                    '#text' => number_format($line_item_total_tax_exclusive, 2, '.', '')
                ],
                'cac:TaxTotal' => $cacTaxTotal,
                'cac:Item' => [
                    'cbc:Name' => $line_item['name'],
                    'cac:ClassifiedTaxCategory' => $cacClassifiedTaxCategories
                ],
                'cac:Price' => [
                    'cbc:PriceAmount' => [
                        '@_currencyID' => 'SAR',
                        '#text' => number_format((float) $line_item['tax_exclusive_price'], 2, '.', '')
                    ],
                    'cac:AllowanceCharge' => $cacAllowanceCharges
                ]
            ],
            /*'line_item_totals' => */ [
                'taxes_total' => $line_item_total_taxes,
                'discounts_total' => $line_item_total_discounts,
                'subtotal' => $line_item_total_tax_exclusive
            ]
        ];
    }

    private function constructLineItemTotals($line_item): array
    {
        $line_item_total_discounts = 0;
        $line_item_total_taxes = 0;

        $cacAllowanceCharges = [];

        // VAT
        // BR-KSA-DEC-02
        $VAT = [
            'cbc:ID' => $line_item['VAT_percent'] ? 'S' : 'O',
            // BT-120, KSA-121
            'cbc:Percent' => number_format($line_item['VAT_percent'] ? ($line_item['VAT_percent'] * 100) : 0, 2, '.', ''),
            'cac:TaxScheme' => [
                'cbc:ID' => 'VAT'
            ],
        ];
        $cacClassifiedTaxCategories[] = $VAT;

        // Calc total discounts
        foreach ($line_item['discounts'] ?? [] as $discount) {
            $line_item_total_discounts += $discount['amount'];
            $cacAllowanceCharges[] = [
                'cbc:ChargeIndicator' => 'false',
                'cbc:AllowanceChargeReason' => $discount['reason'],
                'cbc:Amount' => [
                    '@_currencyID' => 'SAR',
                    // BR-DEC-01
                    '#text' => number_format($discount['amount'], 2, '.', '')
                ]
            ];
        }

        // Calc item subtotal
        $line_item_subtotal = ($line_item['tax_exclusive_price'] * $line_item['quantity']) - $line_item_total_discounts;

        // Calc total taxes
        // BR-KSA-DEC-02
        $line_item_total_taxes = $line_item_total_taxes + ($line_item_subtotal * $line_item['VAT_percent']);

        foreach ($line_item['other_taxes'] ?? [] as $tax) {
            $line_item_total_taxes = $line_item_total_taxes + (floatval($tax['percent_amount']) * $line_item_subtotal);

            $cacClassifiedTaxCategories[] = [
                'cbc:ID' => 'S',
                'cbc:Percent' => number_format($tax['percent_amount'] * 100, 2, '.', ''),
                'cac:TaxScheme' => [
                    'cbc:ID' => 'VAT'
                ]
            ];
        }

        // BR-KSA-DEC-03, BR-KSA-51
        $cacTaxTotal = [
            'cbc:TaxAmount' => [
                '@_currencyID' => 'SAR',
                '#text' => number_format($line_item_total_taxes, 2, '.', '')
            ],
            'cbc:RoundingAmount' => [
                '@_currencyID' => 'SAR',
                '#text' => number_format($line_item_subtotal + $line_item_total_taxes, 2, '.', '')
            ]
        ];


        return [
            $cacAllowanceCharges,
            $cacClassifiedTaxCategories, $cacTaxTotal,
            $line_item_subtotal,
            $line_item_total_taxes,
            $line_item_total_discounts
        ];
    }

    private function constructLegalMonetaryTotal(float $total_subtotal, float $total_taxes)
    {
        return [
            // BR-DEC-09
            'cbc:LineExtensionAmount' => [
                '@_currencyID' => 'SAR',
                '#text' => number_format($total_subtotal, 2, '.', '')
            ],
            // BR-DEC-12
            'cbc:TaxExclusiveAmount' => [
                '@_currencyID' => 'SAR',
                '#text' => number_format($total_subtotal, 2, '.', '')
            ],
            // BR-DEC-14, BT-112
            'cbc:TaxInclusiveAmount' => [
                '@_currencyID' => 'SAR',
                '#text' => number_format($total_subtotal + $total_taxes, 2, '.', '')
            ],
            'cbc:AllowanceTotalAmount' => [
                '@_currencyID' => 'SAR',
                '#text' => number_format(0, 2, '.', '')
            ],
            'cbc:PrepaidAmount' => [
                '@_currencyID' => 'SAR',
                '#text' => number_format(0, 2, '.', '')
            ],
            // BR-DEC-18, BT-112
            'cbc:PayableAmount' => [
                '@_currencyID' => 'SAR',
                '#text' => number_format($total_subtotal + $total_taxes, 2, '.', '')
            ]
        ];
    }

    private function constructTaxTotal(array $line_items)
    {
        $cacTaxSubtotal = [];
        // BR-DEC-13, MESSAGE : [BR-DEC-13]-The allowed maximum number of decimals for the Invoice total VAT amount (BT-110) is 2.
        $addTaxSubtotal = function ($taxable_amount, $tax_amount, $tax_percent) use (&$cacTaxSubtotal) {
            $cacTaxSubtotal[] = [
                // BR-DEC-19
                'cbc:TaxableAmount' => [
                    '@_currencyID' => 'SAR',
                    '#text' => number_format((float)($taxable_amount), 2, '.', '')
                ],
                'cbc:TaxAmount' => [
                    '@_currencyID' => 'SAR',
                    '#text' => number_format((float)($tax_amount), 2, '.', '')
                ],
                'cac:TaxCategory' => [
                    'cbc:ID' => [
                        '@_schemeAgencyID' => 6,
                        '@_schemeID' => 'UN/ECE 5305',
                        '#text' => $tax_percent ? 'S' : 'O'
                    ],
                    'cbc:Percent' => number_format((float)$tax_percent * 100.00, 2, '.', ''),
                    // BR-O-10
                    'cbc:TaxExemptionReason' => $tax_percent ? '' : 'Not subject to VAT',
                    'cac:TaxScheme' => [
                        'cbc:ID' => [
                            '@_schemeAgencyID' => 6,
                            '@_schemeID' => 'UN/ECE 5153',
                            '#text' => 'VAT'
                        ]
                    ],
                ]
            ];
        };

        $taxes_total = 0;
        foreach ($line_items as $line_item) {
            $total_line_item_discount = array_reduce($line_item['discounts'] ?? [], function ($p, $c) {
                return $p + $c['amount'];
            }, 0);

            $taxable_amount = ($line_item['tax_exclusive_price'] * $line_item['quantity']) - $total_line_item_discount;

            $tax_amount = ((float)$line_item['VAT_percent']) * ((float)$taxable_amount);
            $addTaxSubtotal($taxable_amount, $tax_amount, $line_item['VAT_percent']);
            $taxes_total += $tax_amount;

            foreach ($line_item['other_taxes'] ?? [] as $tax) {
                $other_tax_amount = $tax['percent_amount'] * $taxable_amount;
                $addTaxSubtotal($taxable_amount, $other_tax_amount, $tax['percent_amount']);
                $taxes_total += $other_tax_amount;
            }
        }

        // BT-110
        $taxes_total = number_format($taxes_total, 2, '.', '');

        // BR-DEC-13, MESSAGE : [BR-DEC-13]-The allowed maximum number of decimals for the Invoice total VAT amount (BT-110) is 2.
        return [
            [
                // Total tax amount for the full invoice
                'cbc:TaxAmount' => [
                    '@_currencyID' => 'SAR',
                    '#text' => $taxes_total
                ],
                'cac:TaxSubtotal' => $cacTaxSubtotal,
            ],
            [
                // KSA Rule for VAT tax
                'cbc:TaxAmount' => [
                    '@_currencyID' => 'SAR',
                    '#text' => $taxes_total
                ]
            ]
        ];
    }
}
