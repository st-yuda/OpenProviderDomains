<?php

declare(strict_types=1);

/**
 * OpenProvider DNS management module for FOSSBilling — client API.
 *
 * Every method resolves the target domain through the client's own order, so
 * a client can only ever read or change the DNS of domains they own.
 *
 * @copyright OpenProviderDomains contributors
 * @license   Apache-2.0
 */

namespace Box\Mod\Openproviderdns\Api;

/*
 * Cross-version compatibility: FOSSBilling renamed the client/admin API base
 * class from the global `Api_Abstract` (0.8.2 and earlier) to
 * `\FOSSBilling\Api\AbstractApi` (0.8.3+). Alias the historical name onto the
 * new one so this module runs on both without change.
 */
if (!class_exists(\FOSSBilling\Api\AbstractApi::class) && class_exists('Api_Abstract')) {
    class_alias('Api_Abstract', \FOSSBilling\Api\AbstractApi::class);
}

class Client extends \FOSSBilling\Api\AbstractApi
{
    /**
     * List the current client's OpenProvider domains.
     *
     * @return list<array{order_id: int, domain: string}>
     */
    public function domains($data = []): array
    {
        return $this->getService()->getClientDomains($this->getIdentity());
    }

    /**
     * List the DNS records of one of the client's domains.
     *
     * @param array{order_id: int} $data
     *
     * @return array{domain: string, zone_exists: bool, records: array}
     */
    public function records($data): array
    {
        return $this->getService()->getRecords($this->_getDomain($data));
    }

    /**
     * Add a DNS record.
     *
     * @param array{order_id: int, name?: string, type: string, value: string, ttl?: int, prio?: int} $data
     */
    public function record_add($data): bool
    {
        $this->getDi()['events_manager']->fire(['event' => 'onBeforeClientOpenproviderdnsRecordAdd', 'params' => $data]);

        $result = $this->getService()->addRecord($this->_getDomain($data), $this->_record($data));

        $this->getDi()['events_manager']->fire(['event' => 'onAfterClientOpenproviderdnsRecordAdd', 'params' => $data]);

        return $result;
    }

    /**
     * Update an existing DNS record. The original_* fields identify the record
     * to change.
     *
     * @param array{order_id: int, original_name: string, original_type: string, original_value: string, original_ttl?: int, original_prio?: int, name?: string, type: string, value: string, ttl?: int, prio?: int} $data
     */
    public function record_update($data): bool
    {
        $original = [
            'name' => $data['original_name'] ?? '',
            'type' => $data['original_type'] ?? '',
            'value' => $data['original_value'] ?? '',
            'ttl' => $data['original_ttl'] ?? null,
            'prio' => $data['original_prio'] ?? 0,
        ];

        return $this->getService()->updateRecord($this->_getDomain($data), $original, $this->_record($data));
    }

    /**
     * Delete a DNS record.
     *
     * @param array{order_id: int, name: string, type: string, value: string, ttl?: int, prio?: int} $data
     */
    public function record_delete($data): bool
    {
        return $this->getService()->deleteRecord($this->_getDomain($data), $this->_record($data));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{name: string, type: string, value: string, ttl: mixed, prio: mixed}
     */
    private function _record($data): array
    {
        return [
            'name' => $data['name'] ?? '',
            'type' => $data['type'] ?? '',
            'value' => $data['value'] ?? '',
            'ttl' => $data['ttl'] ?? null,
            'prio' => $data['prio'] ?? 0,
        ];
    }

    /**
     * Load the client's domain service from an order id, enforcing ownership.
     *
     * @param array<string, mixed> $data
     */
    private function _getDomain($data): \Model_ServiceDomain
    {
        if (!isset($data['order_id'])) {
            throw new \FOSSBilling\InformationException('Order ID is required');
        }

        $orderService = $this->getDi()['mod_service']('order');
        $order = $orderService->findForClientById($this->getIdentity(), $data['order_id']);
        if (!$order instanceof \Model_ClientOrder) {
            throw new \FOSSBilling\InformationException('Order not found');
        }

        $service = $orderService->getOrderService($order);
        if (!$service instanceof \Model_ServiceDomain || $order->status !== 'active') {
            throw new \FOSSBilling\InformationException('Domain order is not active');
        }

        return $service;
    }
}
