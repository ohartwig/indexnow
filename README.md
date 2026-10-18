<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# koh/indexnow

A small, framework-agnostic PHP client for [IndexNow](https://www.indexnow.org/documentation):
the protocol with which a website tells search engines that a URL was added,
changed or removed — instead of waiting until a crawler comes by.

Bing, Yandex, Seznam, Naver, Yep and Amazon take part and share every
submission with each other. Bing's index feeds ChatGPT search and Copilot,
which makes IndexNow the quickest way for new content to become citable there.

This package does the protocol and nothing else. It has no framework
dependency, no configuration file and no global state; it talks HTTP through
any PSR-18 client. Integrations exist for
[TYPO3](https://github.com/ohartwig/typo3-indexnow) and
[Sylius](https://github.com/ohartwig/sylius-indexnow).

## What it takes care of

- **Keys.** Format checks per the specification, and `Key::derive()`: a stable
  per-host key computed from an application secret with HMAC-SHA256. Nothing
  to store, nothing to provision, identical on every replica, different for
  every host. The key file path is compared in constant time.
- **Hosts that must never be announced.** Submissions cannot be recalled, and
  every participating engine receives them. `HostPolicy` refuses IP addresses,
  single-label names, reserved and private suffixes (`.localhost`, `.test`,
  `.invalid`, `.internal`, `.local`, `home.arpa`, `example.*`, ...) and hosts
  carrying a pre-production label such as `dev`, `staging` or `preview` left of
  the registrable domain. The check runs inside the client, before any network
  I/O, so no integration can forget it.
- **Submissions the engine will accept.** URLs are validated against the
  protocol before sending: same host, `http`/`https`, RFC 3986 characters only,
  no credentials, no fragments, inside the key file's directory when a
  `keyLocation` is used. Duplicates are removed; lists over 10,000 URLs are
  split. `UrlCollection` sorts an untrusted, mixed list by host and sets
  invalid URLs aside instead of failing the batch.
- **Outcomes a queue can act on.** Every response becomes an `Outcome` with
  `isRetryable()`: 429, 5xx, transport errors and 403 (a key file the engine
  could not fetch *yet*) are retryable; 400 and 422 are not. `Retry-After` is
  parsed and capped. Transport failures are results, not exceptions.
- **No leaks.** The key never appears in exception messages or in the log
  excerpts of engine responses.

## Usage

```php
use Koh\IndexNow\IndexNowClient;
use Koh\IndexNow\Key;
use Koh\IndexNow\Submission;

$key = Key::derive($applicationSecret, 'www.example.org');
// Serve $key->value as text/plain at https://www.example.org/{$key->fileName()}

$client = new IndexNowClient($psr18Client, $psr17RequestFactory, $psr17StreamFactory);
$submission = Submission::forHost('www.example.org', $key, [
    'https://www.example.org/news/launch',
    'https://www.example.org/news/',
]);

foreach ($client->submitAll($submission) as $result) {
    if ($result->isRetryable()) {
        // queue again, after $result->retryAfterSeconds if the engine said so
    }
}
```

Give your PSR-18 client a short timeout (five to ten seconds) and do not let
it follow redirects: the request body carries the key.

The default endpoint is `https://api.indexnow.org/indexnow`. Other endpoints
must be HTTPS and one of the known participants, unless explicitly allowed with
`Endpoint::fromUrl($url, allowUnknownHost: true)` — a guard against a typo in
configuration sending the key to someone else's domain.

## Requirements

PHP 8.3 or later, a PSR-18 client and PSR-17 factories. `ext-intl` is used for
internationalised host names when present.

## Independence

This is an independent implementation, written from the IndexNow
specification. It is not affiliated with Microsoft, Yandex or any other
participant.

## Licence

MIT. See [LICENSE](LICENSE).
