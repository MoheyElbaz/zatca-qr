<?php

namespace ZATCA;

use Exception;
use InvalidArgumentException;
use stdClass;

class API
{
    public const ENV_SANDBOX = 'sandbox';
    public const ENV_SIMULATION = 'simulation';
    public const ENV_PRODUCTION = 'production';

    /**
     * Fatoora gateway roots. The sandbox is the developer portal used for the
     * compliance walkthrough, simulation mirrors production without fiscal effect, and
     * core is the live environment.
     */
    private const BASE_URLS = [
        self::ENV_SANDBOX => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
        self::ENV_SIMULATION => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
        self::ENV_PRODUCTION => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
    ];

    private string $environment;
    private string $base_url;
    private string $version = 'V2';
    private int $timeout;

    public function __construct(string $environment = self::ENV_SANDBOX, int $timeout = 60)
    {
        if (!isset(self::BASE_URLS[$environment])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown ZATCA environment "%s". Expected one of: %s.',
                $environment,
                implode(', ', array_keys(self::BASE_URLS))
            ));
        }

        $this->environment = $environment;
        $this->base_url = self::BASE_URLS[$environment];
        $this->timeout = $timeout;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function getBaseUrl(): string
    {
        return $this->base_url;
    }

    private function getAuthHeaders($certificate, $secret): array
    {
        if ($certificate && $secret) {

            $certificate_stripped = $this->cleanUpCertificateString($certificate);
            $certificate_stripped = base64_encode($certificate_stripped);
            $basic = base64_encode($certificate_stripped . ':' . $secret);

            return [
                "Authorization: Basic $basic",
            ];
        }
        return [];
    }

    /**
     * @param  int[]  $accepted_codes  HTTP status codes that are not an error.
     *
     * @throws Exception On a transport failure or an unexpected status code.
     */
    private function post(string $path, array $payload, array $headers, string $what, array $accepted_codes = [200]): stdClass
    {
        $curl = curl_init($this->base_url . $path);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $body = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new Exception(sprintf('%s failed: %s', $what, $curl_error ?: 'no response from the ZATCA gateway'));
        }

        $response = json_decode($body);

        if (!in_array($http_code, $accepted_codes, true)) {
            throw new Exception(sprintf(
                '%s failed with HTTP %d (%s environment): %s',
                $what,
                $http_code,
                $this->environment,
                is_string($body) ? trim(substr($body, 0, 2000)) : ''
            ));
        }

        if (!$response instanceof stdClass) {
            throw new Exception(sprintf('%s returned a response that is not a JSON object: %s', $what, trim(substr((string) $body, 0, 2000))));
        }

        return $response;
    }

    private function wrapIssuedCertificate(stdClass $response): stdClass
    {
        if (!isset($response->binarySecurityToken)) {
            throw new Exception('The ZATCA response does not contain a binarySecurityToken.');
        }

        $issued_certificate = base64_decode($response->binarySecurityToken);
        $response->binarySecurityToken = "-----BEGIN CERTIFICATE-----\n{$issued_certificate}\n-----END CERTIFICATE-----";

        return $response;
    }

    /**
     * Returns the [issueCertificate, checkInvoiceCompliance] pair used by the onboarding
     * flow. Kept as closures for backwards compatibility with earlier releases.
     *
     * @return callable[]
     */
    public function compliance($certificate = NULL, $secret = NULL)
    {
        $auth_headers = $this->getAuthHeaders($certificate, $secret);

        $issueCertificate = function (string $csr, string $otp): stdClass {
            return $this->issueComplianceCertificate($csr, $otp);
        };

        $checkInvoiceCompliance = function (string $signed_invoice_string, string $invoice_hash, string $uuid) use ($auth_headers): stdClass {
            return $this->post(
                '/compliance/invoices',
                [
                    'invoiceHash' => $invoice_hash,
                    'uuid' => $uuid,
                    'invoice' => base64_encode($signed_invoice_string),
                ],
                [
                    'Accept-Version: ' . $this->version,
                    'Accept-Language: en',
                    'Content-Type: application/json',
                    ...$auth_headers,
                ],
                'The invoice compliance check',
                [200, 202]
            );
        };

        return [$issueCertificate, $checkInvoiceCompliance];
    }

    /**
     * Compliance CSID: exchanges the CSR plus the portal OTP for a compliance
     * certificate, its request id and the API secret.
     */
    public function issueComplianceCertificate(string $csr, string $otp): stdClass
    {
        $response = $this->post(
            '/compliance',
            ['csr' => base64_encode($csr)],
            [
                'Accept-Version: ' . $this->version,
                'OTP: ' . $otp,
                'Content-Type: application/json',
            ],
            'Issuing a compliance certificate'
        );

        return $this->wrapIssuedCertificate($response);
    }

    /**
     * Production CSID: once every compliance check has passed, exchange the compliance
     * request id for the production certificate used to stamp real invoices.
     */
    public function issueProductionCertificate(string $compliance_request_id, string $certificate, string $secret): stdClass
    {
        $response = $this->post(
            '/production/csids',
            ['compliance_request_id' => $compliance_request_id],
            [
                'Accept-Version: ' . $this->version,
                'Content-Type: application/json',
                ...$this->getAuthHeaders($certificate, $secret),
            ],
            'Issuing a production certificate'
        );

        return $this->wrapIssuedCertificate($response);
    }

    /**
     * Reporting a simplified tax invoice (the phase 2 obligation for B2C invoices).
     * ZATCA answers 200 when the invoice is reported and 202 when it is reported with
     * warnings; both are returned to the caller for inspection.
     */
    public function reportInvoice(string $signed_invoice_string, string $invoice_hash, string $uuid, string $certificate, string $secret): stdClass
    {
        return $this->post(
            '/invoices/reporting/single',
            [
                'invoiceHash' => $invoice_hash,
                'uuid' => $uuid,
                'invoice' => base64_encode($signed_invoice_string),
            ],
            [
                'Accept-Version: ' . $this->version,
                'Accept-Language: en',
                'Clearance-Status: 0',
                'Content-Type: application/json',
                ...$this->getAuthHeaders($certificate, $secret),
            ],
            'Reporting the invoice',
            [200, 202]
        );
    }

    /**
     * Clearance of a standard (B2B) tax invoice.
     */
    public function clearInvoice(string $signed_invoice_string, string $invoice_hash, string $uuid, string $certificate, string $secret): stdClass
    {
        return $this->post(
            '/invoices/clearance/single',
            [
                'invoiceHash' => $invoice_hash,
                'uuid' => $uuid,
                'invoice' => base64_encode($signed_invoice_string),
            ],
            [
                'Accept-Version: ' . $this->version,
                'Accept-Language: en',
                'Clearance-Status: 1',
                'Content-Type: application/json',
                ...$this->getAuthHeaders($certificate, $secret),
            ],
            'Clearing the invoice',
            [200, 202]
        );
    }

    public static function cleanUpCertificateString(string $certificate): string
    {
        return ZATCASimplifiedTaxInvoice::cleanUpCertificateString($certificate);
    }
}
