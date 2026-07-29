# Laravel Config Doctor

Laravel Config Doctor audits environment usage and configuration safety before deployment. It supports Laravel 12 and newer on PHP 8.2+.

## Install

```bash
composer require laravel-config-doctor/laravel-config-doctor
```

The service provider is auto-discovered. Run:

```bash
php artisan config:doctor
php artisan config:doctor --ci
php artisan config:doctor --json
php artisan config:diff
```

The audit detects `env()` outside `config/`, missing variables, variables without defaults, unused values in `.env`, unsafe queue/cache/mail/filesystem drivers, and differences between the loaded configuration and `bootstrap/cache/config.php`. Secret-looking configuration keys are redacted in diff output. `--ci` returns a failing exit code for errors and warnings.

## Development

```bash
composer install
composer test
```
## Credits

- [Waqas Yousaf](https://github.com/waqas-yousaf)

## License

MIT License.
