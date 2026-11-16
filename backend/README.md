# Lexora Workspace — Laravel API

JSON API for the Amanet legal matter workspace (Laravel 12, Sanctum SPA auth, PostgreSQL).
Setup, demo login and testing instructions: see the [root README](../README.md).

```bash
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve --host=localhost --port=8000
php artisan test
```
