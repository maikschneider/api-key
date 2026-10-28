# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Backend module "API Keys"** under Administration › Integrations (TYPO3 v14). Administrators list keys, create one and see its secret once with a copy button, and revoke keys ([#9](https://github.com/maikschneider/api-key/issues/9)).

### Fixed

- Ship `Configuration/` in the TER package. The `config` packaging exclusion matched it as a case-insensitive prefix.

## [1.0.0] - 2026-10-21

### Added

- **Documentation for v1**: introduction and comparison with the core authentication service, sending keys with curl, security (storage, `encryptionKey` rotation, query strings, Apache and the `Authorization` header, logging) and known problems ([#7](https://github.com/maikschneider/api-key/issues/7)).

- **Keys stop working while their bound user is unavailable.** A key bound to a frontend or backend user that is deleted, disabled or outside its start and end time is invalid (`InvalidReason::UserUnavailable`). `apikey:create --created-by=<be_users uid>` records the creator, which `apikey:list` shows ([#6](https://github.com/maikschneider/api-key/issues/6)).

- **Frontend middleware attaches the result as request attribute `api_key`.** It runs after site resolution and before frontend user authentication, never logs in a user and sets no cookie. With the extension setting `rejectInvalidKeys` it answers invalid keys with `401` and `WWW-Authenticate` itself ([#5](https://github.com/maikschneider/api-key/issues/5)).

- **IP and site restrictions are enforced.** A key with allowed IPs only authenticates from a matching address or CIDR range, resolved behind trusted reverse proxies via `normalizedParams`; a key with sites only authenticates on requests whose `site` attribute is one of them. `ApiKey::hasScope()` lets consumers check scopes; a key without scopes has every scope ([#4](https://github.com/maikschneider/api-key/issues/4)).

- **`ApiKeyAuthenticatorInterface::authenticate()` verifies the key of a request.** It reads `X-API-Key` and `Authorization: Bearer t3key_…` and returns an `AuthenticationResult` that is *not present*, *valid* with an `ApiKey` (uid, title, identifier, scopes, bound frontend or backend user, expiry) or *invalid* with an `InvalidReason`. Bearer tokens without the `t3key_` prefix count as not present, so other authenticators still get them. Keys in the query string, malformed, unknown, revoked and expired keys are invalid. No session, no cookie; rejections are logged without the key ([#1](https://github.com/maikschneider/api-key/issues/1)).

- **Key format `t3key_<base64url(32 random bytes)>`.** The prefix makes keys recognisable for secret scanners; the documentation ships a GitHub custom pattern and a gitleaks rule. The prefix plus eight characters form the identifier that is shown in the backend and in logs ([#3](https://github.com/maikschneider/api-key/issues/3)).
- **`apikey:create`, `apikey:list` and `apikey:revoke` commands.** `apikey:create` prints the key once and validates users, expiry, IP ranges and sites before storing anything. `apikey:revoke` takes the identifier or the uid and hides the record. The `ApiKeyCreator` service does the same for other callers ([#3](https://github.com/maikschneider/api-key/issues/3)).

- **Key storage.** Table `tx_apikey_key` holds keys on the root page with title, identifier prefix, optional frontend/backend user binding, creator, scopes, expiry, last use, allowed IPs and sites. Keys are revoked by hiding or deleting the record ([#2](https://github.com/maikschneider/api-key/issues/2)).
- **Keys are stored as HMAC-SHA256 hashes.** The HMAC secret is derived from the TYPO3 `encryptionKey`, so a database leak alone does not let an attacker check guessed keys. Rotating the `encryptionKey` invalidates all keys ([#2](https://github.com/maikschneider/api-key/issues/2)).
- **`last_used_at` is written at most once every five minutes per key**, so authenticated requests don't cause a database write each time ([#2](https://github.com/maikschneider/api-key/issues/2)).

### Fixed

- **Keys in the query string are found even when PHP drops them while parsing.** The extractor also checks the query string as sent and parameter names, so `?key=t3key_…&key=redacted` or `?t3key_…=1` is rejected as `KeyInQueryString`, even with a valid header ([#22](https://github.com/maikschneider/api-key/issues/22)).
- **Broken `Authorization` headers carrying a key are rejected.** `Bearer t3key_… garbage` is `Malformed`, and a `t3key_` bearer token in one of several `Authorization` headers is `Ambiguous`. Both used to count as no key, so `rejectInvalidKeys` did not answer them with `401`. Foreign bearer tokens still count as no key ([#20](https://github.com/maikschneider/api-key/issues/20)).

- **Restrictions without a value no longer make a key unrestricted.** `ApiKeyCreator` and `apikey:create` split scopes, allowed IPs and sites the way authentication reads them and reject a given restriction that contains no value, such as `--scope=",,,"`. A record whose restriction field holds only separators, e.g. edited in the backend, is rejected as `InvalidReason::Misconfigured` ([#21](https://github.com/maikschneider/api-key/issues/21)).
- **`0.0.0.0/0` allows every IPv4 client.** `GeneralUtility::cmpIP()` read an IPv4 prefix length of zero as an exact address, so such a key rejected every client. IPv4 and IPv6 ranges still never match the other address family ([#23](https://github.com/maikschneider/api-key/issues/23)).
- **`apikey:create --expires` rejects every date that is not in the future.** `--expires=@0` and other dates at the Unix epoch used to create a key that never expires, because `0` means "no expiry" ([#24](https://github.com/maikschneider/api-key/issues/24)).

### Security

- **Responses to authenticated requests no longer enter the page cache.** For a valid key, the middleware disables the page cache through the `frontend.cache.instruction` request attribute. Before, a cacheable page whose content depended on the key could be served from the cache to anonymous visitors or to another key. The documentation covers caching for consumers that call the authenticator themselves and for reverse proxies ([#19](https://github.com/maikschneider/api-key/issues/19)).
