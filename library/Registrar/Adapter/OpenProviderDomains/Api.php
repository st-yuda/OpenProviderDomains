<?php

declare(strict_types=1);

/**
 * OpenProviderDomains registrar module for FOSSBilling.
 *
 * Thin client for the OpenProvider REST API (v1beta).
 *
 * @see https://docs.openprovider.com/doc/all
 *
 * @copyright OpenProviderDomains contributors
 * @license   Apache-2.0
 */

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal OpenProvider API v1beta client.
 *
 * Authentication is performed once and the resulting bearer token is reused
 * for every subsequent call made through the same instance. OpenProvider
 * tokens are valid for 48 hours, so a single login comfortably covers all the
 * requests issued during one registrar operation.
 *
 * Every call returns the decoded response envelope on success. OpenProvider
 * signals errors with a non-zero "code" field; those are turned into a
 * Registrar_Exception so callers can simply assume success when no exception
 * is thrown.
 */
class Registrar_Adapter_OpenProviderDomains_Api
{
    /** Production API base URL. */
    private const PRODUCTION_URL = 'https://api.openprovider.eu/v1beta';

    /** Sandbox (test) API base URL. */
    private const SANDBOX_URL = 'http://api.sandbox.openprovider.nl:8480/v1beta';

    private HttpClientInterface $httpClient;
    private string $username;
    private string $password;
    private string $baseUrl;
    private ?Box_Log $log;
    private ?string $token = null;

    public function __construct(HttpClientInterface $httpClient, string $username, string $password, bool $testMode = false, ?Box_Log $log = null)
    {
        $this->httpClient = $httpClient;
        $this->username = $username;
        $this->password = $password;
        $this->baseUrl = $testMode ? self::SANDBOX_URL : self::PRODUCTION_URL;
        $this->log = $log;
    }

    /**
     * The base URL currently in use (handy for diagnostics / tests).
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Authenticate and cache the bearer token for the lifetime of this object.
     */
    public function login(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $data = $this->send('POST', '/auth/login', [
            'json' => [
                'username' => $this->username,
                'password' => $this->password,
            ],
        ]);

        $token = $data['data']['token'] ?? null;
        if (empty($token)) {
            throw new Registrar_Exception('OpenProvider authentication failed: no token was returned. Please verify the configured username and password.');
        }

        return $this->token = (string) $token;
    }

    /**
     * Perform an authenticated API call.
     *
     * @param string               $method HTTP method (GET, POST, PUT, DELETE)
     * @param string               $path   endpoint path, e.g. "/domains/check"
     * @param array<string, mixed> $params query parameters for GET requests, JSON body otherwise
     *
     * @return array<string, mixed> the decoded response envelope
     */
    public function call(string $method, string $path, array $params = []): array
    {
        $method = strtoupper($method);
        $options = ['auth_bearer' => $this->login()];

        if ($method === 'GET') {
            if ($params !== []) {
                $options['query'] = $params;
            }
        } elseif ($params !== []) {
            $options['json'] = $params;
        }

        return $this->send($method, $path, $options);
    }

    /**
     * Low-level request handling: send the request, decode the JSON envelope
     * and convert transport problems and OpenProvider error codes into a
     * Registrar_Exception.
     *
     * @param array<string, mixed> $options Symfony HTTP client options
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $options): array
    {
        $this->log?->debug(sprintf('OpenProvider API request: %s %s', $method, $path));

        try {
            $response = $this->httpClient->request($method, $this->baseUrl . $path, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (HttpClientException $e) {
            $this->log?->error('OpenProvider API transport error: ' . $e->getMessage());

            throw new Registrar_Exception('Could not connect to the OpenProvider API: :error', [':error' => $e->getMessage()]);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            $this->log?->error(sprintf('OpenProvider API returned a non-JSON response (HTTP %d): %s', $status, substr($body, 0, 500)));

            throw new Registrar_Exception('The OpenProvider API returned an unexpected response (HTTP :status).', [':status' => $status]);
        }

        $code = $data['code'] ?? 0;
        if ($code !== 0) {
            $desc = $data['desc'] ?? '';
            if (!is_string($desc) || $desc === '') {
                $desc = 'Unknown error';
            }
            $this->log?->error(sprintf('OpenProvider API error %s on %s %s: %s', (string) $code, $method, $path, $desc));

            throw new Registrar_Exception('OpenProvider API error :code: :desc', [':code' => (string) $code, ':desc' => $desc]);
        }

        return $data;
    }
}
