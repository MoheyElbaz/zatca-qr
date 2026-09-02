<?php

/**
 * Phase 2 example: onboard an EGS unit, sign a simplified tax invoice and render its QR.
 *
 * This talks to the ZATCA gateway, so it needs a real OTP from the Fatoora portal and
 * only ever runs against the sandbox/simulation environment here. Run it from the CLI
 * (`php phase-2.php`) or serve it over HTTP. Requires `composer install`.
 */

require __DIR__ . '/vendor/autoload.php';

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use ZATCA\EGS;

$line_item = [
    'id' => '1',
    'name' => 'TEST NAME',
    'quantity' => 5,
    'tax_exclusive_price' => 10,
    'VAT_percent' => 0.15,
    'other_taxes' => [
        ['percent_amount' => 1]
    ],
    'discounts' => [
        ['amount' => 2, 'reason' => 'A discount'],
        ['amount' => 2, 'reason' => 'A second discount'],
    ],
];

$egs_unit = [
    'uuid' => '6f4d20e0-6bfe-4a80-9389-7dabe6620f12',
    'custom_id' => 'EGS1-886431145',
    'model' => 'IOS',
    'CRN_number' => '454634645645654',
    'VAT_name' => 'Qr',
    'VAT_number' => '301121971500003',
    'location' => [
        'city' => 'Khobar',
        'city_subdivision' => 'West',
        'street' => 'King Fahahd st',
        'plot_identification' => '0000',
        'building' => '0000',
        'postal_zone' => '31952',
    ],
    'branch_name' => 'My Branch Name',
    'branch_industry' => 'Food',
    'cancelation' => [
        'cancelation_type' => 'INVOICE',
        'canceled_invoice_number' => '',
    ],
];

$invoice = [
    'invoice_counter_number' => 1,
    'invoice_serial_number' => 'EGS1-886431145-1',
    'issue_date' => '2022-03-13',
    'issue_time' => '14:40:40',
    'previous_invoice_hash' => 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==', // AdditionalDocumentReference/PIH
    'line_items' => [
        $line_item,
        $line_item,
        $line_item,
    ],
];

// EGS::ENV_SANDBOX (developer portal), EGS::ENV_SIMULATION or EGS::ENV_PRODUCTION.
$egs = new EGS($egs_unit, EGS::ENV_SANDBOX);

// New Keys & CSR for the EGS
list($private_key, $csr) = $egs->generateNewKeysAndCSR('Qr');

// Issue a new compliance cert for the EGS (OTP comes from the Fatoora portal)
list($request_id, $binary_security_token, $secret) = $egs->issueComplianceCertificate('123345', $csr);

// Sign invoice
list($signed_invoice_string, $invoice_hash, $qr, $invoice_uuid) = $egs->signInvoice($invoice, $egs_unit, $binary_security_token, $private_key);

// Check invoice compliance — pass the per-invoice UUID that signInvoice() returned.
// echo $egs->checkInvoiceCompliance($signed_invoice_string, $invoice_hash, $binary_security_token, $secret, $invoice_uuid), PHP_EOL;

// Once every compliance check passes, swap the compliance CSID for a production one:
// list($request_id, $production_certificate, $production_secret) = $egs->issueProductionCertificate($request_id, $binary_security_token, $secret);
// echo $egs->reportInvoice($signed_invoice_string, $invoice_hash, $production_certificate, $production_secret, $invoice_uuid), PHP_EOL;

// Generate QR Code
$qrCode = QrCode::create($qr)
    ->setEncoding(new Encoding('UTF-8'))
    ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
    ->setSize(300)
    ->setMargin(10)
    ->setForegroundColor(new Color(0, 0, 0))
    ->setBackgroundColor(new Color(255, 255, 255));

$writer = new PngWriter();
$logo = Logo::create(__DIR__ . '/assets/logo.png')
    ->setResizeToWidth(50)
    ->setPunchoutBackground(true);

$label = Label::create('Qr Phase-2')
    ->setTextColor(new Color(255, 0, 0));

$result = $writer->write($qrCode, $logo, $label);

// Save QR Code to file
if (!is_dir(__DIR__ . '/tmp')) {
    mkdir(__DIR__ . '/tmp', 0755, true);
}
$result->saveToFile(__DIR__ . '/tmp/phase-2.png');

if (PHP_SAPI === 'cli') {
    echo "Invoice UUID: {$invoice_uuid}\n";
    echo "Invoice hash: {$invoice_hash}\n";
    echo "QR (Base64 TLV): {$qr}\n";
    echo "QR image written to tmp/phase-2.png\n";
    return;
}

header('Content-Type: ' . $result->getMimeType());
echo $result->getString();
