<div align="center">

![Extension icon](Resources/Public/Icons/Extension.svg)

# TYPO3 extension `api_key`

[![Supported PHP versions](https://img.shields.io/packagist/dependency-v/maikschneider/api-key/php?logo=php)](https://packagist.org/packages/maikschneider/api-key)
[![Tests](https://img.shields.io/github/actions/workflow/status/maikschneider/api-key/tests.yml?label=tests&logo=github)](https://github.com/maikschneider/api-key/actions/workflows/tests.yml)
[![CGL](https://img.shields.io/github/actions/workflow/status/maikschneider/api-key/sca.yml?label=cgl&logo=github)](https://github.com/maikschneider/api-key/actions/workflows/sca.yml)
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

## 🧑‍💻 Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## ⭐ License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE.md).
