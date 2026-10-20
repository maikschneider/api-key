# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Key storage.** Table `tx_apikey_key` holds keys on the root page with title, identifier prefix, optional frontend/backend user binding, creator, scopes, expiry, last use, allowed IPs and sites. Keys are revoked by hiding or deleting the record ([#2](https://github.com/maikschneider/api-key/issues/2)).
- **Keys are stored as HMAC-SHA256 hashes.** The HMAC secret is derived from the TYPO3 `encryptionKey`, so a database leak alone does not let an attacker check guessed keys. Rotating the `encryptionKey` invalidates all keys ([#2](https://github.com/maikschneider/api-key/issues/2)).
- **`last_used_at` is written at most once every five minutes per key**, so authenticated requests don't cause a database write each time ([#2](https://github.com/maikschneider/api-key/issues/2)).
