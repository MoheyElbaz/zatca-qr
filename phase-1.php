<?php

/**
 * Phase 1 example: build the Base64 TLV payload for a simplified invoice QR code and
 * render it as a PNG.
 *
 * Run it from the CLI (`php phase-1.php`) or serve it over HTTP.
 * Requires `composer install` (the QR renderer is a dev/optional dependency).
 */

require __DIR__ . '/vendor/autoload.php';

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use ZATCA\GenerateQrCode;
use ZATCA\Tags\InvoiceDate;
use ZATCA\Tags\InvoiceTaxAmount;
use ZATCA\Tags\InvoiceTotalAmount;
use ZATCA\Tags\Seller;
use ZATCA\Tags\TaxNumber;

$generatedString = GenerateQrCode::fromArray([
    new Seller('Qr'), // seller name
    new TaxNumber('323457892389823'), // seller tax number
    new InvoiceDate('2021-07-12T14:25:09Z'), // invoice date as Zulu ISO8601 @see https://en.wikipedia.org/wiki/ISO_8601
    new InvoiceTotalAmount('100.00'), // invoice total amount (VAT inclusive)
    new InvoiceTaxAmount('15.00'), // invoice tax amount
])->toBase64();

// Generate QR Code
$qrCode = QrCode::create($generatedString)
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

$label = Label::create('Qr Phase-1')
    ->setTextColor(new Color(255, 0, 0));

$result = $writer->write($qrCode, $logo, $label);

// Save QR Code to file
if (!is_dir(__DIR__ . '/tmp')) {
    mkdir(__DIR__ . '/tmp', 0755, true);
}
$result->saveToFile(__DIR__ . '/tmp/phase-1.png');

if (PHP_SAPI === 'cli') {
    echo "TLV (Base64): {$generatedString}\n";
    echo "QR image written to tmp/phase-1.png\n";
    return;
}

header('Content-Type: ' . $result->getMimeType());
echo $result->getString();
