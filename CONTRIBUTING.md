# Contributing to api-key

Thank you for considering contributing to api-key! We welcome contributions of all kinds.

## Local Development Setup

This extension uses DDEV for local development.

```bash
ddev start
ddev composer install
ddev init-typo3
```

## Running Tests

Tests use the TYPO3 Testing Framework and PHPUnit.

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
ddev exec vendor/bin/phpunit --testsuite Functional
```

## Code Quality Standards

Please run all checks before submitting a pull request.

```bash
ddev composer sca
```

Individual tools: `php:fixer`, `php:stan`, `php:lint`, `yaml:lint`, `editorconfig:lint`, `language:lint`, `composer:normalize`.

## Commits and Pull Requests

* [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) (`feat:`, `fix:`, `docs:`, …)
* One pull request per issue, body `Resolves #N.`
* Add an entry to the `[Unreleased]` section of `CHANGELOG.md`

## License

By contributing, you agree that your contributions will be licensed under GPL-2.0-or-later.
