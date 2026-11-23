# Development setup

Requirements: Docker with Compose. PHP and Composer run inside containers.

```sh
export KASMIN_UID=$(id -u) KASMIN_GID=$(id -g)
cp .env.example .env
docker compose -f compose.dev.yaml up -d db mail
docker compose -f compose.dev.yaml run --rm app composer install
docker compose -f compose.dev.yaml run --rm app php artisan key:generate
docker compose -f compose.dev.yaml run --rm app php artisan migrate
docker compose -f compose.dev.yaml up -d app
```

- Application: http://127.0.0.1:8000
- Mailpit (catches all mails): http://127.0.0.1:8025
- Database: MariaDB 11 (service `db`, database/user/password `kasmin`)

## Checks before pushing

```sh
docker compose -f compose.dev.yaml run --rm app vendor/bin/pint --test        # code style
docker compose -f compose.dev.yaml run --rm app vendor/bin/phpstan analyse    # static analysis (level 8)
docker compose -f compose.dev.yaml run --rm app php artisan test              # tests
docker compose -f compose.dev.yaml run --rm app composer audit                # known vulnerabilities
```
