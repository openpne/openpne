# OpenPNE

OpenPNE is an open-source social networking platform you can self-host. It powers community SNS sites — invitation-only or open registration — with member profiles, diaries, communities, direct messaging, friend relationships, timeline, and more.

This repository is a Laravel 13 reimplementation succeeding the previous version (symfony 1.4 based, see [OpenPNE3](https://github.com/openpne/OpenPNE3)).

## Requirements

- PHP 8.3+
- Composer 2.x

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

## Development

```bash
php artisan serve         # http://localhost:8000
php artisan test
vendor/bin/pint --test    # lint check (drop --test to auto-format)
```

## License

Apache License 2.0 — see [LICENSE](LICENSE).
