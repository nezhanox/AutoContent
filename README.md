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

`php artisan test` потребує доступного Postgres. Хост/порт задаються через
`.env.testing` (скопіюйте `cp .env.testing.example .env.testing` і за потреби
відредагуйте `DB_PORT`, якщо 5432 вже зайнятий на вашій машині — `phpunit.xml`
більше не хардкодить ці значення). Решта параметрів (`DB_DATABASE=autocontent_testing`,
`DB_USERNAME`/`DB_PASSWORD=autocontent`) задані в `phpunit.xml`. Перед запуском тестів
піднімайте `docker compose up -d postgres redis` (за потреби перевизначте порти через
`POSTGRES_HOST_PORT`/`REDIS_HOST_PORT`, якщо порти 5432/6379 вже зайняті).

```bash
cp .env.testing.example .env.testing
docker compose up -d postgres redis
php artisan test
vendor/bin/pint --test
```
