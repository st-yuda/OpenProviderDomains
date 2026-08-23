# OpenProviderDomains — OpenProvider registrar for FOSSBilling

A domain registrar module that connects [FOSSBilling](https://fossbilling.org)
to [OpenProvider](https://www.openprovider.com) through the official
OpenProvider REST API (v1beta).

It is a ground-up rewrite of the older OpenProvider adapter and is built and
tested against **FOSSBilling 0.8.x**.

## Features

- **Availability & transfer checks** — query whether a domain is free or can be transferred.
- **Register / transfer / renew / delete** domains.
- **Name server management** — push custom name servers, or fall back to OpenProvider's hosted DNS (`dns-openprovider`) when none are supplied.
- **Contact management** — automatically creates/reuses an OpenProvider customer handle from the order contact and keeps it in sync.
- **Registrar lock** (lock / unlock) and **WHOIS privacy** (enable / disable).
- **EPP / auth code retrieval** and full domain-detail synchronisation (expiry date, lock state, privacy, name servers, contact).
- **Sandbox support** — flip the registrar's *Test Mode* switch to talk to the OpenProvider sandbox instead of production.
- **DNS record management (companion module)** — an included client-area module (`OpenProvider DNS`) lets your customers manage the DNS records of their OpenProvider domains (A, AAAA, CNAME, MX, TXT, …) — for example to point a domain at an IP address. See [DNS management](#dns-management-companion-module).

## How it differs from the old module

This module fixes the problems that prevented the previous adapter from working
reliably on FOSSBilling 0.8.2:

- Uses FOSSBilling's built-in Symfony HTTP client instead of hand-rolled cURL, so JSON bodies are sent with the correct `Content-Type: application/json` header (the old client silently dropped it, so every `POST`/`PUT`/`DELETE` failed).
- Authenticates **once** and reuses the bearer token for the whole operation instead of logging in on every single API call.
- Routes all logging through FOSSBilling's logger (`$this->getLog()`) instead of writing to a `logs/` directory that does not exist (which raised PHP warnings).
- Surfaces OpenProvider error messages as proper `Registrar_Exception`s, so failures are visible in the admin panel instead of failing silently.
- Resolves domains by `full_name` and verifies the match, splits phone numbers and street/house numbers the way OpenProvider expects, and honours customer-supplied name servers.
- Selects the production or sandbox endpoint from the registrar's **Test Mode** toggle rather than a free-text URL field.

## Requirements

- FOSSBilling 0.8.0 or newer (PHP 8.3+).
- An OpenProvider account with API access.

## Installation

1. Copy the contents of this repository's `library/` folder into your
   FOSSBilling installation, preserving the directory structure. The adapter
   must end up at:

   ```
   <fossbilling>/library/Registrar/Adapter/OpenProviderDomains.php
   <fossbilling>/library/Registrar/Adapter/OpenProviderDomains/Api.php
   ```

   For example, from the FOSSBilling root:

   ```bash
   git clone https://github.com/st-yuda/OpenProviderDomains.git /tmp/opd
   cp -r /tmp/opd/library/* /path/to/fossbilling/library/
   ```

2. In the FOSSBilling admin panel go to **Configuration → Domain registration**
   (System → Domain registration on some builds).

3. Open the **New domain registrar** tab, choose **OpenProviderDomains** and
   click **Install**.

4. Switch to the **Registrars** tab, edit **OpenProviderDomains** and enter your
   OpenProvider **username** and **password**.

5. *(Optional)* Turn **Enable Test Mode** on to use the OpenProvider sandbox
   (`http://api.sandbox.openprovider.nl:8480`). Leave it off for production
   (`https://api.openprovider.eu`).

6. Save, then assign the registrar to the TLDs you want to sell under the
   **TLDs** tab.

## Configuration reference

| Setting    | Description                                            |
|------------|--------------------------------------------------------|
| Username   | Your OpenProvider account / reseller username.         |
| Password   | Your OpenProvider account password.                    |
| Test Mode  | When enabled, all requests go to the OpenProvider sandbox. Managed by FOSSBilling's standard per-registrar toggle. |

## DNS management (companion module)

The repository ships a second, optional FOSSBilling module — **OpenProvider DNS**
(`modules/Openproviderdns/`) — that adds a client-area page where your customers
manage the DNS zone of the domains they registered through OpenProvider. It
reuses the registrar's credentials, so there is nothing extra to configure.

**What it does**

- Lists each client's active OpenProvider domains.
- Shows the domain's DNS records and lets the client add, edit and delete
  `A`, `AAAA`, `CNAME`, `MX`, `TXT`, `NS`, `SRV`, `CAA` and `ALIAS` records.
- Creates the DNS zone automatically when the first record is added.
- Every request is scoped to the logged-in client's own orders, so a client can
  only ever touch the DNS of domains they own.

**Installation**

1. Copy `modules/Openproviderdns/` into your FOSSBilling `modules/` directory:

   ```
   <fossbilling>/modules/Openproviderdns/
   ```

2. In the admin panel go to **Extensions → Overview**, find **OpenProvider DNS**
   and click **Activate**.

3. Clients reach the DNS manager at **`/openproviderdns`**. Add a link to it from
   your theme's client menu (or link to it from your domain management page) so
   customers can find it.

**Prerequisites** — for DNS edits to take effect, the domain must actually use
OpenProvider's name servers (e.g. the `dns-openprovider` group / `ns1.openprovider.nl`…).
If the domain points to another DNS provider (Cloudflare, etc.), changes made
here have no effect. The registrar module registers new domains with
OpenProvider's name servers by default when the customer supplies none.

> The DNS module depends on the `OpenProviderDomains` registrar module being
> installed (it reuses its API client and stored credentials).

## Notes & limitations

- The order contact is mapped to a single OpenProvider customer handle that is
  used for the owner, admin, tech and billing roles (FOSSBilling provides one
  contact per domain).
- Some TLDs require extra registrant data ("additional data") that FOSSBilling
  does not collect. Such registrations may be rejected by OpenProvider with a
  descriptive error shown in the FOSSBilling logs.
- House numbers are extracted from the contact's address line with a
  best-effort heuristic; if no number is detected the whole line is sent as the
  street.

## Troubleshooting

- **"Could not connect to the OpenProvider API"** — your server cannot reach the
  API endpoint. Check outbound connectivity and firewalls.
- **"OpenProvider API error …"** — the API rejected the request; the message is
  passed straight through from OpenProvider. Authentication problems usually
  mean the username/password are wrong or the account lacks API access.
- Enable FOSSBilling's debug logging to see each request the adapter makes.

## License

Licensed under the Apache License 2.0. See [LICENSE](LICENSE).

OpenProvider is a trademark of its respective owner. This is an independent,
community-maintained integration and is not affiliated with or endorsed by
OpenProvider.
