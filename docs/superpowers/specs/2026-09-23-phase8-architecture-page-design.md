# Phase 8 — Architecture/dependency page in Console — design

Джерело вимог: backlog у `ROADMAP.md` ("Phase 8 — Сторінка
архітектури/залежностей в адмінці"), другий пункт з декомпозиції
початкового запиту користувача про документацію проєкту.

## Проблема

`docs/architecture.md` вже містить текстовий опис доменної моделі,
pipeline-ланцюжка jobs і provider-чейнів (включно з двома Mermaid-
діаграмами), написаний у Phase "документація для Claude". Він хороший
довідник для читання markdown, але недоступний зсередини нової Console-
адмінки і статичний — не можна клікнути на вузол і побачити короткий
опис саме цього компонента. Мета Phase 8 — та сама інформація, але як
інтерактивна сторінка всередині `/console`.

## 1. Джерело даних — курований граф, не авто-сканування коду

Розглядався варіант реального статичного аналізу `use`-стейтментів по
`app/`. Відхилено: дало б сотні шумних зв'язків (кожен клас імпортує
`Illuminate\...`), суперечить явній вимозі backlog "коротко і ясно, без
простирадл тексту". Замість цього — той самий курований контент, що вже
є текстом у `docs/architecture.md` §1–3, перенесений у типізовану TS-
структуру:

```ts
// resources/js/console/data/architecture.ts
export interface ArchNode {
    id: string;
    label: string;
    description: string; // 1–2 речення, показується при кліку
    x: number; // 0–100, відсоток по горизонталі всередині canvas вʼю
    y: number; // 0–100, відсоток по вертикалі
}

export interface ArchEdge {
    from: string;
    to: string;
    label?: string;
}

export interface ArchView {
    id: 'modules' | 'pipeline' | 'providers';
    title: string;
    nodes: ArchNode[];
    edges: ArchEdge[];
}

export const ARCHITECTURE_VIEWS: ArchView[];
```

Три вʼю, кожен — пряма візуалізація одного розділу `docs/architecture.md`:

- **`modules`** (§1): `HTTP`, `Filament`, `Jobs` → `Content`/`Video`/
  `Publishing` → `Llm`; `Video` → `Video/Providers`.
- **`pipeline`** (§2): `GenerateScriptJob` → `GenerateScenesJob` → ... →
  `QualityCheckVideoJob` → (manual Approve) → `DispatchDuePublicationsCommand`
  → `PublishVideoJob` → `FakeSocialPublisher`.
- **`providers`** (§3): дві паралельні міні-діаграми — asset-чейн
  (`wikimedia → pixabay → pexels → local`) і LLM provider-резолвінг
  (`override → project.purpose → project.default → config default`).

Кожен `description` — стиснута версія відповідного речення з
`docs/architecture.md`, не новий текст (одне джерело правди по суті,
дублювання формату — .md для читання файлом, .ts для рендеру в UI).

## 2. Рендер — hand-positioned SVG, без нової graph-бібліотеки

Графи маленькі (5–10 вузлів на вʼю) і фіксовані на етапі дизайну — не
потребують силового auto-layout. `ArchitectureDiagram.tsx`:

- Вузли — абсолютно позиціоновані `<button>` (`left/top` у відсотках від
  `x`/`y`) всередині `relative`-контейнера фіксованої висоти; клік
  виділяє вузол (обводка `console-accent`) і показує його `description`
  у картці під канвасом.
- Ребра — один `<svg>` оверлей на весь контейнер, `<line>` між центрами
  вузлів (координати рахуються з тих самих `x`/`y` % у px через
  `getBoundingClientRect` при рендері/resize), стрілка через `<marker>`.
- Це чистий React-компонент без стану, крім "який вузол обрано" —
  ніякого drag/zoom/пан у v1 (граф і так повністю видимий).

## 3. Сторінка й навігація

- `GET /console/architecture`, named route `console.architecture`,
  `ArchitectureController::index()` → `Inertia::render('Architecture')`
  без пропсів (дані статичні, живуть у TS-модулі, не в БД — немає сенсу
  ганяти їх через бекенд).
- `Pages/Architecture.tsx`: `AppLayout title="Architecture"` +
  перемикач-таби (Modules / Pipeline / Providers, той самий візуальний
  патерн, що активний пункт у Sidebar) + `ArchitectureDiagram` для
  обраного вʼю.
- `Sidebar.tsx`: третя іконка (`Network` з lucide-react) на
  `/console/architecture`.

## 4. Тести

`tests/Feature/Console/ArchitectureControllerTest.php` — той самий
патерн, що в інших Console-контролерів: guest redirect на login,
authenticated → `assertInertia(fn ($page) => $page->component('Architecture'))`.
Дані статичні (TS-модуль), тому глибшого backend-тестування контенту
не потрібно — немає що мокати чи перевіряти в БД.

## 5. Що поза межами v1

- Реальний auto-scan класів/namespace — свідомо відхилено (§1).
- Drag/zoom/пан по канвасу — граф і так вміщується на екран.
- Редагування графа через UI — дані хардкодяться в TS-файлі,
  оновлюються комітом поруч зі змінами архітектури (як і зараз
  `docs/architecture.md`).
- Синхронізація "чи не застарів граф" — ручна відповідальність (той
  самий ризик, що вже є в `docs/architecture.md` сьогодні).

## 6. Побічний ефект — лінк з docs/architecture.md

`docs/architecture.md` отримує один рядок на початку: посилання на
`/console/architecture` для інтерактивної версії, щоб той, хто читає
файл, знав про існування сторінки (і навпаки — коментар у
`ArchitectureController` вкаже на `docs/architecture.md` як джерело
контенту).
