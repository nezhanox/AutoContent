# Тестування

## Як запустити

```bash
cp .env.testing.example .env.testing   # один раз
docker compose up -d postgres redis
php artisan test
vendor/bin/pint --test
```

`.env.testing` задає лише хост/порт Postgres (щоб не конфліктувати з
локальним 5432); решта параметрів (`DB_DATABASE=autocontent_testing`,
креденшели) — прямо в `phpunit.xml`.

## Структура

Класичний PHPUnit (не Pest): `tests/Unit/*` — доменна логіка без БД/черги,
`tests/Feature/*` — з БД (`RefreshDatabase`), дзеркалить `app/`:
`Feature/Jobs`, `Feature/Domain`, `Feature/Models`, `Feature/Console`,
`Feature/Filament`. Назви методів — `test_it_<опис>()`.

## Жорстке правило: жодних живих зовнішніх викликів

`tests/TestCase.php` викликає в `setUp()`:

```php
Process::preventStrayProcesses();
Http::preventStrayRequests();
```

Це **кидає виняток**, якщо тест (напряму чи транзитивно через доменний код)
робить реальний HTTP-запит або запускає зовнішній процес (ffmpeg, whisper
CLI) без явного фейка. Тому:

- Для HTTP-інтеграцій (LLM API, Pexels/Pixabay/Wikimedia, ElevenLabs) —
  `Http::fake([...])` з конкретним URL-патерном, або підміняй провайдер на
  `Fake*Provider` через `$this->app->bind(...)`.
- Для CLI (ffmpeg, whisper) — `Process::fake([...])`.
- Job-тести майже завжди роблять `Queue::fake()` в `setUp()`, щоб наступний
  job у ланцюжку (`GenerateScenesJob::dispatch()` і т.п.) не виконувався
  реально — перевіряй сам факт диспатчу через `Queue::assertPushed(...)`,
  а не побічні ефекти наступного job.

Якщо тест "зненацька" падає з `PreventedStrayRequest`/`PreventedStrayProcess` —
це сигнал, що десь пропущений фейк, а не привід ставити `preventStrayRequests`
під сумнів.

## Провайдери для тестів

Кожен зовнішній інтеграційний клас має `Fake*`-двійник у тому ж неймспейсі
(`App\Domain\Video\Providers\Fake*`, `App\Domain\Llm\Providers\FakeLlmProvider`,
`App\Domain\Publishing\Providers\FakeSocialPublisher`). Для тестів, які не
перевіряють саму інтеграцію, підміняй реальний провайдер на `Fake*` через
контейнер, а не через `Http::fake`/`Process::fake` — це коротше й стабільніше.

## Стиль коду

`vendor/bin/pint --test` (дефолтні правила Laravel, кастомного `pint.json`
нема). Перед комітом — `vendor/bin/pint` без `--test`, щоб автоформатувати.
