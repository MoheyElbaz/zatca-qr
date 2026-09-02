<?php

use ZATCA\API;
use ZATCA\EGS;

test('key and CSR generation never leaves key material on disk', function () {
    $before = glob(sys_get_temp_dir() . '/zatca-csr-*') ?: [];

    [$private_key, $csr] = (new EGS(sample_egs_unit()))->generateNewKeysAndCSR('Qr');

    contains('-----BEGIN EC PRIVATE KEY-----', $private_key, 'The private key is a SEC1 PEM');
    contains('-----BEGIN CERTIFICATE REQUEST-----', $csr, 'The CSR is a PEM');

    $key = openssl_pkey_get_private($private_key);
    ok($key !== false, 'The private key can be read back');
    same('secp256k1', openssl_pkey_get_details($key)['ec']['curve_name'], 'ZATCA requires a secp256k1 key');

    $after = glob(sys_get_temp_dir() . '/zatca-csr-*') ?: [];
    same($before, $after, 'The temporary CSR configuration must be cleaned up');
});

test('the CSR carries the ZATCA subject and extensions', function () {
    [, $csr] = (new EGS(sample_egs_unit()))->generateNewKeysAndCSR('Qr');

    $subject = openssl_csr_get_subject($csr);

    same('EGS1-886431145', $subject['CN'], 'CN is the taxpayer provided id');
    same('My Branch Name', $subject['OU'], 'OU is the branch name');
    same('Qr', $subject['O'], 'O is the taxpayer name');
    same('SA', $subject['C'], 'C is always SA');

    $der = base64_decode(str_replace(
        ['-----BEGIN CERTIFICATE REQUEST-----', '-----END CERTIFICATE REQUEST-----'],
        '',
        $csr
    ));

    contains('TSTZATCA-Code-Signing', $der, 'The sandbox uses the TSTZATCA code signing template');
    contains('1-Qr|2-IOS|3-6f4d20e0-6bfe-4a80-9389-7dabe6620f12', $der, 'The EGS serial number is 1-solution|2-model|3-serial');
    contains('301121971500003', $der, 'The VAT registration number is in the subjectAltName');
    contains('0000 King Fahahd st, Khobar', $der, 'The branch location is in the subjectAltName');
});

test('each environment gets its own code signing template and gateway', function () {
    $expected = [
        EGS::ENV_SANDBOX => ['TSTZATCA-Code-Signing', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal'],
        EGS::ENV_SIMULATION => ['PREZATCA-Code-Signing', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation'],
        EGS::ENV_PRODUCTION => ['ZATCA-Code-Signing', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core'],
    ];

    foreach ($expected as $environment => [$template, $base_url]) {
        $egs = new EGS(sample_egs_unit(), $environment);

        [, $csr] = $egs->generateNewKeysAndCSR('Qr');
        $der = base64_decode(str_replace(['-----BEGIN CERTIFICATE REQUEST-----', '-----END CERTIFICATE REQUEST-----'], '', $csr));

        contains($template, $der, "The {$environment} CSR uses the {$template} template");
        same($base_url, $egs->api()->getBaseUrl(), "The {$environment} client points at the right gateway");
    }
});

test('the legacy production flag now really selects production', function () {
    // Regression: $egs->production only flipped the CSR OID; every request still went to
    // the developer portal sandbox.
    $egs = new EGS(sample_egs_unit());
    same('https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal', $egs->api()->getBaseUrl(), 'The default is the sandbox');

    $egs->production = true;
    same(EGS::ENV_PRODUCTION, $egs->getEnvironment(), 'production = true means the production environment');
    same('https://gw-fatoora.zatca.gov.sa/e-invoicing/core', $egs->api()->getBaseUrl(), 'and the production gateway');

    $egs->setEnvironment(EGS::ENV_SIMULATION);
    same('https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation', $egs->api()->getBaseUrl(), 'An explicit environment wins');
});

test('an unknown environment is rejected', function () {
    throws(InvalidArgumentException::class, function () {
        new EGS(sample_egs_unit(), 'staging');
    }, 'The EGS must reject an unknown environment');

    throws(InvalidArgumentException::class, function () {
        new API('staging');
    }, 'The API client must reject an unknown environment');
});

test('generated UUIDs are RFC 4122 version 4', function () {
    $uuids = [];

    for ($i = 0; $i < 50; $i++) {
        $uuid = EGS::uuid();
        ok(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) === 1, "{$uuid} is not a v4 UUID");
        $uuids[] = $uuid;
    }

    same(50, count(array_unique($uuids)), 'UUIDs must not repeat');
});

test('a missing EGS key is reported by name', function () {
    $egs_unit = sample_egs_unit();
    unset($egs_unit['custom_id']);

    $egs = new EGS($egs_unit);

    $thrown = throws(InvalidArgumentException::class, function () use ($egs) {
        $egs->generateNewKeysAndCSR('Qr');
    }, 'An incomplete EGS unit must be rejected');

    contains('custom_id', $thrown->getMessage(), 'The exception names the missing key');
});
