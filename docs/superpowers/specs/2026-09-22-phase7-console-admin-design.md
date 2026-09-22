# Phase 7 — Console (Inertia + React admin) v1 — design

Джерело вимог: запит користувача в чаті (backlog у `ROADMAP.md`, розділ
"Backlog: продуктові ініціативи"). Користувача не влаштовує UX поточної
Filament-адмінки; хоче самописну реактивну адмінку на Inertia + React.

## Проблема

Уся адмінка зараз — Filament 4 (`app/Filament/*`), 10 ресурсів + 3
dashboard-віджети + одна кастомна "Generate Video" дія на `ListVideos`.
Filament дає мало контролю над UX і композицією екранів. Потрібна
самописна адмінка на стеку, який користувач контролює (React), без
переписування всього одразу — Filament має лишатись робочим, поки нова
адмінка не покриє потрібний функціонал.

## 1. Стек і межі

- **Inertia.js v2** (`inertiajs/inertia-laravel` на бекенді,
  `@inertiajs/react` на фронтенді) — server-driven: контролери рендерять
  `Inertia::render('Videos/Index', $props)`, окремого REST/JSON:API шару
  не будується. Це узгоджується з тим, що фронтенд і бекенд лишаються
  одним Laravel-монолітом (той самий origin, ті самі сесії/CSRF).
- **React 18 + TypeScript**, збірка тим самим Vite (`vite.config.js` вже
  містить `laravel-vite-plugin` + Tailwind v4 plugin — додається другий
  entry point `resources/js/console/app.tsx`, `resources/js/app.js`
  (Blade-welcome-сторінка) не чіпається).
- **shadcn/ui** — компоненти копіюються в репозиторій
  (`resources/js/console/components/ui/*`), а не встановлюються як
  npm-залежність із власним рантаймом — узгоджено з "великою гнучкістю,
  повним контролем над виглядом" з брейнштормінгу.
- **Жодної нової бізнес-логіки.** Console-контролери викликають ті самі
  доменні сервіси/моделі, що й зараз Filament-ресурси й jobs. Це чистий
  UI-шар.

## 2. Роутинг і структура файлів

```
routes/console.php          # Route::middleware('auth')->prefix('console')->group(...)
app/Http/Controllers/Console/
    AuthController.php       # login/logout, без auth-middleware на login-роутах
    DashboardController.php
    VideoController.php      # index, generate (POST), retry (POST)
resources/js/console/
    app.tsx                  # Inertia createInertiaApp() entrypoint
    Pages/
        Login.tsx
        Dashboard.tsx
        Videos/Index.tsx
    Components/
        ui/...                # shadcn примітиви (Button, Table, Dialog, Badge, ...)
        AppLayout.tsx          # спільний сайдбар/шапка для console-сторінок
    lib/utils.ts               # shadcn cn() helper
```

`routes/web.php` підключає `require __DIR__.'/console.php';` одним рядком
(за зразком того, як Laravel типово підключає `auth.php` в Breeze-стеках).
`/admin` (Filament) лишається як є, без жодних змін у цьому спеку.

## 3. Auth

Без Laravel Breeze-скаффолдингу (реєстрація/forgot-password не потрібні —
внутрішній інструмент з одним сідженим юзером,
`User::canAccessPanel()` вже каже "усі юзери — адміни", ролей нема).

- `GET /console/login`, `POST /console/login`, `POST /console/logout` —
  поза `auth`-middleware (крім logout, який вимагає сесії).
- `POST /console/login` — стандартний `Auth::attempt()` + `session()->regenerate()`,
  той самий guard (`web`), що й Filament — вхід під тим самим юзером працює
  в обох адмінках одночасно (спільна сесія/cookie, той самий `users` стіл).
- Редірект неавтентифікованого запиту на `/console/*` → `/console/login`
  (окремий `Authenticate`-мідлвар з `redirectTo`, не Filament-івський).

## 4. v1 екрани

### 4.1 Dashboard (`GET /console`)

Порт трьох Filament-віджетів на один Inertia-проп, без пагінації (як і
зараз):

- **Stats**: "Videos generated today" (`Video::status=Rendered` +
  `whereDate('updated_at', today())`), "Videos published"
  (`Publication::status=Published`), "Failed jobs today" (нотифікації
  поточного юзера типу `PipelineJobFailedNotification` за сьогодні),
  сума `views`/`likes`/`comments` з `VideoMetric::latestPerPublication()`.
- **Best Videos** (топ-5 публікацій за views, той самий join, що в
  `BestVideosWidget`).
- **Top Topics** (топ-5 тем за сумою views, той самий
  `ROW_NUMBER() OVER (...)` запит, що в `TopTopicsWidget` — Postgres-специфічний
  SQL переноситься як є, без спроби зробити його портативним).

`DashboardController::index()` збирає ці три блоки в один `Inertia::render`,
UI — три картки/таблиці на одній сторінці (без Filament widget-абстракції).

### 4.2 Videos (`GET /console/videos`)

Таблиця: Channel, Idea, Title, Status (badge), **Stage** (badge, той самий
колір-мапінг: danger/success/warning, що в `VideosTable::configure()`,
через `Video::currentStageLabel()` — метод не чіпається), Duration,
Created At. Пошук/сортування — мінімальний (client-side фільтр по title,
без server-side `searchable()` Filament-рівня в v1 — це чисто UX-скорочення,
не втрата функціоналу, який хтось активно використовує).

Дії на рядок:
- **Retry** (видима, коли `status=Failed` або `status=Rendering`) —
  `POST /console/videos/{video}/retry`, той самий "яку стадію перезапустити
  за `failed_stage`" мапінг, що зараз у `VideosTable`-екшені `retry`,
  переноситься в `VideoController::retry()` один-в-один (можна винести в
  доменний сервіс `RetryVideoStageService`, якщо в implementation plan це
  виявиться природнішим — деталь реалізації).
- **View** — окрема сторінка `Videos/Show.tsx` з превʼю кожного етапу
  (сценарій, сцени, посилання на озвучку/субтитри/фінальне відео) —
  **опційно для v1**: якщо implementation plan вирішить, що це занадто
  великий обсяг для першої ітерації, View можна відкласти в Phase 7.1 —
  головне, щоб Retry й Generate Video (нижче) працювали.

Проміжні per-stage кнопки з Filament (`generateVoiceover`,
`collectAssets`, `generateSubtitles`, `renderVideo`, `checkQuality`) —
**не переносяться в v1**: у `docs/GUIDE.md` вони вже описані як "про всяк
випадок", пайплайн з Phase "Foundation orchestrator" і так іде автоматично
одним ланцюжком. Якщо вони знадобляться — окремий Retry вже покриває
"перезапустити одну стадію вручну".

### 4.3 Generate Video (кнопка на `Videos/Index.tsx`)

Модалка (shadcn `Dialog`) з вибором каналу (`ContentProject`, той самий
`pluck('name', 'id')`) і полем теми → `POST /console/videos/generate`.
Контролер один-в-один повторює логіку з `ListVideos::generateVideo`:
`GenerateContentIdeaService::generate()` → `ContentIdeaStatus::Approved` →
`GenerateScriptJob::dispatch()`. Помилка (`Throwable`) → `422` з
повідомленням, показаним у формі (Inertia `errors` проп), а не
Filament-нотифікація.

## 5. Тестування

`tests/Feature/Console/*` — той самий підхід, що в решті проєкту
(`RefreshDatabase`, `Queue::fake()`, `Fake*`-провайдери,
`Http::preventStrayRequests()`/`Process::preventStrayProcesses()` з
`tests/TestCase.php` лишаються діючими без змін). Assertions на Inertia-відповіді
через `assertInertia(fn (Assert $page) => $page->component('Videos/Index')
->has('videos', 5))` (офіційний testing-хелпер пакета `inertiajs/inertia-laravel`).

## 6. Що прямо поза межами v1

- Решта 9 Filament-ресурсів (ContentProjects, ContentIdeas, Scripts,
  MediaAssets, Publications, SocialAccounts, VideoScenes, Voiceovers,
  LlmUsageLogs) — лишаються тільки в Filament до наступних ітерацій Phase 7.
- Будь-яка зміна доменної логіки/jobs — цей спек чистий UI-шар.
- Cutover (вимкнення `/admin`, видалення `filament/filament`) — окремий
  крок наприкінці Phase 7, після досягнення паритету, не частина v1.
- Roles/permissions — модель `User` і зараз не має ролей
  (`canAccessPanel(): true` для всіх), console dashboard так само відкритий
  будь-якому автентифікованому юзеру.

## 7. Ризики / відкриті питання для implementation plan

- shadcn/ui копіює компоненти CLI-командою (`npx shadcn@latest add ...`),
  яка тягне мережеві запити під час `composer setup`/розробки — це
  одноразова дія розробника (Claude/користувача) під час імплементації,
  не частина `npm run build`/CI.
- `Video::currentStageLabel()` і колір-мапінг стадій зараз живуть у
  Filament-таблиці (`match (true) { ... }` в `VideosTable::configure()`) —
  цю логіку варто підняти на бекенд (`Video`-модель або presenter), щоб не
  дублювати її окремо у Filament і в Console-контролері. Implementation
  plan вирішує, чи виносити в модель, чи в окремий клас.
