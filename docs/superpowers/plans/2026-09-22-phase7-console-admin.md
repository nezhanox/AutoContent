# Phase 7 — Console (Inertia + React admin) v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a working v1 of a self-built admin ("Console") on Inertia.js v2 + React + TypeScript, running alongside the existing Filament admin, covering Login, Dashboard, Videos list with Retry, and the "Generate Video" flow.

**Architecture:** Server-driven Inertia (no new REST/API layer) — `App\Http\Controllers\Console\*` controllers call the same domain services/jobs Filament already uses and render `Inertia::render()` responses; React pages consume typed props. UI components are hand-authored shadcn-style primitives (cva + Tailwind), not the shadcn CLI/npm package, to keep the dependency surface small and fully in-repo.

**Tech Stack:** Laravel 12 / PHP 8.4 (existing), `inertiajs/inertia-laravel` (new), React 18 + TypeScript, existing Vite 7 + `laravel-vite-plugin` + Tailwind v4, `@vitejs/plugin-react`, `class-variance-authority` + `clsx` + `tailwind-merge`.

**Spec:** `docs/superpowers/specs/2026-09-22-phase7-console-admin-design.md`

## Global Constraints

- No new REST/API layer — every Console controller action returns an `Inertia::render()` or a redirect, exactly like today's Filament pages call domain services directly.
- No domain/business-logic changes. Console controllers replicate existing Filament action logic (same services, same jobs, same status transitions) — this plan is a UI shell.
- Filament (`/admin`) keeps working unmodified, with exactly one exception: Task 3 moves a `match(true)` color closure out of `VideosTable` into a new `Video::stageBadgeColor()` model method so Console and Filament share one source of truth (spec §7 flags this as an open question — this plan resolves it as "move to the model").
- The new routes file is `routes/panel.php`, **not** `routes/console.php` — Laravel's `bootstrap/app.php` already reserves `routes/console.php` for Artisan closure commands (`commands: __DIR__.'/../routes/console.php'`); reusing that filename for HTTP routes would silently break both.
- Tests: `RefreshDatabase`, `Queue::fake()` for anything that touches jobs, `Fake*` provider bindings for LLM calls — `tests/TestCase.php` already calls `Http::preventStrayRequests()` / `Process::preventStrayProcesses()`, so any accidental live call fails loudly.
- No JS test runner exists in this repo (no Vitest/RTL in `package.json`) and adding one is out of scope — React pages are covered indirectly through Laravel Feature tests asserting Inertia responses (`assertInertia()`, shipped by `inertiajs/inertia-laravel`).
- Style: `vendor/bin/pint --test` must pass before every commit (no custom `pint.json`, Laravel defaults).
- No new migrations are needed for this plan.
- Out of scope (confirmed against spec §6): the other 9 Filament resources, a Video "View" detail page, roles/permissions, and the eventual `/admin` cutover.

---

### Task 1: Toolchain, Inertia bootstrap, Console auth (login/logout)

**Files:**
- Modify: `composer.json` (via `composer require`)
- Modify: `package.json` (via `npm install`)
- Modify: `vite.config.js`
- Create: `tsconfig.json`
- Create: `resources/views/console.blade.php`
- Create: `app/Http/Middleware/HandleInertiaRequests.php`
- Create: `app/Http/Controllers/Console/AuthController.php`
- Create: `routes/panel.php`
- Modify: `routes/web.php`
- Create: `resources/js/console/lib/utils.ts`
- Create: `resources/js/console/components/ui/button.tsx`
- Create: `resources/js/console/components/ui/input.tsx`
- Create: `resources/js/console/components/ui/label.tsx`
- Create: `resources/js/console/Pages/Login.tsx`
- Create: `resources/js/console/app.tsx`
- Test: `tests/Feature/Console/AuthControllerTest.php`

**Interfaces:**
- Produces: `cn(...inputs: ClassValue[]): string` (`resources/js/console/lib/utils.ts`), `Button`/`Input`/`Label` React components (`resources/js/console/components/ui/*`), named route `login` (`GET /console/login`), unnamed `POST /console/login`, named route `console.logout` (`POST /console/logout`, `auth`-protected), Blade root view `console` used by all later Inertia pages.

- [ ] **Step 1: Install backend and frontend dependencies**

```bash
composer require inertiajs/inertia-laravel
npm install --save-dev react react-dom @inertiajs/react @vitejs/plugin-react typescript @types/react @types/react-dom class-variance-authority clsx tailwind-merge
```

- [ ] **Step 2: Wire Vite for a second TSX entrypoint**

Modify `vite.config.js`:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/console/app.tsx'],
            refresh: true,
        }),
        tailwindcss(),
        react(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
```

- [ ] **Step 3: Add `tsconfig.json`**

```json
{
  "compilerOptions": {
    "target": "ES2020",
    "useDefineForClassFields": true,
    "lib": ["ES2020", "DOM", "DOM.Iterable"],
    "module": "ESNext",
    "skipLibCheck": true,
    "moduleResolution": "Bundler",
    "resolveJsonModule": true,
    "isolatedModules": true,
    "noEmit": true,
    "jsx": "react-jsx",
    "strict": true
  },
  "include": ["resources/js/console"]
}
```

- [ ] **Step 4: Write the failing auth test**

```php
<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_protected_console_routes(): void
    {
        $this->post('/console/logout')->assertRedirect('/console/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/console/login')
            ->assertInertia(fn (Assert $page) => $page->component('Login'));
    }

    public function test_valid_credentials_log_the_user_in_and_redirect_to_the_console(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret12345')]);

        $this->post('/console/login', [
            'email' => $user->email,
            'password' => 'secret12345',
        ])->assertRedirect('/console');

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret12345')]);

        $this->post('/console/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_authenticated_user_can_log_out(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/console/logout')->assertRedirect('/console/login');

        $this->assertGuest();
    }
}
```

Save this to `tests/Feature/Console/AuthControllerTest.php`.

- [ ] **Step 5: Run the tests to confirm they fail**

Run: `php artisan test --filter=AuthControllerTest`
Expected: FAIL (routes `console.logout`/`login` don't exist yet — 404s).

- [ ] **Step 6: Create the Inertia middleware**

```php
<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'console';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                ] : null,
            ],
        ];
    }
}
```

Save to `app/Http/Middleware/HandleInertiaRequests.php`.

- [ ] **Step 7: Create the Blade root view**

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'AutoContent') }} — Console</title>
    @vite(['resources/css/app.css', 'resources/js/console/app.tsx'])
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
</body>
</html>
```

Save to `resources/views/console.blade.php`.

- [ ] **Step 8: Create the AuthController**

```php
<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        return redirect('/console');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
```

Save to `app/Http/Controllers/Console/AuthController.php`.

- [ ] **Step 9: Create the route file and wire it into `web.php`**

```php
<?php

use App\Http\Controllers\Console\AuthController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

Route::middleware(HandleInertiaRequests::class)->prefix('console')->group(function () {
    Route::get('login', [AuthController::class, 'create'])->name('login');
    Route::post('login', [AuthController::class, 'store']);

    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'destroy'])->name('console.logout');
    });
});
```

Save to `routes/panel.php`.

Modify `routes/web.php` — add the require after the existing route:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

require __DIR__.'/panel.php';
```

- [ ] **Step 10: Run the tests to confirm the backend passes**

Run: `php artisan test --filter=AuthControllerTest`
Expected: `test_login_page_renders` still FAILs (no `Login.tsx` page yet, Inertia can't resolve the component) — the rest pass. Continue to the frontend steps.

- [ ] **Step 11: Create the `cn()` utility**

```ts
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
```

Save to `resources/js/console/lib/utils.ts`.

- [ ] **Step 12: Create the `Button`, `Input`, `Label` primitives**

```tsx
import { ButtonHTMLAttributes, forwardRef } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '../../lib/utils';

const buttonVariants = cva(
    'inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                default: 'bg-gray-900 text-white hover:bg-gray-800',
                outline: 'border border-gray-300 bg-white hover:bg-gray-50',
                destructive: 'bg-red-600 text-white hover:bg-red-500',
            },
            size: {
                default: 'h-9 px-4 py-2',
                sm: 'h-8 px-3 text-xs',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

export interface ButtonProps
    extends ButtonHTMLAttributes<HTMLButtonElement>,
        VariantProps<typeof buttonVariants> {}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
    ({ className, variant, size, ...props }, ref) => (
        <button ref={ref} className={cn(buttonVariants({ variant, size }), className)} {...props} />
    ),
);
Button.displayName = 'Button';
```

Save to `resources/js/console/components/ui/button.tsx`.

```tsx
import { InputHTMLAttributes, forwardRef } from 'react';
import { cn } from '../../lib/utils';

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(
    ({ className, ...props }, ref) => (
        <input
            ref={ref}
            className={cn(
                'w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10',
                className,
            )}
            {...props}
        />
    ),
);
Input.displayName = 'Input';
```

Save to `resources/js/console/components/ui/input.tsx`.

```tsx
import { LabelHTMLAttributes } from 'react';
import { cn } from '../../lib/utils';

export function Label({ className, ...props }: LabelHTMLAttributes<HTMLLabelElement>) {
    return <label className={cn('text-sm font-medium text-gray-700', className)} {...props} />;
}
```

Save to `resources/js/console/components/ui/label.tsx`.

- [ ] **Step 13: Create the Login page**

```tsx
import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Button } from '../components/ui/button';
import { Input } from '../components/ui/input';
import { Label } from '../components/ui/label';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/console/login');
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-50">
            <form onSubmit={submit} className="w-full max-w-sm space-y-4 rounded-lg border bg-white p-6 shadow-sm">
                <h1 className="text-lg font-semibold">Console login</h1>

                <div className="space-y-1">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    {errors.email && <p className="text-sm text-red-600">{errors.email}</p>}
                </div>

                <div className="space-y-1">
                    <Label htmlFor="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                </div>

                <Button type="submit" disabled={processing} className="w-full">
                    Sign in
                </Button>
            </form>
        </div>
    );
}
```

Save to `resources/js/console/Pages/Login.tsx`.

- [ ] **Step 14: Create the Inertia entrypoint**

```tsx
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
        return pages[`./Pages/${name}.tsx`];
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
```

Save to `resources/js/console/app.tsx`.

- [ ] **Step 15: Run the tests to confirm they all pass**

Run: `php artisan test --filter=AuthControllerTest`
Expected: PASS (4 tests).

- [ ] **Step 16: Style check**

Run: `vendor/bin/pint --test`
Expected: no changes needed (or run `vendor/bin/pint` without `--test` to auto-fix, then re-stage).

- [ ] **Step 17: Commit**

```bash
git add composer.json composer.lock package.json package-lock.json vite.config.js tsconfig.json \
  resources/views/console.blade.php app/Http/Middleware/HandleInertiaRequests.php \
  app/Http/Controllers/Console/AuthController.php routes/panel.php routes/web.php \
  resources/js/console tests/Feature/Console/AuthControllerTest.php
git commit -m "feat(console): bootstrap Inertia+React toolchain and console auth"
```

---

### Task 2: Dashboard

**Files:**
- Create: `app/Http/Controllers/Console/DashboardController.php`
- Modify: `routes/panel.php`
- Create: `resources/js/console/Components/AppLayout.tsx`
- Create: `resources/js/console/components/ui/card.tsx`
- Create: `resources/js/console/components/ui/badge.tsx`
- Create: `resources/js/console/components/ui/table.tsx`
- Create: `resources/js/console/Pages/Dashboard.tsx`
- Test: `tests/Feature/Console/DashboardControllerTest.php`

**Interfaces:**
- Consumes: `HandleInertiaRequests` middleware + `auth`-protected route group from Task 1 (`routes/panel.php`), `Button` (for the layout's sign-out button), `cn()`.
- Produces: `AppLayout` React component (`resources/js/console/Components/AppLayout.tsx`), `Card`/`CardHeader`/`CardTitle`/`CardContent`, `Badge` (`variant?: 'default' | 'success' | 'warning' | 'danger'`), `Table`/`TableHead`/`TableBody`/`TableRow`/`TableHeadCell`/`TableCell` — all reused by Task 3 and Task 4. Named route `console.dashboard` (`GET /console`, `auth`-protected).

- [ ] **Step 1: Write the failing dashboard test**

```php
<?php

namespace Tests\Feature\Console;

use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\PublicationStatus;
use App\Models\Enums\VideoStatus;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_stats_best_videos_and_top_topics(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create();
        $idea = ContentIdea::factory()->create([
            'content_project_id' => $project->id,
            'topic' => 'ai news',
        ]);
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'status' => VideoStatus::Rendered,
            'title' => 'AI News Roundup',
        ]);
        $account = SocialAccount::factory()->create(['content_project_id' => $project->id]);
        $publication = Publication::factory()->create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => PublicationStatus::Published,
        ]);
        VideoMetric::factory()->create([
            'publication_id' => $publication->id,
            'views' => 1000,
            'likes' => 50,
            'comments' => 5,
            'measured_at' => now(),
        ]);

        $this->get('/console')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.videosGeneratedToday', 1)
                ->where('stats.videosPublished', 1)
                ->where('stats.views', 1000)
                ->has('bestVideos', 1)
                ->where('bestVideos.0.video_title', 'AI News Roundup')
                ->has('topTopics', 1)
                ->where('topTopics.0.topic', 'ai news')
            );
    }
}
```

Save to `tests/Feature/Console/DashboardControllerTest.php`.

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php artisan test --filter=DashboardControllerTest`
Expected: FAIL (404 — `GET /console` isn't routed yet).

- [ ] **Step 3: Create the DashboardController**

```php
<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ContentIdea;
use App\Models\Enums\PublicationStatus;
use App\Models\Enums\VideoStatus;
use App\Models\Publication;
use App\Models\Video;
use App\Models\VideoMetric;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $latestMetrics = VideoMetric::latestPerPublication()->get();

        $stats = [
            'videosGeneratedToday' => Video::where('status', VideoStatus::Rendered)
                ->whereDate('updated_at', today())
                ->count(),
            'videosPublished' => Publication::where('status', PublicationStatus::Published)->count(),
            'failedJobsToday' => $request->user()
                ->notifications()
                ->where('type', PipelineJobFailedNotification::class)
                ->whereDate('created_at', today())
                ->count(),
            'views' => (int) $latestMetrics->sum('views'),
            'likes' => (int) $latestMetrics->sum('likes'),
            'comments' => (int) $latestMetrics->sum('comments'),
        ];

        $bestVideos = Publication::query()
            ->joinSub(VideoMetric::latestPerPublication(), 'latest_metrics', 'latest_metrics.publication_id', '=', 'publications.id')
            ->join('videos', 'videos.id', '=', 'publications.video_id')
            ->join('social_accounts', 'social_accounts.id', '=', 'publications.social_account_id')
            ->orderByDesc('latest_metrics.views')
            ->limit(5)
            ->get([
                'publications.id',
                'videos.title as video_title',
                'social_accounts.platform',
                'latest_metrics.views',
                'latest_metrics.likes',
                'latest_metrics.comments',
            ]);

        $topTopics = ContentIdea::query()
            ->join('videos', 'videos.content_idea_id', '=', 'content_ideas.id')
            ->join('publications', 'publications.video_id', '=', 'videos.id')
            ->joinSub(VideoMetric::latestPerPublication(), 'latest_metrics', 'latest_metrics.publication_id', '=', 'publications.id')
            ->selectRaw('content_ideas.topic, SUM(latest_metrics.views) as total_views')
            ->groupBy('content_ideas.topic')
            ->orderByDesc('total_views')
            ->limit(5)
            ->get();

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'bestVideos' => $bestVideos,
            'topTopics' => $topTopics,
        ]);
    }
}
```

Save to `app/Http/Controllers/Console/DashboardController.php`.

- [ ] **Step 4: Wire the dashboard route**

Modify `routes/panel.php` — add the import and the route inside the existing `auth` group:

```php
use App\Http\Controllers\Console\DashboardController;
```

```php
    Route::middleware('auth')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('console.dashboard');
        Route::post('logout', [AuthController::class, 'destroy'])->name('console.logout');
    });
```

- [ ] **Step 5: Create the shared `AppLayout`, `Card`, `Badge`, `Table` primitives**

```tsx
import { PropsWithChildren } from 'react';
import { Link, router } from '@inertiajs/react';
import { Button } from '../components/ui/button';

export function AppLayout({ children }: PropsWithChildren) {
    function logout() {
        router.post('/console/logout');
    }

    return (
        <div className="min-h-screen bg-gray-50">
            <header className="border-b bg-white">
                <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                    <nav className="flex items-center gap-4 text-sm font-medium">
                        <Link href="/console" className="text-gray-900">Dashboard</Link>
                        <Link href="/console/videos" className="text-gray-500 hover:text-gray-900">Videos</Link>
                    </nav>
                    <Button variant="outline" size="sm" onClick={logout}>Sign out</Button>
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-6 py-8">{children}</main>
        </div>
    );
}
```

Save to `resources/js/console/Components/AppLayout.tsx`.

```tsx
import { HTMLAttributes } from 'react';
import { cn } from '../../lib/utils';

export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('rounded-lg border bg-white shadow-sm', className)} {...props} />;
}

export function CardHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('border-b px-4 py-3', className)} {...props} />;
}

export function CardTitle({ className, ...props }: HTMLAttributes<HTMLHeadingElement>) {
    return <h3 className={cn('text-sm font-semibold text-gray-900', className)} {...props} />;
}

export function CardContent({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('p-4', className)} {...props} />;
}
```

Save to `resources/js/console/components/ui/card.tsx`.

```tsx
import { HTMLAttributes } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '../../lib/utils';

const badgeVariants = cva('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', {
    variants: {
        variant: {
            default: 'bg-gray-100 text-gray-800',
            success: 'bg-green-100 text-green-800',
            warning: 'bg-amber-100 text-amber-800',
            danger: 'bg-red-100 text-red-800',
        },
    },
    defaultVariants: {
        variant: 'default',
    },
});

export interface BadgeProps extends HTMLAttributes<HTMLSpanElement>, VariantProps<typeof badgeVariants> {}

export function Badge({ className, variant, ...props }: BadgeProps) {
    return <span className={cn(badgeVariants({ variant }), className)} {...props} />;
}
```

Save to `resources/js/console/components/ui/badge.tsx`.

```tsx
import { HTMLAttributes, TdHTMLAttributes, ThHTMLAttributes } from 'react';
import { cn } from '../../lib/utils';

export function Table({ className, ...props }: HTMLAttributes<HTMLTableElement>) {
    return (
        <div className="w-full overflow-x-auto">
            <table className={cn('w-full text-left text-sm', className)} {...props} />
        </div>
    );
}

export function TableHead({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
    return <thead className={cn('border-b text-xs uppercase text-gray-500', className)} {...props} />;
}

export function TableBody({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
    return <tbody className={cn('divide-y', className)} {...props} />;
}

export function TableRow({ className, ...props }: HTMLAttributes<HTMLTableRowElement>) {
    return <tr className={cn('hover:bg-gray-50', className)} {...props} />;
}

export function TableHeadCell({ className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
    return <th className={cn('px-3 py-2 font-medium', className)} {...props} />;
}

export function TableCell({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
    return <td className={cn('px-3 py-2', className)} {...props} />;
}
```

Save to `resources/js/console/components/ui/table.tsx`.

- [ ] **Step 6: Create the Dashboard page**

```tsx
import { AppLayout } from '../Components/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card';
import { Badge } from '../components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeadCell, TableRow } from '../components/ui/table';

interface Stats {
    videosGeneratedToday: number;
    videosPublished: number;
    failedJobsToday: number;
    views: number;
    likes: number;
    comments: number;
}

interface BestVideo {
    id: number;
    video_title: string;
    platform: string;
    views: number;
    likes: number;
    comments: number;
}

interface TopTopic {
    topic: string;
    total_views: number;
}

interface DashboardProps {
    stats: Stats;
    bestVideos: BestVideo[];
    topTopics: TopTopic[];
}

export default function Dashboard({ stats, bestVideos, topTopics }: DashboardProps) {
    return (
        <AppLayout>
            <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
                <Stat label="Videos generated today" value={stats.videosGeneratedToday} />
                <Stat label="Videos published" value={stats.videosPublished} />
                <Stat label="Failed jobs today" value={stats.failedJobsToday} />
                <Stat label="Views" value={stats.views} />
                <Stat label="Likes" value={stats.likes} />
                <Stat label="Comments" value={stats.comments} />
            </div>

            <div className="mt-6 grid gap-6 md:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle>Best Videos</CardTitle></CardHeader>
                    <CardContent>
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <TableHeadCell>Video</TableHeadCell>
                                    <TableHeadCell>Platform</TableHeadCell>
                                    <TableHeadCell>Views</TableHeadCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {bestVideos.map((video) => (
                                    <TableRow key={video.id}>
                                        <TableCell>{video.video_title}</TableCell>
                                        <TableCell><Badge>{video.platform}</Badge></TableCell>
                                        <TableCell>{video.views}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Top Topics</CardTitle></CardHeader>
                    <CardContent>
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <TableHeadCell>Topic</TableHeadCell>
                                    <TableHeadCell>Views</TableHeadCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {topTopics.map((topic) => (
                                    <TableRow key={topic.topic}>
                                        <TableCell>{topic.topic}</TableCell>
                                        <TableCell>{topic.total_views}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <Card>
            <CardContent>
                <p className="text-xs font-medium text-gray-500">{label}</p>
                <p className="mt-1 text-2xl font-semibold text-gray-900">{value}</p>
            </CardContent>
        </Card>
    );
}
```

Save to `resources/js/console/Pages/Dashboard.tsx`.

- [ ] **Step 7: Run the test to confirm it passes**

Run: `php artisan test --filter=DashboardControllerTest`
Expected: PASS.

- [ ] **Step 8: Run the full Console suite + style check**

Run: `php artisan test --filter=Console && vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Console/DashboardController.php routes/panel.php \
  resources/js/console/Components resources/js/console/components/ui/card.tsx \
  resources/js/console/components/ui/badge.tsx resources/js/console/components/ui/table.tsx \
  resources/js/console/Pages/Dashboard.tsx tests/Feature/Console/DashboardControllerTest.php
git commit -m "feat(console): add dashboard with stats, best videos and top topics"
```

---

### Task 3: Videos list + Generate Video

**Files:**
- Modify: `app/Models/Video.php`
- Modify: `app/Filament/Resources/Videos/Tables/VideosTable.php`
- Create: `app/Http/Controllers/Console/VideoController.php`
- Modify: `routes/panel.php`
- Create: `resources/js/console/components/ui/dialog.tsx`
- Create: `resources/js/console/components/ui/select.tsx`
- Create: `resources/js/console/Pages/Videos/Index.tsx`
- Test: `tests/Feature/Console/VideoControllerTest.php`

**Interfaces:**
- Consumes: `AppLayout`, `Button`, `Badge`, `Table` family, `Input`, `Label`, `cn()` from Tasks 1–2.
- Produces: `Video::stageBadgeColor(): string`, `Dialog`/`DialogHeader`/`DialogTitle`/`DialogFooter` (open-controlled modal), `Select` (styled native `<select>`), named routes `console.videos.index` (`GET /console/videos`) and `console.videos.generate` (`POST /console/videos/generate`). Video row shape consumed later by Task 4: `{ id, channel, idea, title, status, stageLabel, stageColor, createdAt }`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Console;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Jobs\GenerateScriptJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VideoControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_videos_with_channel_idea_and_stage(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create(['name' => 'Tech Channel']);
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id, 'title' => 'AI trends']);
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'status' => VideoStatus::Failed,
            'failed_stage' => 'render',
            'title' => 'AI Trends 2026',
        ]);

        $this->get('/console/videos')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Videos/Index')
                ->has('videos', 1)
                ->where('videos.0.title', 'AI Trends 2026')
                ->where('videos.0.channel', 'Tech Channel')
                ->where('videos.0.idea', 'AI trends')
                ->where('videos.0.stageLabel', $video->fresh()->currentStageLabel())
                ->where('videos.0.stageColor', 'danger')
                ->has('channels', 1)
            );
    }

    public function test_generate_creates_an_approved_idea_and_dispatches_script_generation(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'T', 'topic' => 'ai', 'score' => 50])
            );
        });

        $project = ContentProject::factory()->create();

        $this->post('/console/videos/generate', [
            'content_project_id' => $project->id,
            'topic' => 'ai',
        ])->assertRedirect();

        $idea = ContentIdea::where('content_project_id', $project->id)->sole();
        $this->assertSame(ContentIdeaStatus::Approved, $idea->status);

        Queue::assertPushed(GenerateScriptJob::class, fn (GenerateScriptJob $job) => $job->contentIdeaId === $idea->id);
    }
}
```

Save to `tests/Feature/Console/VideoControllerTest.php`.

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test --filter=VideoControllerTest`
Expected: FAIL (routes don't exist, `Video::stageBadgeColor()` doesn't exist).

- [ ] **Step 3: Add `Video::stageBadgeColor()`**

Modify `app/Models/Video.php` — insert this method right after `currentStageLabel()` (after its closing `}` on line 61, before `public function contentProject(): BelongsTo`):

```php
    public function stageBadgeColor(): string
    {
        return match (true) {
            $this->status === VideoStatus::Failed => 'danger',
            $this->status === VideoStatus::Rendered && $this->quality_passed === false => 'danger',
            $this->status === VideoStatus::Rendered && $this->quality_report !== null => 'success',
            default => 'warning',
        };
    }
```

- [ ] **Step 4: Point Filament's color closure at the new method (DRY, no behavior change)**

Modify `app/Filament/Resources/Videos/Tables/VideosTable.php` — replace:

```php
                    ->color(fn (Video $record): string => match (true) {
                        $record->status === VideoStatus::Failed => 'danger',
                        $record->status === VideoStatus::Rendered && $record->quality_passed === false => 'danger',
                        $record->status === VideoStatus::Rendered && $record->quality_report !== null => 'success',
                        default => 'warning',
                    }),
```

with:

```php
                    ->color(fn (Video $record): string => $record->stageBadgeColor()),
```

- [ ] **Step 5: Create the VideoController (index + generate)**

```php
<?php

namespace App\Http\Controllers\Console;

use App\Domain\Content\Services\GenerateContentIdeaService;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateScriptJob;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\Video;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class VideoController extends Controller
{
    public function index(): Response
    {
        $videos = Video::query()
            ->with(['contentProject:id,name', 'contentIdea:id,title'])
            ->latest('id')
            ->get()
            ->map(fn (Video $video) => [
                'id' => $video->id,
                'channel' => $video->contentProject?->name,
                'idea' => $video->contentIdea?->title,
                'title' => $video->title,
                'status' => $video->status->value,
                'stageLabel' => $video->currentStageLabel(),
                'stageColor' => $video->stageBadgeColor(),
                'createdAt' => $video->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Videos/Index', [
            'videos' => $videos,
            'channels' => ContentProject::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'content_project_id' => ['required', 'integer', 'exists:content_projects,id'],
            'topic' => ['required', 'string', 'max:255'],
        ]);

        try {
            $idea = app(GenerateContentIdeaService::class)->generate(
                ContentProject::findOrFail($data['content_project_id']),
                $data['topic'],
            );

            $idea->update(['status' => ContentIdeaStatus::Approved]);

            GenerateScriptJob::dispatch($idea->id);
        } catch (Throwable $exception) {
            return back()->withErrors(['topic' => $exception->getMessage()]);
        }

        return back();
    }
}
```

Save to `app/Http/Controllers/Console/VideoController.php`.

- [ ] **Step 6: Wire the Videos routes**

Modify `routes/panel.php` — add the import:

```php
use App\Http\Controllers\Console\VideoController;
```

and inside the `auth` group, after the dashboard route:

```php
        Route::get('videos', [VideoController::class, 'index'])->name('console.videos.index');
        Route::post('videos/generate', [VideoController::class, 'generate'])->name('console.videos.generate');
```

- [ ] **Step 7: Create the `Dialog` and `Select` primitives**

```tsx
import { HTMLAttributes, PropsWithChildren, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '../../lib/utils';

interface DialogProps extends PropsWithChildren {
    open: boolean;
    onClose: () => void;
}

export function Dialog({ open, onClose, children }: DialogProps) {
    useEffect(() => {
        if (!open) return;

        function handleKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape') onClose();
        }

        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [open, onClose]);

    if (!open) return null;

    return createPortal(
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-lg" role="dialog" aria-modal="true">
                {children}
            </div>
        </div>,
        document.body,
    );
}

export function DialogHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('mb-4', className)} {...props} />;
}

export function DialogTitle({ className, ...props }: HTMLAttributes<HTMLHeadingElement>) {
    return <h2 className={cn('text-lg font-semibold', className)} {...props} />;
}

export function DialogFooter({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('mt-6 flex justify-end gap-2', className)} {...props} />;
}
```

Save to `resources/js/console/components/ui/dialog.tsx`.

```tsx
import { SelectHTMLAttributes, forwardRef } from 'react';
import { cn } from '../../lib/utils';

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(
    ({ className, ...props }, ref) => (
        <select
            ref={ref}
            className={cn('w-full rounded-md border border-gray-300 px-3 py-2 text-sm', className)}
            {...props}
        />
    ),
);
Select.displayName = 'Select';
```

Save to `resources/js/console/components/ui/select.tsx`.

- [ ] **Step 8: Create the Videos index page**

```tsx
import { FormEvent, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AppLayout } from '../../Components/AppLayout';
import { Button } from '../../components/ui/button';
import { Badge } from '../../components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeadCell, TableRow } from '../../components/ui/table';
import { Dialog, DialogFooter, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Select } from '../../components/ui/select';

interface VideoRow {
    id: number;
    channel: string | null;
    idea: string | null;
    title: string;
    status: string;
    stageLabel: string;
    stageColor: 'danger' | 'success' | 'warning' | 'default';
    createdAt: string | null;
}

interface Channel {
    id: number;
    name: string;
}

interface VideosIndexProps {
    videos: VideoRow[];
    channels: Channel[];
}

export default function VideosIndex({ videos, channels }: VideosIndexProps) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        content_project_id: '',
        topic: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/console/videos/generate', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <AppLayout>
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-semibold">Videos</h1>
                <Button onClick={() => setOpen(true)}>Generate Video</Button>
            </div>

            <Table>
                <TableHead>
                    <TableRow>
                        <TableHeadCell>Channel</TableHeadCell>
                        <TableHeadCell>Idea</TableHeadCell>
                        <TableHeadCell>Title</TableHeadCell>
                        <TableHeadCell>Status</TableHeadCell>
                        <TableHeadCell>Stage</TableHeadCell>
                    </TableRow>
                </TableHead>
                <TableBody>
                    {videos.map((video) => (
                        <TableRow key={video.id}>
                            <TableCell>{video.channel}</TableCell>
                            <TableCell>{video.idea}</TableCell>
                            <TableCell>{video.title}</TableCell>
                            <TableCell><Badge>{video.status}</Badge></TableCell>
                            <TableCell><Badge variant={video.stageColor}>{video.stageLabel}</Badge></TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>

            <Dialog open={open} onClose={() => setOpen(false)}>
                <DialogHeader>
                    <DialogTitle>Generate Video</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1">
                        <Label htmlFor="channel">Channel</Label>
                        <Select
                            id="channel"
                            value={data.content_project_id}
                            onChange={(e) => setData('content_project_id', e.target.value)}
                        >
                            <option value="">Select a channel</option>
                            {channels.map((channel) => (
                                <option key={channel.id} value={channel.id}>{channel.name}</option>
                            ))}
                        </Select>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="topic">Topic</Label>
                        <Input
                            id="topic"
                            value={data.topic}
                            onChange={(e) => setData('topic', e.target.value)}
                        />
                        {errors.topic && <p className="text-sm text-red-600">{errors.topic}</p>}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>Start</Button>
                    </DialogFooter>
                </form>
            </Dialog>
        </AppLayout>
    );
}
```

Save to `resources/js/console/Pages/Videos/Index.tsx`.

- [ ] **Step 9: Run the tests to confirm they pass**

Run: `php artisan test --filter=VideoControllerTest`
Expected: PASS (2 tests).

- [ ] **Step 10: Run the full existing suite (Filament regression check) + style**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS — the `VideosTable` refactor in Step 4 must not break any existing Filament test (e.g. `tests/Feature/Filament/*`).

- [ ] **Step 11: Commit**

```bash
git add app/Models/Video.php app/Filament/Resources/Videos/Tables/VideosTable.php \
  app/Http/Controllers/Console/VideoController.php routes/panel.php \
  resources/js/console/components/ui/dialog.tsx resources/js/console/components/ui/select.tsx \
  resources/js/console/Pages/Videos tests/Feature/Console/VideoControllerTest.php
git commit -m "feat(console): add videos list and generate-video flow"
```

---

### Task 4: Retry a failed stage

**Files:**
- Modify: `app/Http/Controllers/Console/VideoController.php`
- Modify: `routes/panel.php`
- Modify: `resources/js/console/Pages/Videos/Index.tsx`
- Modify: `tests/Feature/Console/VideoControllerTest.php`

**Interfaces:**
- Consumes: `VideoController::index()` row shape from Task 3, `VideoStatus` enum, pipeline jobs (`GenerateScenesJob`, `GenerateVoiceoverJob`, `CollectVideoAssetsJob`, `GenerateSubtitlesJob`, `RenderVideoJob`, `QualityCheckVideoJob`).
- Produces: `VideoController::retry(Video $video)`, named route `console.videos.retry` (`POST /console/videos/{video}/retry`), `canRetry: boolean` field added to the video row shape.

- [ ] **Step 1: Add the failing retry tests**

Append to `tests/Feature/Console/VideoControllerTest.php` (inside the class, add these imports at the top: `App\Jobs\RenderVideoJob`, `App\Models\ContentIdea` is already imported):

```php
    public function test_index_marks_failed_and_stalled_videos_as_retryable(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create();
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);
        Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'status' => VideoStatus::Failed,
            'failed_stage' => 'render',
        ]);
        Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'status' => VideoStatus::Draft,
        ]);

        $this->get('/console/videos')
            ->assertInertia(fn (Assert $page) => $page
                ->has('videos', 2)
                ->where('videos.0.canRetry', false)
                ->where('videos.1.canRetry', true)
            );
    }

    public function test_retry_dispatches_the_job_for_the_failed_stage_and_resets_status(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $project = ContentProject::factory()->create();
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'status' => VideoStatus::Failed,
            'failed_stage' => 'render',
            'error_message' => 'ffmpeg exploded',
        ]);

        $this->post("/console/videos/{$video->id}/retry")->assertRedirect();

        $video->refresh();
        $this->assertSame(VideoStatus::AssetsReady, $video->status);
        $this->assertNull($video->failed_stage);
        $this->assertNull($video->error_message);

        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job) => $job->videoId === $video->id);
    }

    public function test_retry_without_a_failed_stage_returns_an_error(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create();
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'status' => VideoStatus::Draft,
            'failed_stage' => null,
        ]);

        $this->post("/console/videos/{$video->id}/retry")->assertSessionHasErrors('video');
    }
```

Note: `videos.0`/`videos.1` ordering relies on `Video::query()->latest()` (newest first) — the second factory call (`Draft`) is created after the first (`Failed`), so it sorts to index 0. Add the import `use App\Jobs\RenderVideoJob;` to the top of the test file.

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test --filter=VideoControllerTest`
Expected: FAIL (`canRetry` key missing from Step 1's assertions; `POST /console/videos/{video}/retry` 404s).

- [ ] **Step 3: Add `retry()` and `canRetry` to VideoController**

Modify `app/Http/Controllers/Console/VideoController.php`:

Add imports:

```php
use App\Jobs\CollectVideoAssetsJob;
use App\Jobs\GenerateScenesJob;
use App\Jobs\GenerateSubtitlesJob;
use App\Jobs\GenerateVoiceoverJob;
use App\Jobs\QualityCheckVideoJob;
use App\Jobs\RenderVideoJob;
use App\Models\Enums\VideoStatus;
```

(`GenerateScriptJob` import already present from Task 3.)

In `index()`, add `'canRetry' => ...` to the mapped array, right after `'stageColor'`:

```php
                'stageColor' => $video->stageBadgeColor(),
                'canRetry' => in_array($video->status, [VideoStatus::Failed, VideoStatus::Rendering], true),
```

Add the new method at the end of the class, before the closing `}`:

```php
    public function retry(Video $video): RedirectResponse
    {
        $stage = $video->status === VideoStatus::Rendering
            ? 'render'
            : $video->failed_stage;

        if ($stage === null) {
            return back()->withErrors(['video' => 'Cannot retry — no failed stage recorded.']);
        }

        $video->update([
            'status' => match ($stage) {
                'scenes', 'voiceover' => VideoStatus::ScriptGenerated,
                'assets' => VideoStatus::VoiceGenerated,
                'subtitles', 'render' => VideoStatus::AssetsReady,
                'quality_check' => VideoStatus::Rendered,
                default => $video->status,
            },
            'failed_stage' => null,
            'error_message' => null,
        ]);

        match ($stage) {
            'scenes' => GenerateScenesJob::dispatch($video->script_id),
            'voiceover' => GenerateVoiceoverJob::dispatch($video->id),
            'assets' => CollectVideoAssetsJob::dispatch($video->id),
            'subtitles' => GenerateSubtitlesJob::dispatch($video->id),
            'render' => RenderVideoJob::dispatch($video->id),
            'quality_check' => QualityCheckVideoJob::dispatch($video->id),
            default => null,
        };

        return back();
    }
```

- [ ] **Step 4: Wire the retry route**

Modify `routes/panel.php` — add after the `console.videos.generate` route:

```php
        Route::post('videos/{video}/retry', [VideoController::class, 'retry'])->name('console.videos.retry');
```

- [ ] **Step 5: Add the Retry column to the Videos page**

Modify `resources/js/console/Pages/Videos/Index.tsx`:

Change the import line:

```tsx
import { useForm } from '@inertiajs/react';
```

to:

```tsx
import { router, useForm } from '@inertiajs/react';
```

Add `canRetry: boolean;` to the `VideoRow` interface, right after `stageColor`:

```tsx
    stageColor: 'danger' | 'success' | 'warning' | 'default';
    canRetry: boolean;
```

Add a `retry` function right after `submit`:

```tsx
    function retry(videoId: number) {
        router.post(`/console/videos/${videoId}/retry`);
    }
```

Add a header cell after the Stage header cell:

```tsx
                        <TableHeadCell>Stage</TableHeadCell>
                        <TableHeadCell></TableHeadCell>
```

Add a body cell after the stage badge cell:

```tsx
                            <TableCell><Badge variant={video.stageColor}>{video.stageLabel}</Badge></TableCell>
                            <TableCell>
                                {video.canRetry && (
                                    <Button variant="outline" size="sm" onClick={() => retry(video.id)}>
                                        Retry
                                    </Button>
                                )}
                            </TableCell>
```

- [ ] **Step 6: Run the tests to confirm they pass**

Run: `php artisan test --filter=VideoControllerTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Run the full suite + style check**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Console/VideoController.php routes/panel.php \
  resources/js/console/Pages/Videos/Index.tsx tests/Feature/Console/VideoControllerTest.php
git commit -m "feat(console): add retry action for failed pipeline stages"
```

---

## After this plan

v1 is done when all 4 tasks are committed and `php artisan test && vendor/bin/pint --test` passes clean. Update `ROADMAP.md` Phase 7 status to `[x]` with the date, and note in the backlog that Phase 8 (architecture/dependency page) can now start since it depends on this admin existing. Manual smoke check before marking done: `npm run build && php artisan serve`, log in at `/console/login` with the seeded `admin@autocontent.test` / `password`, confirm Dashboard/Videos render and Generate Video dispatches a job (check with `php artisan queue:work` running).
