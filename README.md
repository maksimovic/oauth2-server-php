### `maksimovic/oauth2-server-php`

[![Total Downloads](https://poser.pugx.org/maksimovic/oauth2-server-php/downloads)](https://packagist.org/packages/maksimovic/oauth2-server-php)
[![Latest Stable Version](https://poser.pugx.org/maksimovic/oauth2-server-php/v/stable)](https://packagist.org/packages/maksimovic/oauth2-server-php)

A maintained fork of [`bshaffer/oauth2-server-php`](https://github.com/bshaffer/oauth2-server-php) — a standards-compliant OAuth 2.0 and OpenID Connect server library for PHP. It provides the server side of grants (authorization code, client credentials, refresh token, JWT bearer, …), token / scope / user-credentials handling, and pluggable storage backends (PDO, Redis, MongoDB, DynamoDB, Cassandra, Couchbase, in-memory).

### Why this fork

Upstream has been effectively dormant; open PRs sit for months without triage. This fork brings the library up to current tooling and picks up community fixes:

- **PHP 8.1–8.5** support
- **PHPUnit 10** test suite, **PHPStan** level-max static analysis
- Couchbase SDK 4.x, MongoDB SDK 2.x, DynamoDB null-safety, Cassandra driver refresh
- Dropped legacy Mongo driver
- Docker-based CI matrix across PHP 8.1–8.5 with all storage backends

### Drop-in replacement

`composer.json` declares `"replace": {"bshaffer/oauth2-server-php": "*"}`, so any consumer still requiring `bshaffer/oauth2-server-php` can pull this fork without changing their constraint:

```json
{
  "require": {
    "maksimovic/oauth2-server-php": "^2.0"
  }
}
```

### Documentation

The [upstream documentation](https://bshaffer.github.io/oauth2-server-php-docs/) still applies — public API and namespaces are unchanged.
