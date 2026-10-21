<div align="center">

![Extension icon](Resources/Public/Icons/Extension.svg)

# TYPO3 extension `api_key`

[![Supported PHP versions](https://img.shields.io/packagist/dependency-v/maikschneider/api-key/php?logo=php)](https://packagist.org/packages/maikschneider/api-key)
[![Tests](https://img.shields.io/github/actions/workflow/status/maikschneider/api-key/tests.yml?label=tests&logo=github)](https://github.com/maikschneider/api-key/actions/workflows/tests.yml)
[![CGL](https://img.shields.io/github/actions/workflow/status/maikschneider/api-key/sca.yml?label=cgl&logo=github)](https://github.com/maikschneider/api-key/actions/workflows/sca.yml)
[![Coverage](https://codecov.io/gh/maikschneider/api-key/graph/badge.svg)](https://codecov.io/gh/maikschneider/api-key)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE.md)

</div>

Stateless API key authentication for TYPO3. Scripts, cron jobs, CI pipelines and server-to-server integrations send a key, and any API, PSR-15 middleware or auth service can verify it, without creating sessions or cookies.

```bash
curl -H 'X-API-Key: t3key_…' https://example.com/api/articles
curl -H 'Authorization: Bearer t3key_…' https://example.com/api/articles
```

> [!WARNING]
> This extension is under development and not released yet.

## ✨ Features

* `X-API-Key` and `Authorization: Bearer` headers. Bearer tokens without the `t3key_` prefix are left to other authenticators
* Service keys, keys bound to a frontend user and keys bound to a backend user
* Keys are stored as HMAC hashes and shown only once at creation
* Optional restrictions per key: scopes, expiry, IP ranges, sites
* No `fe_sessions` rows, no `Set-Cookie`, no `lastlogin` updates

## 🔥 Installation

```bash
composer require maikschneider/api-key
```

## 🔑 Managing keys

```bash
vendor/bin/typo3 apikey:create --title="CI deploy" --scope=articles:write --expires="+90 days"
vendor/bin/typo3 apikey:list
vendor/bin/typo3 apikey:revoke t3key_KhhNfTRL
```

The key is printed once and cannot be recovered.

## 📙 Documentation

Usage, configuration, the developer guide for consumers and security notes: [docs.typo3.org](https://docs.typo3.org/p/maikschneider/api-key/main/en-us/).

> [!TIP]
> Behind Apache, bearer tokens only reach TYPO3 with `CGIPassAuth On`. The `X-API-Key` header always works.

## 🧑‍💻 Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## ⭐ License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE.md).
