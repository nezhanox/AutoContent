# Phase 8 — Architecture Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship an interactive `/console/architecture` page with three tab views (Modules, Pipeline, Providers) that visualize the same content already written in `docs/architecture.md` §1–3, as hand-positioned node/edge diagrams with click-to-see-description.

**Architecture:** One new Console route/controller/page, consistent with the Phase 7 pattern (server-driven Inertia, no new REST layer). The diagram data is a static, curated TypeScript module (not database-driven, not auto-scanned from code) — the controller renders the page with no props. A small custom `ArchitectureDiagram` component draws absolutely-positioned nodes plus an SVG line overlay for edges; no new graph library.

**Tech Stack:** Same as Phase 7's Console (Inertia + React + TypeScript, hand-authored Tailwind components, lucide-react icons — no new dependencies needed for this plan).

**Spec:** `docs/superpowers/specs/2026-09-23-phase8-architecture-page-design.md`

## Global Constraints

- No new REST/API layer — the controller returns `Inertia::render()` only.
- No new npm dependencies (no graph/charting library) — nodes are plain positioned `<button>` elements, edges are a hand-drawn `<svg>` overlay.
- Diagram content must match `docs/architecture.md` §1 (modules), §2 (pipeline), §3 (providers) — this plan is a visualization of existing, already-reviewed documentation content, not new architectural claims.
- No JS test runner exists in this repo — the page is covered by a Laravel Feature test asserting the Inertia response, same as every other Console page.
- Style: `vendor/bin/pint --test` must pass; run `npx tsc --noEmit` and `npm run build` before considering the task done (this repo's final Phase 7 review found real `tsc` errors that nothing had run before — don't repeat that gap).
- Follow the existing Console file/import conventions exactly: pages under `resources/js/console/Pages/`, shared UI under `resources/js/console/components/ui/*.tsx`, shared page-level components under `resources/js/console/components/*.tsx`, controllers under `app/Http/Controllers/Console/*.php`, routes appended to `routes/panel.php` inside the existing `auth` middleware group.

---

### Task 1: Architecture page (Modules / Pipeline / Providers)

**Files:**
- Create: `resources/js/console/data/architecture.ts`
- Create: `resources/js/console/components/ArchitectureDiagram.tsx`
- Create: `resources/js/console/Pages/Architecture.tsx`
- Create: `app/Http/Controllers/Console/ArchitectureController.php`
- Modify: `routes/panel.php`
- Modify: `resources/js/console/components/Sidebar.tsx`
- Modify: `resources/js/console/components/AppLayout.tsx`
- Modify: `docs/architecture.md`
- Test: `tests/Feature/Console/ArchitectureControllerTest.php`

**Interfaces:**
- Consumes: `AppLayout`, `Button`, `Card`/`CardContent` from earlier Console work; `cn()` from `resources/js/console/lib/utils.ts`.
- Produces: `ArchNode`/`ArchEdge`/`ArchView` types and `ARCHITECTURE_VIEWS: ArchView[]` (`resources/js/console/data/architecture.ts`), `ArchitectureDiagram` component (props: `{ view: ArchView }`), named route `console.architecture` (`GET /console/architecture`, `auth`-protected).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ArchitectureControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/console/architecture')->assertRedirect('/console/login');
    }

    public function test_authenticated_user_sees_the_architecture_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/console/architecture')
            ->assertInertia(fn (Assert $page) => $page->component('Architecture'));
    }
}
```

Save to `tests/Feature/Console/ArchitectureControllerTest.php`.

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php artisan test --filter=ArchitectureControllerTest`
Expected: FAIL (route `console.architecture` / component `Architecture` don't exist yet — 404).

- [ ] **Step 3: Create the ArchitectureController**

```php
<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the interactive counterpart to docs/architecture.md §1-3.
 * The diagram data lives in resources/js/console/data/architecture.ts,
 * not here — it's static, curated content, not derived from the DB.
 */
class ArchitectureController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Architecture');
    }
}
```

Save to `app/Http/Controllers/Console/ArchitectureController.php`.

- [ ] **Step 4: Wire the route**

Modify `routes/panel.php` — add the import:

```php
use App\Http\Controllers\Console\ArchitectureController;
```

and inside the `auth` group, after the `console.videos.retry` route:

```php
        Route::get('architecture', [ArchitectureController::class, 'index'])->name('console.architecture');
```

- [ ] **Step 5: Run the test to confirm the backend passes**

Run: `php artisan test --filter=ArchitectureControllerTest`
Expected: `test_guest_is_redirected_to_login` PASSes. `test_authenticated_user_sees_the_architecture_page` still FAILs (Inertia can't resolve a `Pages/Architecture.tsx` component yet). Continue to the frontend steps.

- [ ] **Step 6: Create the diagram data module**

```ts
export interface ArchNode {
    id: string;
    label: string;
    description: string;
    /** Percentage position (0-100) within the diagram canvas. */
    x: number;
    y: number;
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

const modules: ArchView = {
    id: 'modules',
    title: 'Modules',
    nodes: [
        { id: 'http', label: 'app/Http', description: 'Console-контролери — тонкий шар над доменними сервісами.', x: 12, y: 10 },
        { id: 'filament', label: 'app/Filament', description: 'Стара адмінка (Resources/Pages/Widgets) — теж тонкий UI-шар.', x: 50, y: 10 },
        { id: 'jobs', label: 'app/Jobs', description: 'Ланцюжок pipeline-jobs — кожен диспатчить наступний сам після успіху.', x: 88, y: 10 },
        { id: 'domain', label: 'app/Domain', description: 'Доменний код, організований по bounded contexts, а не по MVC-шарах.', x: 50, y: 34 },
        { id: 'content', label: 'Content', description: 'Генерація ідей і сценаріїв (GenerateScriptService та ін.).', x: 15, y: 60 },
        { id: 'video', label: 'Video', description: 'Найбільший контекст: сцени, озвучка, асети, субтитри, рендер, quality-check.', x: 50, y: 60 },
        { id: 'publishing', label: 'Publishing', description: 'Публікація відео в соцмережі — зараз FakeSocialPublisher (заглушка).', x: 85, y: 60 },
        { id: 'llm', label: 'Llm', description: 'Абстракція над LLM-провайдерами: LlmManager, LlmProviderInterface.', x: 38, y: 88 },
        { id: 'video_providers', label: 'Video/Providers', description: 'Зовнішні інтеграції Video-контексту: Pexels, Pixabay, Wikimedia, ffmpeg, Whisper, ElevenLabs.', x: 78, y: 88 },
    ],
    edges: [
        { from: 'http', to: 'domain' },
        { from: 'filament', to: 'domain' },
        { from: 'jobs', to: 'domain' },
        { from: 'domain', to: 'content' },
        { from: 'domain', to: 'video' },
        { from: 'domain', to: 'publishing' },
        { from: 'content', to: 'llm' },
        { from: 'video', to: 'llm' },
        { from: 'publishing', to: 'llm' },
        { from: 'video', to: 'video_providers' },
    ],
};

const pipeline: ArchView = {
    id: 'pipeline',
    title: 'Pipeline',
    nodes: [
        { id: 'script', label: 'GenerateScriptJob', description: 'ContentIdea → Script через LLM.', x: 8, y: 18 },
        { id: 'scenes', label: 'GenerateScenesJob', description: 'Script → Video + сцени (VideoScene[]).', x: 24.8, y: 18 },
        { id: 'voiceover', label: 'GenerateVoiceoverJob', description: 'Озвучка через ElevenLabs (чи Fake у тестах).', x: 41.6, y: 18 },
        { id: 'assets', label: 'CollectVideoAssetsJob', description: 'Підбір MediaAsset для кожної сцени через asset-чейн.', x: 58.4, y: 18 },
        { id: 'subtitles', label: 'GenerateSubtitlesJob', description: 'Whisper → субтитри.', x: 75.2, y: 18 },
        { id: 'render', label: 'RenderVideoJob', description: 'ffmpeg → фінальний file_path.', x: 92, y: 18 },
        { id: 'quality', label: 'QualityCheckVideoJob', description: 'Автоматична перевірка якості рендеру.', x: 8, y: 68 },
        { id: 'approve', label: 'Manual Approve', description: 'Ручне підтвердження в адмінці — єдиний неавтоматичний крок ланцюга.', x: 29, y: 68 },
        { id: 'dispatch_due', label: 'DispatchDuePublicationsCommand', description: 'Планувальник, щохвилини перевіряє scheduled_at.', x: 50, y: 68 },
        { id: 'publish', label: 'PublishVideoJob', description: 'Публікація в соцмережу через Publishing-провайдер.', x: 71, y: 68 },
        { id: 'publisher', label: 'FakeSocialPublisher', description: 'Заглушка — реальної інтеграції з соцмережами ще нема.', x: 92, y: 68 },
    ],
    edges: [
        { from: 'script', to: 'scenes', label: 'idea → script' },
        { from: 'scenes', to: 'voiceover', label: 'script → сцени' },
        { from: 'voiceover', to: 'assets', label: 'озвучка' },
        { from: 'assets', to: 'subtitles', label: 'асети' },
        { from: 'subtitles', to: 'render', label: 'субтитри' },
        { from: 'render', to: 'quality', label: 'рендер' },
        { from: 'quality', to: 'approve', label: 'quality report' },
        { from: 'approve', to: 'dispatch_due', label: 'approved' },
        { from: 'dispatch_due', to: 'publish', label: 'scheduled_at <= now' },
        { from: 'publish', to: 'publisher' },
    ],
};

const providers: ArchView = {
    id: 'providers',
    title: 'Providers',
    nodes: [
        { id: 'wikimedia', label: 'wikimedia', description: 'Класичне мистецтво для історичних сцен; порожньо — падає далі по чейну.', x: 12, y: 25 },
        { id: 'pixabay', label: 'pixabay', description: 'Комерційний stock, другий у черзі asset-чейну.', x: 38, y: 25 },
        { id: 'pexels', label: 'pexels', description: 'Комерційний stock, третій у черзі asset-чейну.', x: 64, y: 25 },
        { id: 'local', label: 'local', description: 'Локальна медіатека — останній фолбек, завжди щось повертає.', x: 90, y: 25 },
        { id: 'override', label: 'providerOverride', description: 'Явний provider/model, переданий у виклик — найвищий пріоритет.', x: 12, y: 70 },
        { id: 'purpose_setting', label: 'project.settings.ai.<purpose>', description: "ContentProject.settings.ai.<purpose>, напр. 'script'.", x: 38, y: 70 },
        { id: 'default_setting', label: 'project.settings.ai.default', description: 'Дефолт каналу, якщо purpose-специфічного немає.', x: 64, y: 70 },
        { id: 'config_default', label: "config('llm.default_provider')", description: 'Глобальний дефолт застосунку — останній фолбек.', x: 90, y: 70 },
    ],
    edges: [
        { from: 'wikimedia', to: 'pixabay', label: 'no hit' },
        { from: 'pixabay', to: 'pexels', label: 'no hit' },
        { from: 'pexels', to: 'local', label: 'no hit' },
        { from: 'override', to: 'purpose_setting', label: 'not set' },
        { from: 'purpose_setting', to: 'default_setting', label: 'not set' },
        { from: 'default_setting', to: 'config_default', label: 'not set' },
    ],
};

export const ARCHITECTURE_VIEWS: ArchView[] = [modules, pipeline, providers];
```

Save to `resources/js/console/data/architecture.ts`.

- [ ] **Step 7: Create the diagram renderer**

```tsx
import { useState } from 'react';
import { cn } from '../lib/utils';
import type { ArchView } from '../data/architecture';
import { Card, CardContent } from './ui/card';

export function ArchitectureDiagram({ view }: { view: ArchView }) {
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const selected = view.nodes.find((node) => node.id === selectedId) ?? null;

    return (
        <div>
            <div className="relative h-[420px] w-full overflow-hidden rounded-2xl border border-console-border bg-console-surface">
                <svg className="absolute inset-0 h-full w-full" aria-hidden="true">
                    <defs>
                        <marker id={`arrow-${view.id}`} viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                            <path d="M 0 0 L 10 5 L 0 10 z" fill="#c7c9e0" />
                        </marker>
                    </defs>
                    {view.edges.map((edge) => {
                        const from = view.nodes.find((node) => node.id === edge.from);
                        const to = view.nodes.find((node) => node.id === edge.to);
                        if (!from || !to) return null;

                        return (
                            <line
                                key={`${edge.from}-${edge.to}`}
                                x1={`${from.x}%`}
                                y1={`${from.y}%`}
                                x2={`${to.x}%`}
                                y2={`${to.y}%`}
                                stroke="#c7c9e0"
                                strokeWidth={1.5}
                                markerEnd={`url(#arrow-${view.id})`}
                            />
                        );
                    })}
                </svg>

                {view.nodes.map((node) => (
                    <button
                        key={node.id}
                        type="button"
                        onClick={() => setSelectedId(node.id)}
                        style={{ left: `${node.x}%`, top: `${node.y}%` }}
                        className={cn(
                            '-translate-x-1/2 -translate-y-1/2 absolute rounded-lg border bg-white px-3 py-1.5 text-xs font-medium shadow-sm transition-colors',
                            selectedId === node.id
                                ? 'border-console-accent text-console-accent'
                                : 'border-console-border text-console-text hover:border-console-accent/50',
                        )}
                    >
                        {node.label}
                    </button>
                ))}
            </div>

            <Card className="mt-4">
                <CardContent className="text-sm text-console-text">
                    {selected ? (
                        <>
                            <p className="font-semibold text-console-text">{selected.label}</p>
                            <p className="mt-1 text-console-text-muted">{selected.description}</p>
                        </>
                    ) : (
                        <p className="text-console-text-muted">Клікни на вузол, щоб побачити опис.</p>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
```

Save to `resources/js/console/components/ArchitectureDiagram.tsx`.

- [ ] **Step 8: Create the Architecture page**

```tsx
import { useState } from 'react';
import { AppLayout } from '../components/AppLayout';
import { ArchitectureDiagram } from '../components/ArchitectureDiagram';
import { Button } from '../components/ui/button';
import { ARCHITECTURE_VIEWS } from '../data/architecture';

export default function Architecture() {
    const [activeId, setActiveId] = useState(ARCHITECTURE_VIEWS[0].id);
    const active = ARCHITECTURE_VIEWS.find((view) => view.id === activeId) ?? ARCHITECTURE_VIEWS[0];

    return (
        <AppLayout title="Architecture">
            <div className="mb-4 flex gap-2">
                {ARCHITECTURE_VIEWS.map((view) => (
                    <Button
                        key={view.id}
                        variant={view.id === activeId ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => setActiveId(view.id)}
                    >
                        {view.title}
                    </Button>
                ))}
            </div>

            <ArchitectureDiagram view={active} />
        </AppLayout>
    );
}
```

Save to `resources/js/console/Pages/Architecture.tsx`.

- [ ] **Step 9: Add the third sidebar nav item and fix active-state matching**

Modify `resources/js/console/components/Sidebar.tsx` — add the import and nav entry:

```tsx
import { Clapperboard, LayoutDashboard, Network } from 'lucide-react';
```

```tsx
const NAV_ITEMS = [
    { href: '/console', label: 'Dashboard', icon: LayoutDashboard },
    { href: '/console/videos', label: 'Videos', icon: Clapperboard },
    { href: '/console/architecture', label: 'Architecture', icon: Network },
];
```

Replace the active-state check inside the `.map()`:

```tsx
const active = item.href === '/console' ? current === '/console' : current.startsWith(item.href);
```

(replaces the old `const active = current === item.href;`)

Modify `resources/js/console/components/AppLayout.tsx` — replace the two-way ternary passed to `Sidebar` with the raw current URL, since `Sidebar` now does its own prefix matching:

```tsx
<Sidebar current={url} />
```

(replaces `<Sidebar current={url === '/console' ? '/console' : '/console/videos'} />`)

- [ ] **Step 10: Link docs/architecture.md to the new page**

Modify `docs/architecture.md` — add this line right after the first paragraph (after "Для тестів — `docs/testing.md`."):

```markdown

Інтерактивна версія розділів 1–3 нижче: `/console/architecture` (Modules/Pipeline/Providers).
```

- [ ] **Step 11: Run the tests to confirm they pass**

Run: `php artisan test --filter=ArchitectureControllerTest`
Expected: PASS (2 tests).

- [ ] **Step 12: Typecheck, build, full suite, style**

Run: `npx tsc --noEmit && npm run build && php artisan test && vendor/bin/pint --test`
Expected: all clean/PASS. (`tsc` and `npm run build` are not optional here — the Phase 7 final review found real type errors that no one had checked before; check them yourself before reporting done.)

- [ ] **Step 13: Commit**

```bash
git add resources/js/console/data/architecture.ts \
  resources/js/console/components/ArchitectureDiagram.tsx \
  resources/js/console/Pages/Architecture.tsx \
  app/Http/Controllers/Console/ArchitectureController.php \
  routes/panel.php resources/js/console/components/Sidebar.tsx \
  resources/js/console/components/AppLayout.tsx docs/architecture.md \
  tests/Feature/Console/ArchitectureControllerTest.php
git commit -m "feat(console): add interactive architecture/dependency page"
```

---

## After this plan

Update `ROADMAP.md` Phase 8 status to `[x]` with the date and a one-paragraph summary (files touched, what it covers), the same way Phase 7 was closed out. Manual smoke check before marking done: visit `/console/architecture`, click through all three tabs, click a few nodes on each and confirm the description card updates, resize the browser window to confirm the SVG lines still track the percentage-positioned nodes correctly at different widths.
