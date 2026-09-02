<?php

use ZATCA\EGS;
use ZATCA\ZATCASimplifiedTaxInvoice;

test('the issuer name keeps its attribute types', function () {
    // Regression: the issuer was rebuilt as "CN=" plus the bare RDN values, so
    // <ds:X509IssuerName> never matched the real issuer of the certificate.
    [$certificate] = make_certificate([
        'countryName' => 'SA',
        'organizationName' => 'ZATCA',
        'organizationalUnitName' => 'GDT',
        'commonName' => 'ZATCA CA',
    ]);

    [, $issuer] = (new ZATCASimplifiedTaxInvoice())->getCertificateInfo($certificate);

    same('CN=ZATCA CA, OU=GDT, O=ZATCA, C=SA', $issuer, 'The issuer must be a full RFC 2253 distinguished name');
});

test('the certificate hash, serial, public key and signature are extracted', function () {
    [$certificate] = make_certificate();

    [$hash, , $serial, $public_key, $signature] = (new ZATCASimplifiedTaxInvoice())->getCertificateInfo($certificate);

    $body = ZATCASimplifiedTaxInvoice::cleanUpCertificateString($certificate);

    same(base64_encode(hash('sha256', $body)), $hash, 'ZATCA expects the base64 of the hex digest of the certificate body');
    ok($serial !== '' && ctype_digit((string) $serial), 'The serial number is a decimal string');
    same("\x30", $public_key[0], 'The public key is returned as DER (a SEQUENCE)');

    $der = base64_decode($body);
    ok(str_contains($der, $signature), 'The extracted signature must be the one inside the certificate');
    ok(strlen($signature) >= 68, 'An ECDSA P-256 signature is around 70 bytes');
});

test('the cryptographic stamp signs the invoice hash bytes', function () {
    // Regression: the hash was base64_encode()d again before signing, so ZATCA — which
    // verifies against the raw digest bytes — rejected every stamp.
    [$certificate, $private_key] = make_certificate();

    $invoice_hash = base64_encode(hash('sha256', 'an invoice', true));

    $signature = base64_decode((new ZATCASimplifiedTaxInvoice())->createInvoiceDigitalSignature($invoice_hash, $private_key));
    $public_key = openssl_pkey_get_public($certificate);

    same(1, openssl_verify(base64_decode($invoice_hash), $signature, $public_key, OPENSSL_ALGO_SHA256), 'The signature must verify over the raw hash bytes');
    same(0, openssl_verify(base64_encode($invoice_hash), $signature, $public_key, OPENSSL_ALGO_SHA256), 'It must not be a signature over the re-encoded hash');
});

test('signing accepts both SEC1 and PKCS#8 private keys', function () {
    $invoice_hash = base64_encode(hash('sha256', 'an invoice', true));
    $builder = new ZATCASimplifiedTaxInvoice();

    // PKCS#8, as produced by openssl_pkey_export().
    [$certificate, $pkcs8] = make_certificate();
    ok($builder->createInvoiceDigitalSignature($invoice_hash, $pkcs8) !== '', 'A PKCS#8 key can be used');

    // SEC1, as produced by this library and by the ZATCA tooling.
    [$sec1] = (new EGS(sample_egs_unit()))->generateNewKeysAndCSR('Qr');
    contains('-----BEGIN EC PRIVATE KEY-----', $sec1, 'The library hands out a SEC1 key');

    $signature = base64_decode($builder->createInvoiceDigitalSignature($invoice_hash, $sec1));
    $public_key = openssl_pkey_get_details(openssl_pkey_get_private($sec1))['key'];

    same(1, openssl_verify(base64_decode($invoice_hash), $signature, $public_key, OPENSSL_ALGO_SHA256), 'A SEC1 key signs verifiably');

    // A bare base64 body (no PEM armour) is still accepted.
    $stripped = ZATCASimplifiedTaxInvoice::cleanUpPrivateKeyString($sec1);
    ok($builder->createInvoiceDigitalSignature($invoice_hash, $stripped) !== '', 'A bare base64 key body is accepted');
});

test('an unusable private key raises instead of returning an empty signature', function () {
    // Regression: openssl_sign()'s return value was discarded, so a bad key produced an
    // invoice signed with an empty SignatureValue.
    $thrown = throws(RuntimeException::class, function () {
        (new ZATCASimplifiedTaxInvoice())->createInvoiceDigitalSignature(base64_encode('hash'), 'not a key');
    }, 'A broken key must raise');

    contains('private key', $thrown->getMessage(), 'The message should name the problem');

    throws(InvalidArgumentException::class, function () {
        [, $key] = make_certificate();
        (new ZATCASimplifiedTaxInvoice())->createInvoiceDigitalSignature('not base64 !!', $key);
    }, 'An invoice hash that is not base64 must raise');
});

test('signInvoice produces a signed document, a QR and a fresh UUID', function () {
    [$certificate, $private_key] = make_certificate();

    $egs = new EGS(sample_egs_unit());

    [$signed_xml, $invoice_hash, $qr, $uuid] = $egs->signInvoice(sample_invoice(), sample_egs_unit(), $certificate, $private_key);
    [$second_xml, , , $second_uuid] = $egs->signInvoice(sample_invoice(), sample_egs_unit(), $certificate, $private_key);

    ok($uuid !== $second_uuid, 'Every invoice gets its own UUID');
    ok($second_xml !== '', 'A second invoice can be signed in the same process');

    $document = new DOMDocument();
    ok($document->loadXML($signed_xml), 'The signed invoice is well formed XML');

    not_contains('SET_UBL_EXTENSIONS_STRING', $signed_xml, 'The UBL extensions placeholder is filled in');
    not_contains('SET_QR_CODE_DATA', $signed_xml, 'The QR placeholder is filled in');

    $xpath = invoice_xpath($document);
    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
    $xpath->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');

    same($uuid, $xpath->evaluate('string(/ubl:Invoice/cbc:UUID)'), 'The returned UUID is the one in the document');
    same($invoice_hash, $xpath->evaluate('string(//ds:Reference[@Id="invoiceSignedData"]/ds:DigestValue)'), 'The invoice hash is referenced');
    ok($xpath->evaluate('string(//ds:SignatureValue)') !== '', 'The signature value is not empty');
    same('CN=ZATCA CA, OU=GDT, O=ZATCA, C=SA', $xpath->evaluate('string(//ds:X509IssuerName)'), 'The signed properties carry the real issuer');

    // The QR is a complete phase 2 payload: the nine tags ZATCA expects.
    $tags = decode_tlv($qr);
    same([1, 2, 3, 4, 5, 6, 7, 8, 9], array_keys($tags), 'The QR carries all nine phase 2 tags');
    same('Qr', $tags[1], 'Tag 1 is the seller name');
    same('301121971500003', $tags[2], 'Tag 2 is the VAT number');
    same('2022-03-13T14:40:40Z', $tags[3], 'Tag 3 mirrors the invoice issue date and time');
    same($invoice_hash, $tags[6], 'Tag 6 is the invoice hash');
    same(1, openssl_verify(base64_decode($invoice_hash), base64_decode($tags[7]), openssl_pkey_get_public($certificate), OPENSSL_ALGO_SHA256), 'Tag 7 verifies against the certificate');
});

test('the QR timestamp does not depend on the server timezone', function () {
    [$certificate, $private_key] = make_certificate();
    $original_timezone = date_default_timezone_get();

    try {
        $stamps = [];
        foreach (['UTC', 'Asia/Riyadh', 'America/Los_Angeles'] as $timezone) {
            date_default_timezone_set($timezone);
            [, , $qr] = (new EGS(sample_egs_unit()))->signInvoice(sample_invoice(), sample_egs_unit(), $certificate, $private_key);
            $stamps[$timezone] = decode_tlv($qr)[3];
        }

        same(['UTC' => '2022-03-13T14:40:40Z', 'Asia/Riyadh' => '2022-03-13T14:40:40Z', 'America/Los_Angeles' => '2022-03-13T14:40:40Z'], $stamps, 'The QR timestamp must mirror the invoice, whatever the server timezone');
    } finally {
        date_default_timezone_set($original_timezone);
    }
});

test('an unparseable issue date is rejected instead of silently becoming 1970', function () {
    [$certificate, $private_key] = make_certificate();

    throws(InvalidArgumentException::class, function () use ($certificate, $private_key) {
        (new EGS(sample_egs_unit()))->signInvoice(
            sample_invoice(null, ['issue_date' => '13/03/2022']),
            sample_egs_unit(),
            $certificate,
            $private_key
        );
    }, 'A malformed issue date must raise');
});
