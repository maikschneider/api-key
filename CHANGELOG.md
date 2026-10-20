# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Key format `t3key_<base64url(32 random bytes)>`.** The prefix makes keys recognisable for secret scanners; the documentation ships a GitHub custom pattern and a gitleaks rule. The prefix plus eight characters form the identifier that is shown in the backend and in logs ([#3](https://github.com/maikschneider/api-key/issues/3)).
- **`apikey:create`, `apikey:list` and `apikey:revoke` commands.** `apikey:create` prints the key once and validates users, expiry, IP ranges and sites before storing anything. `apikey:revoke` takes the identifier or the uid and hides the record. The `ApiKeyCreator` service does the same for other callers ([#3](https://github.com/maikschneider/api-key/issues/3)).

- **Key storage.** Table `tx_apikey_key` holds keys on the root page with title, identifier prefix, optional frontend/backend user binding, creator, scopes, expiry, last use, allowed IPs and sites. Keys are revoked by hiding or deleting the record ([#2](https://github.com/maikschneider/api-key/issues/2)).
- **Keys are stored as HMAC-SHA256 hashes.** The HMAC secret is derived from the TYPO3 `encryptionKey`, so a database leak alone does not let an attacker check guessed keys. Rotating the `encryptionKey` invalidates all keys ([#2](https://github.com/maikschneider/api-key/issues/2)).
- **`last_used_at` is written at most once every five minutes per key**, so authenticated requests don't cause a database write each time ([#2](https://github.com/maikschneider/api-key/issues/2)).
