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
