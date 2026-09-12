# AI Content Factory

Laravel-застосунок для автоматизованого виробництва faceless short-form контенту
та публікації в TikTok, YouTube Shorts, Instagram Reels та X.

- Вимоги: [`TechnicalTask.md`](TechnicalTask.md)
- План виконання по фазах: [`ROADMAP.md`](ROADMAP.md)
- Спеки та implementation plans: `docs/superpowers/`

## Локальний запуск

```bash
composer install
cp .env.example .env
php artisan key:generate
docker compose up -d --build
```

Застосунок доступний на http://localhost:8080, health-check — http://localhost:8080/up.
Адмін-панель (Filament) — http://localhost:8080/admin.

## Тести

`php artisan test` потребує доступного Postgres за параметрами з `phpunit.xml`:
хост `127.0.0.1`, порт `5432`, база `autocontent_testing`, користувач/пароль
`autocontent`/`autocontent`. Перед запуском тестів піднімайте
`docker compose up -d postgres redis` (за потреби перевизначте порти через
`POSTGRES_HOST_PORT`/`REDIS_HOST_PORT`, якщо порти 5432/6379 вже зайняті на вашій
машині — тоді передайте відповідний `DB_PORT` і в оточення тестів).

```bash
docker compose up -d postgres redis
php artisan test
vendor/bin/pint --test
```
