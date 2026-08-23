<?php

declare(strict_types=1);

/**
 * OpenProvider DNS management module for FOSSBilling.
 *
 * Companion to the OpenProviderDomains registrar module. It lets clients
 * manage the DNS zone of the domains they registered through OpenProvider
 * (A, AAAA, CNAME, MX, TXT, ... records) — for example to point a domain at
 * an IP address.
 *
 * It reuses the registrar's API client (Registrar_Adapter_OpenProviderDomains_Api)
 * and the credentials stored on the OpenProvider registrar, so there is
 * nothing extra to configure.
 *
 * @copyright OpenProviderDomains contributors
 * @license   Apache-2.0
 */

namespace Box\Mod\Openproviderdns;

class Service implements \FOSSBilling\InjectionAwareInterface
{
    /** Registrar name this module is bound to (the adapter file name). */
    public const REGISTRAR_NAME = 'OpenProviderDomains';

    /** DNS record types we expose to clients. */
    public const SUPPORTED_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'ALIAS'];

    /** Default record TTL in seconds. */
    public const DEFAULT_TTL = 3600;

    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /**
     * List the active domains a client owns that are managed by the
     * OpenProvider registrar and are therefore eligible for DNS management.
     *
     * @return list<array{order_id: int, domain: string}>
     */
    public function getClientDomains(\Model_Client $client): array
    {
        $orders = $this->di['db']->find(
            'ClientOrder',
            'client_id = :cid AND service_type = :stype AND status = :status ORDER BY id DESC',
            [
                ':cid' => $client->id,
                ':stype' => \Model_ProductTable::DOMAIN,
                ':status' => \Model_ClientOrder::STATUS_ACTIVE,
            ]
        );

        $orderService = $this->di['mod_service']('order');
        $domains = [];
        foreach ($orders as $order) {
            $service = $orderService->getOrderService($order);
            if (!$service instanceof \Model_ServiceDomain) {
                continue;
            }
            if (!$this->isOpenProviderDomain($service)) {
                continue;
            }
            $domains[] = [
                'order_id' => (int) $order->id,
                'domain' => $this->domainName($service),
            ];
        }

        return $domains;
    }

    /**
     * Return the current DNS records of a domain.
     *
     * @return array{domain: string, zone_exists: bool, records: list<array<string, mixed>>}
     */
    public function getRecords(\Model_ServiceDomain $domain): array
    {
        $name = $this->domainName($domain);
        $api = $this->api($domain);

        $records = [];
        $zoneExists = true;
        try {
            $response = $api->call('GET', '/dns/zones/' . $name);
            foreach ($response['data']['records'] ?? [] as $record) {
                $records[] = $this->mapRecord($record);
            }
        } catch (\Registrar_Exception) {
            // The zone does not exist yet — it is created when the first record is added.
            $zoneExists = false;
        }

        return [
            'domain' => $name,
            'zone_exists' => $zoneExists,
            'records' => $records,
        ];
    }

    /**
     * Add a DNS record. Creates the zone first if it does not exist yet.
     *
     * @param array<string, mixed> $input
     */
    public function addRecord(\Model_ServiceDomain $domain, array $input): bool
    {
        $name = $this->domainName($domain);
        $record = $this->normalizeRecord($input, $name);
        $api = $this->api($domain);

        if ($this->zoneExists($api, $name)) {
            $api->call('PUT', '/dns/zones/' . $name, [
                'name' => $name,
                'records' => ['add' => [$record]],
            ]);
        } else {
            $api->call('POST', '/dns/zones', [
                'domain' => $this->domainParts($domain),
                'type' => 'master',
                'records' => [$record],
            ]);
        }

        return true;
    }

    /**
     * Update an existing DNS record. The original values must be supplied so
     * OpenProvider can locate the record to change.
     *
     * @param array<string, mixed> $original
     * @param array<string, mixed> $updated
     */
    public function updateRecord(\Model_ServiceDomain $domain, array $original, array $updated): bool
    {
        $name = $this->domainName($domain);
        $api = $this->api($domain);

        $api->call('PUT', '/dns/zones/' . $name, [
            'name' => $name,
            'records' => [
                'update' => [[
                    'original_record' => $this->normalizeRecord($original, $name),
                    'record' => $this->normalizeRecord($updated, $name),
                ]],
            ],
        ]);

        return true;
    }

    /**
     * Delete a DNS record.
     *
     * @param array<string, mixed> $input
     */
    public function deleteRecord(\Model_ServiceDomain $domain, array $input): bool
    {
        $name = $this->domainName($domain);
        $api = $this->api($domain);

        $api->call('PUT', '/dns/zones/' . $name, [
            'name' => $name,
            'records' => ['remove' => [$this->normalizeRecord($input, $name)]],
        ]);

        return true;
    }

    /**
     * Build an OpenProvider API client from the registrar configuration bound
     * to the given domain.
     */
    protected function api(\Model_ServiceDomain $domain): \Registrar_Adapter_OpenProviderDomains_Api
    {
        if (!class_exists(\Registrar_Adapter_OpenProviderDomains_Api::class)) {
            throw new \FOSSBilling\InformationException('The OpenProviderDomains registrar module must be installed to manage DNS.');
        }

        $registrar = $this->di['db']->load('TldRegistrar', $domain->tld_registrar_id);
        if (!$registrar instanceof \Model_TldRegistrar || $registrar->registrar !== self::REGISTRAR_NAME) {
            throw new \FOSSBilling\InformationException('DNS management is only available for domains registered through OpenProvider.');
        }

        $config = json_decode($registrar->config ?? '', true) ?: [];
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        if ($username === '' || $password === '') {
            throw new \FOSSBilling\InformationException('The OpenProvider registrar is not fully configured.');
        }

        $httpClient = \Symfony\Component\HttpClient\HttpClient::create(['bindto' => BIND_TO]);

        return new \Registrar_Adapter_OpenProviderDomains_Api(
            $httpClient,
            $username,
            $password,
            !empty($registrar->test_mode),
            $this->di['logger']
        );
    }

    private function isOpenProviderDomain(\Model_ServiceDomain $domain): bool
    {
        $registrar = $this->di['db']->load('TldRegistrar', $domain->tld_registrar_id);

        return $registrar instanceof \Model_TldRegistrar && $registrar->registrar === self::REGISTRAR_NAME;
    }

    private function zoneExists(\Registrar_Adapter_OpenProviderDomains_Api $api, string $name): bool
    {
        try {
            $api->call('GET', '/dns/zones/' . $name);

            return true;
        } catch (\Registrar_Exception) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array{name: string, type: string, value: string, ttl: int, prio: int}
     */
    private function mapRecord(array $record): array
    {
        return [
            'name' => (string) ($record['name'] ?? ''),
            'type' => strtoupper((string) ($record['type'] ?? '')),
            'value' => (string) ($record['value'] ?? ''),
            'ttl' => (int) ($record['ttl'] ?? self::DEFAULT_TTL),
            'prio' => (int) ($record['prio'] ?? 0),
        ];
    }

    /**
     * Validate and normalise a record submitted by a client.
     *
     * @param array<string, mixed> $input
     *
     * @return array{name: string, type: string, value: string, ttl: int, prio: int}
     */
    private function normalizeRecord(array $input, string $domainName): array
    {
        $type = strtoupper(trim((string) ($input['type'] ?? '')));
        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new \FOSSBilling\InformationException('Unsupported DNS record type: :type', [':type' => $type]);
        }

        $value = trim((string) ($input['value'] ?? ''));
        if ($value === '') {
            throw new \FOSSBilling\InformationException('The DNS record value cannot be empty.');
        }

        $ttl = (int) ($input['ttl'] ?? self::DEFAULT_TTL);
        if ($ttl < 60) {
            $ttl = self::DEFAULT_TTL;
        }

        $prio = (int) ($input['prio'] ?? 0);
        if ($prio < 0) {
            $prio = 0;
        }

        return [
            'name' => $this->fqdn((string) ($input['name'] ?? ''), $domainName),
            'type' => $type,
            'value' => $value,
            'ttl' => $ttl,
            'prio' => $prio,
        ];
    }

    /**
     * Turn a user supplied host into a fully-qualified name inside the domain.
     * "" or "@" -> the domain itself, "www" -> "www.domain", a name already
     * ending in the domain is left untouched.
     */
    private function fqdn(string $name, string $domainName): string
    {
        $name = strtolower(trim($name));
        $name = rtrim($name, '.');

        if ($name === '' || $name === '@') {
            return $domainName;
        }

        if ($name === $domainName || str_ends_with($name, '.' . $domainName)) {
            return $name;
        }

        return $name . '.' . $domainName;
    }

    private function domainName(\Model_ServiceDomain $domain): string
    {
        $tld = (string) $domain->tld;
        if (!str_starts_with($tld, '.')) {
            $tld = '.' . $tld;
        }

        return strtolower($domain->sld . $tld);
    }

    /**
     * @return array{name: string, extension: string}
     */
    private function domainParts(\Model_ServiceDomain $domain): array
    {
        return [
            'name' => strtolower((string) $domain->sld),
            'extension' => ltrim(strtolower((string) $domain->tld), '.'),
        ];
    }
}
