# Laravel Config Doctor

[![Run Tests](https://github.com/waqas-yousaf/laravel-config-doctor/actions/workflows/run-tests.yml/badge.svg)](https://github.com/waqas-yousaf/laravel-config-doctor/actions/workflows/run-tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/waqas-yousaf/laravel-config-doctor.svg?style=flat-square)](https://packagist.org/packages/waqas-yousaf/laravel-config-doctor)
[![Total Downloads](https://img.shields.io/packagist/dt/waqas-yousaf/laravel-config-doctor.svg?style=flat-square)](https://packagist.org/packages/waqas-yousaf/laravel-config-doctor)
[![Software License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

Laravel Config Doctor audits your Laravel application's environment variable usage, config safety, and environment integrity before you deploy to production. The current package version is `0.1.0`. It supports Laravel 12 and newer on PHP 8.2+.

## Why Config Doctor?

When Laravel caches its configuration (`php artisan config:cache`), any direct calls to `env()` outside your configuration files will return `null`. This silent behavior often causes production outages when developers accidentally use `env()` in controllers, models, or jobs.

Config Doctor scans your codebase to catch these and other configuration anti-patterns.

## Features

- **AST-like Balanced Parenthesis Parsing**: Accurately parses nested `env()` helper calls like `env('FOO', env('BAR', 'default'))` or complex defaults such as arrays.
- **Custom Helper Scan**: Support for scanning custom env-retrieval wrappers (e.g. `Env::get()`).
- **Secret Detection**: Scans `.env.example` to ensure no active keys/secrets (passwords, tokens, keys) have been accidentally committed.
- **Environment Integrity Verification**: Warns you if keys listed in `.env.example` are missing from your local `.env`.
- **Driver Security Check**: Warns if sync queues, array cache, log mailers, or local file systems are active in production.
- **Config Cache Diffing**: Identifies differences between loaded configuration and the cached configuration file `bootstrap/cache/config.php`.

## Install

Install the package via Composer:

```bash
composer require waqas-yousaf/laravel-config-doctor --dev
```

The service provider is automatically registered.

## Usage

### Audit Configuration and Environment

Run the audit command to analyze your project:

```bash
php artisan config:doctor
```

#### Command Options

- `--ci`: Return a failing exit code if any errors or warnings are found. Useful for pull request checks.
- `--env=`: Audit a specific environment suffix (e.g., `--env=staging` to audit `.env.staging`).
- `--json`: Format the audit findings as a machine-readable JSON object.
- `--generate-env-example`: Generate a fresh `.env.example` from all scanned environment usage in the codebase.

### Compare Configurations

Check for differences between the loaded configuration and your cached configuration:

```bash
php artisan config:diff
```

You can also compare two distinct environment files (secrets are automatically redacted):

```bash
php artisan config:diff --from=local --to=production
```

## Audit Checks and Finding Codes

| Finding Code | Severity | Description |
|---|---|---|
| `env-outside-config` | Error | An environment variable is read outside a `config/` file (will return `null` if config is cached). |
| `missing-env` | Error / Warning | An environment variable is referenced in the code but is missing from the active `.env` file. |
| `committed-secret` | Error | An actual secret (e.g., API key, password) appears to be committed inside the public `.env.example`. |
| `missing-env-from-example` | Warning | An environment variable is defined in `.env.example` but is missing from your active `.env`. |
| `env-without-default` | Warning | An environment variable is used in `config/` but does not define a default value fallback. |
| `boolean-cast` | Warning | A quoted boolean or number is used as a fallback default. Laravel will parse it as a string. |
| `unsafe-driver` | Warning | Unsafe or temporary drivers (like `sync` queue, `array` cache, `log` mail) are active. |
| `unused-env` | Notice | A variable is declared in `.env` but never referenced in scanned PHP files. |

## Configuration

To customize which directories are scanned or how environment variables are analyzed, publish the configuration file:

```bash
php artisan vendor:publish --tag="config-doctor-config"
```

The config file will be available at `config/config-doctor.php`:

```php
return [
    // Directories to recursively scan for env() calls
    'scan_dirs' => [
        'app',
        'bootstrap',
        'config',
        'database',
        'resources',
        'routes',
    ],

    // File extensions to include in the scan
    'extensions' => [
        'php',
    ],

    // Environment keys ignored in the "unused" checks
    'ignore_unused' => [
        'APP_KEY',
        'APP_ENV',
        'APP_DEBUG',
        'APP_URL',
        'APP_NAME',
    ],

    // Custom environment retrieval functions/classes to scan
    'helpers' => [
        'env',
        'Env::get',
    ],
];
```

## GitHub Actions CI Integration

You can integrate Config Doctor into your GitHub Actions workflow to prevent configuration issues from being merged:

```yaml
name: Audit Config Safety

on: [push, pull_request]

jobs:
  config-audit:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout Code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Install Dependencies
        run: composer install --prefer-dist --no-progress

      - name: Run Configuration Audit
        run: php artisan config:doctor --ci
```

## Development

Run tests using PHPUnit:

```bash
composer install
composer test
```

## Credits

- [Waqas Yousaf](https://github.com/waqas-yousaf)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
