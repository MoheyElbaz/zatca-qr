<div align="center">
  <h1>ZATCA-QR (PHP)</h1>
  <p>Saudi Arabia ZATCA e-invoicing (Fatoora) toolkit in PHP — Phase 1 TLV QR codes, and Phase 2 EGS onboarding, invoice signing and reporting.</p>
  <p>
    <a href="https://github.com/MoheyElbaz/zatca-qr/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-MIT-green" alt="MIT"></a>
    <img src="https://img.shields.io/badge/PHP-%E2%89%A58.1-777bb3" alt="PHP >= 8.1">
    <img src="https://img.shields.io/badge/ZATCA-Phase%201%20%2B%202-0f766e" alt="ZATCA Phase 1 + 2">
  </p>
</div>

## Attribution — النسبة

This repository is a maintained fork of the PHP port by **[Nady Shalaby](https://github.com/nadyshalaby)**, itself a port of the original TypeScript library **[wes4m/zatca-xml-js](https://github.com/wes4m/zatca-xml-js)**. Credit for the original design and the PHP port belongs to them; this fork adds packaging, documentation (including a practical Arabic Phase-2 guide), and fixes.

هذا المستودع تفريعة مُصانة من نقل PHP للأستاذ نادي شلبي عن المكتبة الأصلية بلغة TypeScript لـ wes4m — الفضل في التصميم الأصلي والنقل لهما، وهذه التفريعة تضيف التغليف والتوثيق والإصلاحات.

## What it does

- **Phase 1**: build the Base64 **TLV** payload for simplified-invoice QR codes (seller, VAT number, timestamp, totals) and render the QR image.
- **Phase 2**: **EGS onboarding** (secp256k1 keys, CSR, compliance → production CSIDs), simplified tax invoice **UBL creation, signing** (cryptographic stamp), **compliance checks and reporting** to the Fatoora APIs.

## Requirements

- PHP **8.1+** with `ext-dom`, `ext-openssl`, `ext-curl` and `ext-json`
- No `openssl` binary and no `shell_exec`: keys, CSRs and signatures all go through `ext-openssl`
- `endroid/qr-code` ^5.0 — optional, only to render the QR image (the library returns the Base64 TLV string)

## Install

```bash
composer require moheyelbaz/zatca-qr
```

Until the package is on Packagist, install from the repository:

```json
{
  "repositories": [{ "type": "vcs", "url": "https://github.com/MoheyElbaz/zatca-qr" }],
  "require": { "moheyelbaz/zatca-qr": "dev-main" }
}
```

## Quick start — Phase 1 (QR only)

```php
use ZATCA\GenerateQrCode;
use ZATCA\Tags\{Seller, TaxNumber, InvoiceDate, InvoiceTotalAmount, InvoiceTaxAmount};

$base64TLV = GenerateQrCode::fromArray([
    new Seller('اسم المنشأة'),
    new TaxNumber('301121971500003'),
    new InvoiceDate('2026-09-02T10:30:00Z'),
    new InvoiceTotalAmount('115.00'),
    new InvoiceTaxAmount('15.00'),
])->toBase64();
// Feed $base64TLV to any QR renderer (see phase-1.php for endroid/qr-code usage)
```

The TLV length field is a single byte, so no field may exceed **255 bytes** — an Arabic
character is 2 bytes. An over-long value raises a `LengthException` instead of producing
a QR that cannot be parsed.

## Quick start — Phase 2 (onboarding, signing, reporting)

See the runnable example in [`phase-2.php`](phase-2.php) and the **Arabic step-by-step guide in `docs/`**. The flow:

```php
use ZATCA\EGS;

// EGS::ENV_SANDBOX (developer portal) · EGS::ENV_SIMULATION · EGS::ENV_PRODUCTION
$egs = new EGS($egs_unit, EGS::ENV_SANDBOX);

// 1. secp256k1 key (generated in memory, never written to disk) + CSR
[$private_key, $csr] = $egs->generateNewKeysAndCSR('My Solution');

// 2. Compliance CSID, using the OTP from the Fatoora portal
[$request_id, $certificate, $secret] = $egs->issueComplianceCertificate($otp, $csr);

// 3. Sign the invoice. Each invoice gets its own UUID — keep it, ZATCA wants it with the invoice.
[$signed_xml, $invoice_hash, $qr, $invoice_uuid] = $egs->signInvoice($invoice, $egs_unit, $certificate, $private_key);

// 4. Compliance checks → production CSID → report
echo $egs->checkInvoiceCompliance($signed_xml, $invoice_hash, $certificate, $secret, $invoice_uuid);
[$request_id, $production_certificate, $production_secret] = $egs->issueProductionCertificate($request_id, $certificate, $secret);
echo $egs->reportInvoice($signed_xml, $invoice_hash, $production_certificate, $production_secret, $invoice_uuid);
```

The environment picks both the gateway and the code-signing template baked into the CSR
(`TSTZATCA-` / `PREZATCA-` / `ZATCA-Code-Signing`), so the two can no longer drift apart.

**Always start against the ZATCA sandbox/simulation environment before production.**

## Tests

```bash
php tests/run.php     # or: composer test
```

No dev dependencies needed — the suite covers the TLV encoding, invoice construction,
XML escaping, the cryptographic stamp and the EGS onboarding flow.

## Disclaimer

This is community software, not a ZATCA product. Validate your integration against ZATCA's official
[developer documentation](https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/Pages/default.aspx) and the compliance
checks for your own EGS before going live. No warranty — see LICENSE.

## Roadmap of this fork

- Packagist release and semantic versioning
- Practical Arabic documentation for Phase-2 scenarios (credit notes, cancellation, B2B standard invoices)
- B2B standard invoice (clearance) support beyond the current `API::clearInvoice()` endpoint
- Validating the generated UBL against ZATCA's XSD and schematron rules
