<?php

declare(strict_types=1);

/**
 * OpenProvider DNS management module for FOSSBilling — client controller.
 *
 * Registers the client-area page at /openproviderdns.
 *
 * @copyright OpenProviderDomains contributors
 * @license   Apache-2.0
 */

namespace Box\Mod\Openproviderdns\Controller;

class Client implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/openproviderdns', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        // Ensures a client is logged in (redirects to login otherwise).
        $this->di['is_client_logged'];

        $api = $this->di['api_client'];
        $domains = $api->openproviderdns_domains();

        return $app->render('mod_openproviderdns_index', [
            'domains' => $domains,
            'types' => \Box\Mod\Openproviderdns\Service::SUPPORTED_TYPES,
        ]);
    }
}
