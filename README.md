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

```bash
php artisan test
vendor/bin/pint --test
```
