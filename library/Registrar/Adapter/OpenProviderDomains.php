<?php

declare(strict_types=1);

/**
 * OpenProviderDomains registrar module for FOSSBilling.
 *
 * A clean, self-contained re-implementation of an OpenProvider domain
 * registrar adapter targeting the OpenProvider REST API (v1beta) and
 * FOSSBilling 0.8.x.
 *
 * @see https://docs.openprovider.com/doc/all
 * @see https://docs.fossbilling.org/extensions-and-development/guides/creating-a-module/
 *
 * @copyright OpenProviderDomains contributors
 * @license   Apache-2.0
 */

require_once __DIR__ . '/OpenProviderDomains/Api.php';

class Registrar_Adapter_OpenProviderDomains extends Registrar_AdapterAbstract
{
    /**
     * Default name server group used when the customer did not provide any
     * name servers of their own. "dns-openprovider" is OpenProvider's free
     * hosted DNS group and is always available to resellers.
     */
    private const DEFAULT_NS_GROUP = 'dns-openprovider';

    /** @var array<string, string|null> */
    public array $config = [
        'username' => null,
        'password' => null,
    ];

    private ?Registrar_Adapter_OpenProviderDomains_Api $api = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct($options)
    {
        if (isset($options['username']) && !empty($options['username'])) {
            $this->config['username'] = $options['username'];
        } else {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing.', [':domain_registrar' => 'OpenProvider', ':missing' => 'OpenProvider username'], 3001);
        }

        if (isset($options['password']) && !empty($options['password'])) {
            $this->config['password'] = $options['password'];
        } else {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing.', [':domain_registrar' => 'OpenProvider', ':missing' => 'OpenProvider password'], 3001);
        }
    }

    /**
     * Configuration form shown in the FOSSBilling admin panel.
     *
     * The sandbox/production switch is intentionally not part of this form:
     * FOSSBilling exposes a dedicated "Test mode" toggle for every registrar
     * which calls enableTestMode() on this adapter. Enabling it points all
     * requests at the OpenProvider sandbox.
     *
     * @return array{label: string, form: array<string, mixed>}
     */
    public static function getConfig(): array
    {
        return [
            'label' => 'Manage domains through the OpenProvider REST API. Enter your OpenProvider account (reseller) credentials below. Enable the registrar "Test mode" to use the OpenProvider sandbox environment instead of production.',
            'form' => [
                'username' => [
                    'text', [
                        'label' => 'OpenProvider username',
                        'description' => 'The username of your OpenProvider account.',
                        'required' => true,
                    ],
                ],
                'password' => [
                    'password', [
                        'label' => 'OpenProvider password',
                        'description' => 'The password of your OpenProvider account.',
                        'required' => true,
                        'renderPassword' => true,
                    ],
                ],
            ],
        ];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $result = $this->_api()->call('POST', '/domains/check', [
            'domains' => [$this->_domainParts($domain)],
        ]);

        return ($result['data']['results'][0]['status'] ?? null) === 'free';
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $result = $this->_api()->call('POST', '/domains/check', [
            'domains' => [$this->_domainParts($domain)],
        ]);

        // A domain has to be registered ("active") somewhere before it can be
        // transferred away to OpenProvider.
        return ($result['data']['results'][0]['status'] ?? null) === 'active';
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $handle = $this->_getOrCreateCustomer($this->_contact($domain));

        $data = [
            'domain' => $this->_domainParts($domain),
            'period' => $this->_period($domain),
            'owner_handle' => $handle,
            'admin_handle' => $handle,
            'tech_handle' => $handle,
            'billing_handle' => $handle,
            'autorenew' => 'default',
        ];

        $nameServers = $this->_nameServers($domain);
        if ($nameServers !== []) {
            $data['name_servers'] = $nameServers;
        } else {
            $data['ns_group'] = self::DEFAULT_NS_GROUP;
        }

        if ($domain->getPrivacyEnabled() !== null) {
            $data['is_private_whois_enabled'] = (bool) $domain->getPrivacyEnabled();
        }

        $this->_api()->call('POST', '/domains', $data);

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $handle = $this->_getOrCreateCustomer($this->_contact($domain));

        $data = [
            'domain' => $this->_domainParts($domain),
            'owner_handle' => $handle,
            'admin_handle' => $handle,
            'tech_handle' => $handle,
            'billing_handle' => $handle,
            'autorenew' => 'default',
            'auth_code' => (string) $domain->getEpp(),
        ];

        $nameServers = $this->_nameServers($domain);
        if ($nameServers !== []) {
            $data['name_servers'] = $nameServers;
        } else {
            // Keep whatever name servers the domain currently uses.
            $data['import_nameservers_from_registry'] = true;
        }

        $this->_api()->call('POST', '/domains/transfer', $data);

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $id = $this->_getDomainId($domain);

        $this->_api()->call('POST', "/domains/{$id}/renew", [
            'domain' => $this->_domainParts($domain),
            'period' => $this->_period($domain),
        ]);

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        $id = $this->_getDomainId($domain);

        $this->_api()->call('DELETE', "/domains/{$id}");

        return true;
    }

    public function getEpp(Registrar_Domain $domain)
    {
        $id = $this->_getDomainId($domain);

        $result = $this->_api()->call('GET', "/domains/{$id}/authcode");

        return $result['data']['auth_code'] ?? '';
    }

    public function getDomainDetails(Registrar_Domain $domain)
    {
        $id = $this->_getDomainId($domain);

        $result = $this->_api()->call('GET', "/domains/{$id}");
        $data = $result['data'] ?? [];

        if (!empty($data['creation_date'])) {
            $domain->setRegistrationTime(strtotime((string) $data['creation_date']));
        }
        if (!empty($data['expiration_date'])) {
            $domain->setExpirationTime(strtotime((string) $data['expiration_date']));
        }
        if (isset($data['is_private_whois_enabled'])) {
            $domain->setPrivacyEnabled((bool) $data['is_private_whois_enabled']);
        }
        if (isset($data['is_locked'])) {
            $domain->setLocked((bool) $data['is_locked']);
        }
        if (!empty($data['auth_code'])) {
            $domain->setEpp((string) $data['auth_code']);
        }

        $setters = ['setNs1', 'setNs2', 'setNs3', 'setNs4'];
        foreach (array_values($data['name_servers'] ?? []) as $i => $ns) {
            if ($i > 3 || empty($ns['name'])) {
                continue;
            }
            $domain->{$setters[$i]}((string) $ns['name']);
        }

        $handle = $data['owner_handle'] ?? ($data['admin_handle'] ?? '');
        if (!empty($handle)) {
            $contact = $this->_getContact((string) $handle);
            if ($contact instanceof Registrar_Domain_Contact) {
                $domain->setContactRegistrar($contact);
                $domain->setContactAdmin($contact);
                $domain->setContactTech($contact);
                $domain->setContactBilling($contact);
            }
        }

        return $domain;
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $nameServers = $this->_nameServers($domain);
        if ($nameServers === []) {
            throw new Registrar_Exception('At least one name server is required to update the domain name servers.');
        }

        $id = $this->_getDomainId($domain);
        $this->_api()->call('PUT', "/domains/{$id}", ['name_servers' => $nameServers]);

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $id = $this->_getDomainId($domain);
        $handle = $this->_getOrCreateCustomer($this->_contact($domain), true);

        $this->_api()->call('PUT', "/domains/{$id}", [
            'owner_handle' => $handle,
            'admin_handle' => $handle,
            'tech_handle' => $handle,
            'billing_handle' => $handle,
        ]);

        return true;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $id = $this->_getDomainId($domain);
        $this->_api()->call('PUT', "/domains/{$id}", ['is_locked' => true]);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $id = $this->_getDomainId($domain);
        $this->_api()->call('PUT', "/domains/{$id}", ['is_locked' => false]);

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $id = $this->_getDomainId($domain);
        $this->_api()->call('PUT', "/domains/{$id}", ['is_private_whois_enabled' => true]);

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $id = $this->_getDomainId($domain);
        $this->_api()->call('PUT', "/domains/{$id}", ['is_private_whois_enabled' => false]);

        return true;
    }

    /**
     * Lazily build (and cache) the API client. Caching the client keeps the
     * authentication token alive across the several calls a single operation
     * may perform.
     */
    private function _api(): Registrar_Adapter_OpenProviderDomains_Api
    {
        if (!$this->api instanceof Registrar_Adapter_OpenProviderDomains_Api) {
            $this->api = new Registrar_Adapter_OpenProviderDomains_Api(
                $this->getHttpClient(),
                (string) $this->config['username'],
                (string) $this->config['password'],
                $this->_testMode,
                $this->getLog()
            );
        }

        return $this->api;
    }

    /**
     * The "name"/"extension" pair OpenProvider expects for a domain.
     *
     * @return array{name: string, extension: string}
     */
    private function _domainParts(Registrar_Domain $domain): array
    {
        return [
            'name' => (string) $domain->getSld(),
            'extension' => ltrim((string) $domain->getTld(), '.'),
        ];
    }

    /**
     * Registration/renewal period in years, never below 1.
     */
    private function _period(Registrar_Domain $domain): int
    {
        return max(1, (int) $domain->getRegistrationPeriod());
    }

    /**
     * Build the OpenProvider name server list from the domain object,
     * skipping any empty entries.
     *
     * @return list<array{name: string, seq_nr: int}>
     */
    private function _nameServers(Registrar_Domain $domain): array
    {
        $servers = [];
        $seq = 0;
        foreach ([$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()] as $ns) {
            $ns = trim((string) $ns);
            if ($ns !== '') {
                $servers[] = ['name' => $ns, 'seq_nr' => $seq++];
            }
        }

        return $servers;
    }

    /**
     * Pick the first usable contact from the domain object. FOSSBilling sets
     * the same contact for all four roles, but we fall back gracefully.
     */
    private function _contact(Registrar_Domain $domain): Registrar_Domain_Contact
    {
        $contact = $domain->getContactRegistrar()
            ?? $domain->getContactAdmin()
            ?? $domain->getContactTech()
            ?? $domain->getContactBilling();

        if (!$contact instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('No contact information is available for domain :domain.', [':domain' => $domain->getName()]);
        }

        return $contact;
    }

    /**
     * Resolve the internal OpenProvider domain id for a FOSSBilling domain.
     */
    private function _getDomainId(Registrar_Domain $domain): int
    {
        $result = $this->_api()->call('GET', '/domains', [
            'full_name' => $domain->getName(),
            'limit' => 100,
        ]);

        $results = $result['data']['results'] ?? [];
        $wanted = strtolower($domain->getName());

        foreach ($results as $row) {
            $name = $row['domain']['name'] ?? '';
            $extension = $row['domain']['extension'] ?? '';
            $full = strtolower(trim($name . '.' . $extension, '.'));
            if ($full === $wanted && isset($row['id'])) {
                return (int) $row['id'];
            }
        }

        if (isset($results[0]['id'])) {
            return (int) $results[0]['id'];
        }

        throw new Registrar_Exception('Domain :domain was not found in the OpenProvider account.', [':domain' => $domain->getName()]);
    }

    /**
     * Find an existing customer handle (by exact e-mail) or create a new
     * customer, optionally updating an existing one with fresh data.
     */
    private function _getOrCreateCustomer(Registrar_Domain_Contact $contact, bool $updateExisting = false): string
    {
        $data = $this->_buildCustomerData($contact);

        $handle = $this->_findCustomerHandle((string) $contact->getEmail());
        if ($handle !== null) {
            if ($updateExisting) {
                $this->_api()->call('PUT', "/customers/{$handle}", $data);
            }

            return $handle;
        }

        $result = $this->_api()->call('POST', '/customers', $data);
        $handle = $result['data']['handle'] ?? null;
        if (empty($handle)) {
            throw new Registrar_Exception('OpenProvider did not return a customer handle when creating the contact.');
        }

        return (string) $handle;
    }

    /**
     * Look up a customer handle by exact e-mail address.
     */
    private function _findCustomerHandle(string $email): ?string
    {
        if ($email === '') {
            return null;
        }

        $result = $this->_api()->call('GET', '/customers', [
            'email_pattern' => $email,
            'limit' => 100,
        ]);

        foreach ($result['data']['results'] ?? [] as $row) {
            if (isset($row['handle'], $row['email']) && strcasecmp((string) $row['email'], $email) === 0) {
                return (string) $row['handle'];
            }
        }

        return null;
    }

    /**
     * Translate a FOSSBilling contact into an OpenProvider customer payload.
     *
     * @return array<string, mixed>
     */
    private function _buildCustomerData(Registrar_Domain_Contact $contact): array
    {
        $address = $this->_splitAddress((string) $contact->getAddress1());

        return [
            'company_name' => (string) ($contact->getCompany() ?? ''),
            'email' => (string) $contact->getEmail(),
            'name' => [
                'first_name' => (string) $contact->getFirstName(),
                'last_name' => (string) $contact->getLastName(),
            ],
            'address' => [
                'street' => $address['street'],
                'number' => $address['number'],
                'zipcode' => (string) $contact->getZip(),
                'city' => (string) $contact->getCity(),
                'state' => (string) ($contact->getState() ?? ''),
                'country' => strtoupper((string) $contact->getCountry()),
            ],
            'phone' => $this->_buildPhone((string) $contact->getTelCc(), (string) $contact->getTel()),
        ];
    }

    /**
     * Retrieve a customer by handle and map it back onto a FOSSBilling
     * contact object. Returns null if the customer cannot be fetched.
     */
    private function _getContact(string $handle): ?Registrar_Domain_Contact
    {
        try {
            $result = $this->_api()->call('GET', "/customers/{$handle}");
        } catch (Registrar_Exception) {
            return null;
        }

        $data = $result['data'] ?? [];
        if ($data === []) {
            return null;
        }

        $street = trim(($data['address']['street'] ?? '') . ' ' . ($data['address']['number'] ?? ''));
        $tel = trim(($data['phone']['area_code'] ?? '') . ($data['phone']['subscriber_number'] ?? ''));

        $contact = new Registrar_Domain_Contact();
        $contact
            ->setFirstName($data['name']['first_name'] ?? '')
            ->setLastName($data['name']['last_name'] ?? '')
            ->setEmail($data['email'] ?? '')
            ->setCompany($data['company_name'] ?? '')
            ->setAddress1($street)
            ->setCity($data['address']['city'] ?? '')
            ->setState($data['address']['state'] ?? '')
            ->setZip($data['address']['zipcode'] ?? '')
            ->setCountry($data['address']['country'] ?? '')
            ->setTelCc($data['phone']['country_code'] ?? '')
            ->setTel($tel);

        return $contact;
    }

    /**
     * Build an OpenProvider phone object from FOSSBilling's country-code and
     * number fields.
     *
     * OpenProvider expects the number split into country code, area code and
     * subscriber number. FOSSBilling only stores a calling code and the rest
     * of the number, so we mirror the heuristic used by OpenProvider's own
     * integrations: strip a leading zero and treat the first three remaining
     * digits as the area code.
     *
     * @return array{country_code: string, area_code: string, subscriber_number: string}
     */
    private function _buildPhone(string $countryCode, string $number): array
    {
        $countryCode = preg_replace('/\D/', '', $countryCode) ?? '';
        $number = ltrim(preg_replace('/\D/', '', $number) ?? '', '0');

        $areaCode = '';
        $subscriber = $number;
        if (strlen($number) > 3) {
            $areaCode = substr($number, 0, 3);
            $subscriber = substr($number, 3);
        }

        return [
            'country_code' => $countryCode === '' ? '' : '+' . $countryCode,
            'area_code' => $areaCode,
            'subscriber_number' => $subscriber,
        ];
    }

    /**
     * Best-effort split of a single address line into street + house number,
     * which OpenProvider stores separately. Falls back to putting everything
     * in the street field when no number can be detected.
     *
     * @return array{street: string, number: string}
     */
    private function _splitAddress(string $address): array
    {
        $address = trim($address);

        // House number at the end, e.g. "Main Street 12B" or "Baker St 221-223".
        if (preg_match('/^(.*?)[\s,]+(\d+\s*[a-zA-Z]?(?:[-\/]\d+\s*[a-zA-Z]?)?)$/', $address, $m)) {
            return ['street' => trim($m[1]), 'number' => trim($m[2])];
        }

        // House number at the start, e.g. "12 Main Street".
        if (preg_match('/^(\d+\s*[a-zA-Z]?)[\s,]+(.*)$/', $address, $m)) {
            return ['street' => trim($m[2]), 'number' => trim($m[1])];
        }

        return ['street' => $address, 'number' => ''];
    }
}
