# Phase 0 — Bootstrap: design spec

Джерело: `TechnicalTask.md` (розділи 2, 22, 25), `ROADMAP.md` (Phase 0).

## Мета

Підняти порожній, але робочий скелет проєкту: Laravel-застосунок, Docker-оточення,
базова структура директорій під доменну логіку. Без моделей, без бізнес-логіки, без
AI-інтеграцій — це предмет Phase 1+.

## Скоуп

Входить:

* Ініціалізація Laravel-застосунку (PHP 8.4+, Laravel 12+) у корені репозиторію.
* Docker Compose із сервісами: `app`, `nginx`, `postgres`, `redis`, `worker`, `horizon`
  (розділ 22 ТЗ). FFmpeg встановлений в образі `worker`.
* `.env.example` з усіма ключами з розділу 18 ТЗ (`OPENAI_API_KEY`, `ANTHROPIC_API_KEY`,
  `ELEVENLABS_API_KEY`, `AWS_*` та стандартні Laravel/DB/Redis змінні), без значень.
* Базова структура `app/Domain/` (порожні `.gitkeep`/namespace-заглушки під майбутні
  `Content`, `Video`, `Publishing`, `Analytics`, `Llm` модулі) — щоб Phase 1 одразу
  розкладав файли за конвенцією, без пере-структурування.
* `README.md` з коротким описом проєкту, посиланням на `TechnicalTask.md`/`ROADMAP.md`
  і командами запуску (`docker compose up`, `php artisan test`).
* Перевірка, що `php artisan test`, `php artisan pint` проходять на чистому Laravel-скелеті.

Не входить (свідомо відкладено):

* Будь-які моделі/міграції/Filament (Phase 1).
* Python worker/контейнер (створюється тільки коли реально знадобиться — розділ 22 ТЗ).
* CI pipeline — не вимагався ТЗ, не додаємо без потреби.

## Рішення

* **PHP/Laravel версія**: PHP 8.4, Laravel 12 (найновіша на момент старту) — точну версію
  фіксує `composer.json` після встановлення.
* **Структура Docker**: окремі `Dockerfile` для `php`/`worker` під `docker/php/` і
  `docker/worker/` (розділ 22 ТЗ передбачає саме такий поділ), `nginx` — офіційний образ з
  конфігом у `docker/nginx/`.
* **DB/Queue у local dev**: Postgres і Redis лише через Docker Compose, не системні
  інсталяції — щоб оточення було відтворюване.
* **Структура `app/Domain/`**: за розділом 5 ТЗ (`app/Domain/Content/Services/...`),
  на цьому етапі — тільки директорії, без класів.

## Acceptance criteria (DoD)

* `docker compose up` піднімає всі 6 сервісів без помилок.
* `/up` (Laravel health route) повертає 200 через `nginx`.
* `docker compose exec worker ffmpeg -version` відпрацьовує.
* `php artisan test` і `php artisan pint --test` проходять чисто на чистому проєкті.
* `.env.example` містить усі ключі з розділу 18 ТЗ.
* Жодних секретів у git.
