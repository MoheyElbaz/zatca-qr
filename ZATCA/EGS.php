<?php

namespace ZATCA;

use DOMDocument;
use Exception;
use InvalidArgumentException;

class EGS
{
    public const ENV_SANDBOX = API::ENV_SANDBOX;
    public const ENV_SIMULATION = API::ENV_SIMULATION;
    public const ENV_PRODUCTION = API::ENV_PRODUCTION;

    /**
     * The Microsoft "certificate template name" OID value ZATCA uses to tell the
     * environments apart.
     */
    private const CODE_SIGNING_TEMPLATES = [
        self::ENV_SANDBOX => 'TSTZATCA-Code-Signing',
        self::ENV_SIMULATION => 'PREZATCA-Code-Signing',
        self::ENV_PRODUCTION => 'ZATCA-Code-Signing',
    ];

    private array $egs_info;
    private ?API $api = null;
    private string $environment;

    /**
     * @deprecated Kept for backwards compatibility; prefer passing the environment to the
     *             constructor or setEnvironment(). Setting it to true selects production.
     */
    public bool $production = false;

    public function __construct(array $egs_info, string $environment = self::ENV_SANDBOX)
    {
        if (!isset(self::CODE_SIGNING_TEMPLATES[$environment])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown ZATCA environment "%s". Expected one of: %s.',
                $environment,
                implode(', ', array_keys(self::CODE_SIGNING_TEMPLATES))
            ));
        }

        $this->egs_info = $egs_info;
        $this->environment = $environment;
    }

    public function setEnvironment(string $environment): self
    {
        if (!isset(self::CODE_SIGNING_TEMPLATES[$environment])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown ZATCA environment "%s". Expected one of: %s.',
                $environment,
                implode(', ', array_keys(self::CODE_SIGNING_TEMPLATES))
            ));
        }

        $this->environment = $environment;
        $this->api = null;

        return $this;
    }

    public function getEnvironment(): string
    {
        // The legacy `$egs->production = true` switch has to keep meaning "talk to the
        // production gateway", which is what it never did before.
        if ($this->production && $this->environment === self::ENV_SANDBOX) {
            return self::ENV_PRODUCTION;
        }

        return $this->environment;
    }

    /**
     * The API client for the currently selected environment.
     */
    public function api(): API
    {
        $environment = $this->getEnvironment();

        if ($this->api === null || $this->api->getEnvironment() !== $environment) {
            $this->api = new API($environment);
        }

        return $this->api;
    }

    /**
     * @return array{0: string, 1: string} [private key PEM, CSR PEM]
     */
    public function generateNewKeysAndCSR(string $solution_name)
    {
        $private_key = $this->generateSecp256k1KeyPair();

        return [$private_key, $this->generateCSR($solution_name, $private_key)];
    }

    /**
     * ZATCA requires a secp256k1 key. The key is produced in memory and returned as a
     * SEC1 ("BEGIN EC PRIVATE KEY") PEM — it is never written to disk by this library.
     */
    private function generateSecp256k1KeyPair(): string
    {
        $key = openssl_pkey_new([
            'curve_name' => 'secp256k1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            throw new Exception('Unable to generate a secp256k1 key pair: ' . self::opensslErrors());
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['ec']['d'], $details['ec']['x'], $details['ec']['y'])) {
            throw new Exception('Unable to read the generated key: ' . self::opensslErrors());
        }

        $private_key = self::toSec1Pem($details['ec']);

        // Never hand out a key we cannot read back.
        $check = openssl_pkey_get_private($private_key);
        if ($check === false || openssl_pkey_get_details($check)['key'] !== $details['key']) {
            throw new Exception('The generated private key failed its self check.');
        }

        return $private_key;
    }

    /**
     * Encode the raw EC parameters as an RFC 5915 ECPrivateKey, the format ZATCA's
     * tooling and documentation expect.
     */
    private static function toSec1Pem(array $ec): string
    {
        // secp256k1 => OID 1.3.132.0.10
        $curve_oid = "\x06\x05\x2B\x81\x04\x00\x0A";
        $point = "\x04" . str_pad($ec['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\x00", STR_PAD_LEFT);

        $sequence = self::der(0x02, "\x01")                                        // version
            . self::der(0x04, str_pad($ec['d'], 32, "\x00", STR_PAD_LEFT))         // privateKey
            . self::der(0xA0, $curve_oid)                                          // [0] parameters
            . self::der(0xA1, self::der(0x03, "\x00" . $point));                   // [1] publicKey

        $der = self::der(0x30, $sequence);

        return "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END EC PRIVATE KEY-----";
    }

    private static function der(int $tag, string $payload): string
    {
        $length = strlen($payload);

        if ($length < 0x80) {
            return chr($tag) . chr($length) . $payload;
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr($tag) . chr(0x80 | strlen($bytes)) . $bytes . $payload;
    }

    private static function opensslErrors(): string
    {
        $errors = [];
        while ($error = openssl_error_string()) {
            $errors[] = $error;
        }

        return $errors ? implode('; ', $errors) : 'no OpenSSL error reported';
    }

    /**
     * Builds the CSR with the ZATCA specific extensions. Only the OpenSSL configuration
     * touches the filesystem — and it goes to the system temp directory with 0600
     * permissions, never to the document root.
     */
    private function generateCSR(string $solution_name, string $private_key): string
    {
        if (!$private_key) throw new Exception('EGS has no private key');

        $key = openssl_pkey_get_private(ZATCASimplifiedTaxInvoice::wrapPrivateKeyString($private_key));
        if ($key === false) {
            throw new Exception('Unable to read the EGS private key: ' . self::opensslErrors());
        }

        $config_file = tempnam(sys_get_temp_dir(), 'zatca-csr-');
        if ($config_file === false) {
            throw new Exception('Unable to create a temporary file for the CSR configuration.');
        }

        try {
            chmod($config_file, 0600);

            if (file_put_contents($config_file, $this->defaultCSRConfig($solution_name)) === false) {
                throw new Exception('Unable to write the CSR configuration.');
            }

            $csr = openssl_csr_new($this->csrDistinguishedName(), $key, [
                'config' => $config_file,
                'req_extensions' => 'v3_req',
                'digest_alg' => 'sha256',
            ]);

            if ($csr === false) {
                throw new Exception('Unable to generate the CSR: ' . self::opensslErrors());
            }

            if (!openssl_csr_export($csr, $csr_string)) {
                throw new Exception('Unable to export the CSR: ' . self::opensslErrors());
            }
        } finally {
            @unlink($config_file);
        }

        return trim($csr_string);
    }

    private function egsInfo(string $key)
    {
        if (!array_key_exists($key, $this->egs_info)) {
            throw new InvalidArgumentException(sprintf('The EGS unit is missing the required key "%s".', $key));
        }

        return $this->egs_info[$key];
    }

    /**
     * The subject of the CSR: CN=<taxpayer provided id>, OU=<branch>, O=<taxpayer>, C=SA.
     */
    private function csrDistinguishedName(): array
    {
        return [
            'commonName' => (string) $this->egsInfo('custom_id'),
            'organizationalUnitName' => (string) $this->egsInfo('branch_name'),
            'organizationName' => (string) $this->egsInfo('VAT_name'),
            'countryName' => 'SA',
        ];
    }

    private function defaultCSRConfig(string $solution_name)
    {
        $location = $this->egsInfo('location');

        $config = [
            'egs_model' => $this->egsInfo('model'),
            'egs_serial_number' => $this->egsInfo('uuid'),
            'solution_name' => $solution_name,
            'vat_number' => $this->egsInfo('VAT_number'),
            'branch_location' => $location['building'] . ' ' . $location['street'] . ', ' . $location['city'],
            'branch_industry' => $this->egsInfo('branch_industry'),
            'branch_name' => $this->egsInfo('branch_name'),
            'taxpayer_name' => $this->egsInfo('VAT_name'),
            'taxpayer_provided_id' => $this->egsInfo('custom_id'),
        ];

        $template_csr = require __DIR__ . '/templates/csr_template.php';

        return strtr($template_csr, [
            'SET_PRODUCTION_VALUE' => self::CODE_SIGNING_TEMPLATES[$this->getEnvironment()],
            'SET_EGS_SERIAL_NUMBER' => "1-{$config['solution_name']}|2-{$config['egs_model']}|3-{$config['egs_serial_number']}",
            'SET_VAT_REGISTRATION_NUMBER' => $config['vat_number'],
            'SET_BRANCH_LOCATION' => $config['branch_location'],
            'SET_BRANCH_INDUSTRY' => $config['branch_industry'],
            'SET_COMMON_NAME' => $config['taxpayer_provided_id'],
            'SET_BRANCH_NAME' => $config['branch_name'],
            'SET_TAXPAYER_NAME' => $config['taxpayer_name'],
        ]);
    }

    /**
     * RFC 4122 version 4 UUID from a cryptographically secure source.
     */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array{0: string, 1: string, 2: string} [request id, compliance certificate, api secret]
     */
    public function issueComplianceCertificate(string $otp, $csr): array
    {
        if (!$csr) throw new Exception('EGS needs to generate a CSR first.');

        $issued_data = $this->api()->issueComplianceCertificate($csr, $otp);

        return [$issued_data->requestID, $issued_data->binarySecurityToken, $issued_data->secret];
    }

    /**
     * Exchanges a passed compliance check for the production certificate.
     *
     * @return array{0: string, 1: string, 2: string} [request id, production certificate, api secret]
     */
    public function issueProductionCertificate($compliance_request_id, string $certificate, string $secret): array
    {
        if (!$compliance_request_id) throw new Exception('EGS needs a compliance request id first.');

        $issued_data = $this->api()->issueProductionCertificate((string) $compliance_request_id, $certificate, $secret);

        return [$issued_data->requestID, $issued_data->binarySecurityToken, $issued_data->secret];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string} [signed invoice XML, invoice hash, QR, invoice UUID]
     */
    public function signInvoice(array $invoice, array $egs_unit, string $certificate, string $private_key): array
    {
        $invoice['uuid'] = $invoice['uuid'] ?? self::uuid();

        $zatca_simplified_tax_invoice = new ZATCASimplifiedTaxInvoice();

        $invoice_xml = $zatca_simplified_tax_invoice->simplifiedTaxInvoice($invoice, $egs_unit);

        $invoice_hash = $zatca_simplified_tax_invoice->getInvoiceHash($invoice_xml);

        list($hash, $issuer, $serialNumber, $public_key, $signature)
            = $zatca_simplified_tax_invoice->getCertificateInfo($certificate);

        $digital_signature = $zatca_simplified_tax_invoice->createInvoiceDigitalSignature($invoice_hash, $private_key);

        $qr = $zatca_simplified_tax_invoice->generateQR(
            $invoice_xml,
            $digital_signature,
            $public_key,
            $signature,
            $invoice_hash
        );

        $signed_properties_props = [
            // XAdES SigningTime is a UTC instant, hence gmdate() and not date().
            'sign_timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'certificate_hash' => $hash, // SignedSignatureProperties/SigningCertificate/CertDigest/<ds:DigestValue>SET_CERTIFICATE_HASH</ds:DigestValue>
            'certificate_issuer' => $issuer,
            'certificate_serial_number' => $serialNumber
        ];
        $ubl_signature_signed_properties_xml_string_for_signing = $zatca_simplified_tax_invoice->defaultUBLExtensionsSignedPropertiesForSigning($signed_properties_props);
        $ubl_signature_signed_properties_xml_string = $zatca_simplified_tax_invoice->defaultUBLExtensionsSignedProperties($signed_properties_props);

        $signed_properties_hash = base64_encode(openssl_digest($ubl_signature_signed_properties_xml_string_for_signing, 'sha256'));

        // UBL Extensions
        $ubl_signature_xml_string = $zatca_simplified_tax_invoice->defaultUBLExtensions(
            $invoice_hash, // <ds:DigestValue>SET_INVOICE_HASH</ds:DigestValue>
            $signed_properties_hash, // SignatureInformation/Signature/SignedInfo/Reference/<ds:DigestValue>SET_SIGNED_PROPERTIES_HASH</ds:DigestValue>
            $digital_signature,
            $certificate,
            $ubl_signature_signed_properties_xml_string
        );

        // Set signing elements
        $unsigned_invoice_str = $invoice_xml->saveXML();

        $unsigned_invoice_str = strtr($unsigned_invoice_str, [
            'SET_UBL_EXTENSIONS_STRING' => $ubl_signature_xml_string,
            'SET_QR_CODE_DATA' => $qr,
        ]);

        $signed_invoice = new DOMDocument();
        if (!$signed_invoice->loadXML($unsigned_invoice_str)) {
            throw new Exception('The signed invoice is not well formed XML.');
        }

        return [$signed_invoice->saveXML(), $invoice_hash, $qr, $invoice['uuid']];
    }

    /**
     * Runs the signed invoice through the compliance checks of the selected environment.
     *
     * @param  string|null  $uuid  The invoice UUID returned by signInvoice(). Defaults to
     *                             the EGS unit UUID for backwards compatibility.
     */
    public function checkInvoiceCompliance(string $signed_invoice_string, string $invoice_hash, string $certificate, string $secret, ?string $uuid = null): string
    {
        if (!$certificate || !$secret)
            throw new Exception('EGS is missing a certificate/private key/api secret to check the invoice compliance.');

        list($issueCertificate, $checkInvoiceCompliance) = $this->api()->compliance($certificate, $secret);
        $issued_data = $checkInvoiceCompliance($signed_invoice_string, $invoice_hash, $uuid ?? $this->egsInfo('uuid'));

        return json_encode($issued_data);
    }

    /**
     * Reports a simplified tax invoice to ZATCA (phase 2 obligation for B2C invoices).
     */
    public function reportInvoice(string $signed_invoice_string, string $invoice_hash, string $certificate, string $secret, ?string $uuid = null): string
    {
        if (!$certificate || !$secret)
            throw new Exception('EGS is missing a certificate/api secret to report the invoice.');

        return json_encode($this->api()->reportInvoice(
            $signed_invoice_string,
            $invoice_hash,
            $uuid ?? $this->egsInfo('uuid'),
            $certificate,
            $secret
        ));
    }
}
