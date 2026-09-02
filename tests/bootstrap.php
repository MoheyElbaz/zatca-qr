<?php

/**
 * Minimal, dependency free test harness so the suite runs on a bare checkout
 * (`php tests/run.php`) without a `composer install`.
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'ZATCA\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = dirname(__DIR__) . '/ZATCA/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

final class TestRegistry
{
    /** @var array<int, array{0: string, 1: callable}> */
    public static array $tests = [];
    public static int $assertions = 0;
}

function test(string $name, callable $body): void
{
    TestRegistry::$tests[] = [$name, $body];
}

function fail(string $message): void
{
    throw new RuntimeException($message);
}

function ok($condition, string $message): void
{
    TestRegistry::$assertions++;

    if (!$condition) {
        fail($message);
    }
}

function same($expected, $actual, string $message): void
{
    TestRegistry::$assertions++;

    if ($expected !== $actual) {
        fail(sprintf("%s\n    expected: %s\n    actual:   %s", $message, var_export($expected, true), var_export($actual, true)));
    }
}

function contains(string $needle, string $haystack, string $message): void
{
    TestRegistry::$assertions++;

    if (!str_contains($haystack, $needle)) {
        fail(sprintf("%s\n    %s was not found in:\n%s", $message, var_export($needle, true), substr($haystack, 0, 4000)));
    }
}

function not_contains(string $needle, string $haystack, string $message): void
{
    TestRegistry::$assertions++;

    if (str_contains($haystack, $needle)) {
        fail(sprintf("%s\n    %s was unexpectedly found in:\n%s", $message, var_export($needle, true), substr($haystack, 0, 4000)));
    }
}

function throws(string $expected_class, callable $body, string $message): Throwable
{
    TestRegistry::$assertions++;

    try {
        $body();
    } catch (Throwable $thrown) {
        if (!$thrown instanceof $expected_class) {
            fail(sprintf('%s: expected %s, got %s (%s)', $message, $expected_class, get_class($thrown), $thrown->getMessage()));
        }

        return $thrown;
    }

    fail(sprintf('%s: expected a %s, nothing was thrown', $message, $expected_class));
}

/**
 * Decodes a Base64 TLV payload into [tag => value].
 *
 * @return array<int, string>
 */
function decode_tlv(string $base64): array
{
    $bytes = base64_decode($base64, true);
    if ($bytes === false) {
        fail('The QR payload is not valid base64.');
    }

    $tags = [];
    $offset = 0;
    $length = strlen($bytes);

    while ($offset < $length) {
        if ($offset + 2 > $length) {
            fail('Truncated TLV: a tag/length pair is incomplete.');
        }

        $tag = ord($bytes[$offset]);
        $size = ord($bytes[$offset + 1]);
        $offset += 2;

        if ($offset + $size > $length) {
            fail(sprintf('Truncated TLV: tag %d claims %d bytes but only %d remain.', $tag, $size, $length - $offset));
        }

        $tags[$tag] = substr($bytes, $offset, $size);
        $offset += $size;
    }

    return $tags;
}

/**
 * A self signed secp256k1 certificate that stands in for a ZATCA issued CSID.
 *
 * @return array{0: string, 1: string} [certificate PEM, private key PEM]
 */
function make_certificate(array $dn = null): array
{
    $dn = $dn ?? [
        'countryName' => 'SA',
        'organizationName' => 'ZATCA',
        'organizationalUnitName' => 'GDT',
        'commonName' => 'ZATCA CA',
    ];

    $key = openssl_pkey_new(['curve_name' => 'secp256k1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($key === false) {
        fail('Unable to generate a test key pair.');
    }

    // An empty request section, so the host's openssl.cnf does not add defaults (an
    // "ST=Some-State" of its own) to the distinguished name under test.
    $config_file = tempnam(sys_get_temp_dir(), 'zatca-test-');
    file_put_contents($config_file, "[req]\nprompt = no\ndistinguished_name = dn\n\n[dn]\n");
    $config = ['config' => $config_file, 'digest_alg' => 'sha256'];

    try {
        $csr = openssl_csr_new($dn, $key, $config);
        if ($csr === false) {
            fail('Unable to generate a test CSR.');
        }

        $certificate = openssl_csr_sign($csr, null, $key, 365, $config);
        if ($certificate === false) {
            fail('Unable to self sign the test certificate.');
        }
    } finally {
        @unlink($config_file);
    }

    openssl_x509_export($certificate, $certificate_pem);
    openssl_pkey_export($key, $key_pem);

    return [$certificate_pem, $key_pem];
}

function sample_egs_unit(array $overrides = []): array
{
    return array_replace([
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
    ], $overrides);
}

function sample_invoice(array $line_items = null, array $overrides = []): array
{
    $line_items = $line_items ?? [[
        'id' => '1',
        'name' => 'TEST NAME',
        'quantity' => 5,
        'tax_exclusive_price' => 10,
        'VAT_percent' => 0.15,
        'other_taxes' => [['percent_amount' => 1]],
        'discounts' => [
            ['amount' => 2, 'reason' => 'A discount'],
            ['amount' => 2, 'reason' => 'A second discount'],
        ],
    ]];

    return array_replace([
        'invoice_counter_number' => 1,
        'invoice_serial_number' => 'EGS1-886431145-1',
        'issue_date' => '2022-03-13',
        'issue_time' => '14:40:40',
        'previous_invoice_hash' => 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==',
        'line_items' => $line_items,
    ], $overrides);
}
