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

- PHP **8.1+** with `ext-dom`
- The **`openssl` binary** available on the system (`shell_exec` is used for key/CSR generation in the EGS flow)
- `endroid/qr-code` ^5.0 (installed by Composer)

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

## Quick start — Phase 2 (onboarding, signing, reporting)

See the runnable example in [`phase-2.php`](phase-2.php) and the **Arabic step-by-step guide in `docs/`**. The flow:

1. Build the `EGS` unit info (VAT number, CRN, branch, location…)
2. `generateNewKeysAndCSR()` → secp256k1 private key + CSR
3. Compliance CSID (with the OTP from the Fatoora portal) → run compliance checks → Production CSID
4. Create the invoice → `sign()` → report to ZATCA

**Always start against the ZATCA sandbox/simulation environment before production.**

## Disclaimer

This is community software, not a ZATCA product. Validate your integration against ZATCA's official
[developer documentation](https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/Pages/default.aspx) and the compliance
checks for your own EGS before going live. No warranty — see LICENSE.

## Roadmap of this fork

- Packagist release and semantic versioning
- Practical Arabic documentation for Phase-2 scenarios (credit notes, cancellation, B2B standard invoices)
- Replacing `shell_exec` openssl calls with `ext-openssl` where feasible
- Tests for TLV output against ZATCA's published examples
