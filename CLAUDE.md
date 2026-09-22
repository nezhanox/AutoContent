# AutoContent — вхідний файл для Claude

Laravel 12 / PHP 8.4 застосунок, що генерує faceless короткі відео (ідея →
сценарій → сцени → озвучка → b-roll → субтитри → рендер → перевірка якості)
і публікує їх у TikTok/YouTube Shorts/Instagram Reels/X. Адмінка: Filament 4
(`/admin`, всі 10 ресурсів) + нова самописна Inertia+React консоль
(`/console`, v1: Login, Dashboard, Videos-лист, Generate Video, Retry —
див. `docs/superpowers/specs/2026-09-22-phase7-console-admin-design.md`).
Обидві живуть паралельно, поки Console не наздожене паритет.

Деталі, які не варто тримати тут (щоб файл лишався коротким):

- **Архітектура, доменна модель, pipeline, provider-чейни** → `docs/architecture.md`
- **Як писати й ганяти тести, конвенції** → `docs/testing.md`
- **Користувацький гайд (як згенерувати відео через UI)** → `docs/GUIDE.md`
- **Вимоги/фази/статус проєкту** → `TechnicalTask.md`, `ROADMAP.md`
- **Спеки та implementation-плани по фазах** → `docs/superpowers/specs/`, `docs/superpowers/plans/`

## Швидкий старт

```bash
docker compose up -d --build
```

Застосунок: http://localhost:8080, health: http://localhost:8080/up,
Filament-адмінка: http://localhost:8080/admin, нова консоль:
http://localhost:8080/console/login (сідер: `admin@autocontent.test` /
`password`, спільний для обох адмінок). Консоль вимагає зібраних
фронтенд-асетів (`npm run build`, бо `resources/views/console.blade.php`
не має dev-фолбеку) — `composer setup` це вже робить, для ручного запуску
після pull — `npm install && npm run build`.

Черга **обов'язково** перезапускати після зміни коду job'ів/сервісів —
Laravel worker не перезавантажує PHP-класи на льоту:

```bash
php artisan queue:work --queue=render,whisper,default --tries=3 --timeout=300
```

## Ключові конвенції

- **Доменний код живе в `app/Domain/{Content,Llm,Publishing,Video}/`**, не в
  `app/Http` чи `app/Filament` — контролери/Filament-ресурси лише тонкий шар
  над доменними сервісами.
- **Кожен зовнішній інтеграційний клас має `Fake*`-двійник** (`FakeLlmProvider`,
  `FakeAssetProvider`, `FakeTtsProvider`, `FakeSocialPublisher` тощо) для
  тестів — реальні HTTP/CLI виклики в тестах заборонені (`docs/testing.md`).
- **Pipeline — це ланцюжок Jobs**, не оркестратор: кожен job у `handle()` сам
  диспатчить наступний після успіху (`GenerateScriptJob::dispatch()` в кінці
  `GenerateScenesJob` тощо). Немає центрального класу "VideoPipeline".
- **Провайдер-чейни конфігуруються через `config/*.php`** (наприклад
  `config('assets.chain')`), біндяться в `*ServiceProvider::register()`, і
  обгортаються `Chained*Provider`, що йде по списку, поки хтось не поверне
  результат.
- Стиль коду — `vendor/bin/pint --test` (без кастомного `pint.json`, дефолти Laravel).

Перед будь-якою нетривіальною зміною — прочитай `docs/architecture.md`, щоб
не зламати ланцюжок jobs або provider-чейн.
