# Phase 0 — Bootstrap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up a working Laravel 12 skeleton with a Docker Compose environment (app, nginx, postgres, redis, worker, horizon) and the base `app/Domain` directory layout, with zero business logic — ready for Phase 1 to build on.

**Architecture:** Scaffold Laravel via Composer directly into the repo root (which already holds `TechnicalTask.md`, `ROADMAP.md`, `docs/`), then layer Docker Compose + two custom PHP images (`app`/`horizon` share one image, `worker` adds FFmpeg) in front of it. No models, no Filament, no AI code — those are Phase 1+.

**Tech Stack:** PHP 8.4, Laravel 12, Composer 2, Docker Compose v2, PostgreSQL 16, Redis 7, Nginx 1.27, FFmpeg.

**Spec:** `docs/superpowers/specs/2026-09-11-phase0-bootstrap-design.md`

## Global Constraints

- PHP 8.4+, Laravel 12+ (TechnicalTask.md §2).
- Postgres and Redis only via Docker Compose in local dev — no system installs (spec, Рішення).
- Docker Compose services: `app`, `nginx`, `postgres`, `redis`, `worker`, `horizon` (TechnicalTask.md §22).
- FFmpeg must be available in the `worker` container (TechnicalTask.md §22).
- Separate Dockerfiles under `docker/php/` and `docker/worker/` (TechnicalTask.md §22, spec Рішення).
- `.env.example` must contain every key from TechnicalTask.md §18; no secrets ever committed.
- No models/migrations/Filament/business logic in this phase (spec Скоуп — не входить).

---

### Task 1: Scaffold the Laravel application into the repo root

**Files:**
- Create: full Laravel 12 skeleton at repo root (`composer.json`, `artisan`, `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `storage/`, `tests/`, `.env.example`)
- Modify: `.gitignore` (merge Laravel's default entries with the existing `.idea/` line)

**Interfaces:**
- Consumes: nothing (first task)
- Produces: standard Laravel 12 app skeleton at repo root; `php artisan` CLI available; default `/up` health-check route (Laravel 11+ ships this in `bootstrap/app.php` by default); `phpunit`/`pest` test runner wired via `php artisan test`; `laravel/pint` available via `vendor/bin/pint`

- [ ] **Step 1: Scaffold Laravel in the scratchpad, then move it into the repo root**

Composer's `create-project` refuses a non-empty target directory, and this repo root already has `TechnicalTask.md`, `ROADMAP.md`, `docs/`, `.gitignore`. Scaffold into the scratchpad first, then merge.

```bash
composer create-project laravel/laravel:^12.0 /private/tmp/claude-501/-Users-vlad-PhpstormProjects-AutoContent/1bdb01e1-9d71-4af2-ab1e-6110a6b1adaf/scratchpad/laravel-app --prefer-dist --no-interaction

rsync -a --exclude='.git' \
  /private/tmp/claude-501/-Users-vlad-PhpstormProjects-AutoContent/1bdb01e1-9d71-4af2-ab1e-6110a6b1adaf/scratchpad/laravel-app/ \
  /Users/vlad/PhpstormProjects/AutoContent/

rm -rf /private/tmp/claude-501/-Users-vlad-PhpstormProjects-AutoContent/1bdb01e1-9d71-4af2-ab1e-6110a6b1adaf/scratchpad/laravel-app
```

- [ ] **Step 2: Merge `.gitignore`**

`rsync` above overwrote `.gitignore` with Laravel's default. Re-add the `.idea/` entry:

```bash
grep -qxF '.idea/' /Users/vlad/PhpstormProjects/AutoContent/.gitignore || echo '.idea/' >> /Users/vlad/PhpstormProjects/AutoContent/.gitignore
```

- [ ] **Step 3: Generate the app key and run the default test suite**

```bash
cd /Users/vlad/PhpstormProjects/AutoContent
cp .env.example .env
php artisan key:generate
php artisan test
```

Expected: default Laravel example tests (`ExampleTest`) PASS.

- [ ] **Step 4: Run Pint**

```bash
cd /Users/vlad/PhpstormProjects/AutoContent
vendor/bin/pint --test
```

Expected: no style violations (fresh skeleton is already Pint-clean).

- [ ] **Step 5: Commit**

```bash
cd /Users/vlad/PhpstormProjects/AutoContent
rm -f .env
git add -A
git commit -m "Scaffold Laravel 12 application skeleton"
```

---

### Task 2: Configure `.env.example` with all required keys

**Files:**
- Modify: `.env.example`

**Interfaces:**
- Consumes: default `.env.example` produced by Task 1
- Produces: full set of env var names that Phase 1's `config/llm.php` and `config/filesystems.php` will read: `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `ELEVENLABS_API_KEY`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`; DB/queue/cache pointed at the Docker service names `postgres`/`redis`

- [ ] **Step 1: Edit `.env.example`**

Change the default DB block to Postgres, point queue/cache/session at Redis, and append the AI/storage keys from TechnicalTask.md §18.

```dotenv
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=autocontent
DB_USERNAME=autocontent
DB_PASSWORD=autocontent
```

```dotenv
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis

REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379
```

Append at the end of the file:

```dotenv
# --- AI providers (TechnicalTask.md §18) ---
OPENAI_API_KEY=
ANTHROPIC_API_KEY=

ELEVENLABS_API_KEY=

# --- S3-compatible storage (TechnicalTask.md §17-18) ---
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=
FILESYSTEM_DISK=local
```

- [ ] **Step 2: Verify every §18 key is present**

```bash
cd /Users/vlad/PhpstormProjects/AutoContent
for key in OPENAI_API_KEY ANTHROPIC_API_KEY ELEVENLABS_API_KEY AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_DEFAULT_REGION AWS_BUCKET; do
  grep -q "^${key}=" .env.example || echo "MISSING: $key"
done
```

Expected: no output (nothing missing).

- [ ] **Step 3: Commit**

```bash
cd /Users/vlad/PhpstormProjects/AutoContent
git add .env.example
git commit -m "Configure .env.example for Postgres/Redis and AI/storage keys"
```

---

### Task 3: Add Docker images and Compose file

**Files:**
- Create: `docker/php/Dockerfile`
- Create: `docker/worker/Dockerfile`
- Create: `docker/nginx/default.conf`
- Create: `docker-compose.yml`
- Create: `.dockerignore`

**Interfaces:**
- Consumes: repo root Laravel app from Task 1 (`composer.json`, `artisan`, `public/`)
- Produces: Compose service names `app`, `nginx`, `postgres`, `redis`, `worker`, `horizon` that later phases (and `README.md`) reference; nginx listens on host port `8080`; Postgres on host port `5432`; Redis on host port `6379`

- [ ] **Step 1: Write `.dockerignore`**

```text
.git
.idea
node_modules
vendor
storage/logs
storage/framework/cache
storage/framework/sessions
storage/framework/views
tests
docs
```

- [ ] **Step 2: Write `docker/php/Dockerfile`** (shared by `app` and `horizon`)

```dockerfile
FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath zip intl pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction

CMD ["php-fpm"]
```

- [ ] **Step 3: Write `docker/worker/Dockerfile`**

```dockerfile
FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        ffmpeg \
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath zip intl pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction

CMD ["php", "artisan", "queue:work", "--sleep=3", "--tries=3"]
```

- [ ] **Step 4: Write `docker/nginx/default.conf`**

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

- [ ] **Step 5: Write `docker-compose.yml`**

```yaml
services:
  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    volumes:
      - .:/var/www/html
    env_file: .env
    depends_on:
      - postgres
      - redis

  nginx:
    image: nginx:1.27-alpine
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - app

  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: ${DB_DATABASE:-autocontent}
      POSTGRES_USER: ${DB_USERNAME:-autocontent}
      POSTGRES_PASSWORD: ${DB_PASSWORD:-autocontent}
    volumes:
      - postgres_data:/var/lib/postgresql/data
    ports:
      - "5432:5432"

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  worker:
    build:
      context: .
      dockerfile: docker/worker/Dockerfile
    volumes:
      - .:/var/www/html
    env_file: .env
    depends_on:
      - postgres
      - redis

  horizon:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    command: php artisan horizon
    volumes:
      - .:/var/www/html
    env_file: .env
    depends_on:
      - postgres
      - redis

volumes:
  postgres_data:
```

- [ ] **Step 6: Validate the Compose file**

```bash
cd /Users/vlad/PhpstormProjects/AutoContent
docker compose config --quiet
```

Expected: exits 0, no output.

- [ ] **Step 7: Commit**

```bash
cd /Users/vlad/PhpstormProjects/AutoContent
git add docker/ docker-compose.yml .dockerignore
git commit -m "Add Docker Compose environment (app, nginx, postgres, redis, worker, horizon)"
```

---

### Task 4: Smoke-test the Docker environment end to end

**Files:**
- None created/modified — verification only.

**Interfaces:**
- Consumes: `docker-compose.yml` and Dockerfiles from Task 3, `.env.example` from Task 2
- Produces: confirmation that the DoD in the spec holds; nothing downstream depends on this task's artifacts (verification-only)

- [ ] **Step 1: Create a local `.env` from the example (not committed)**

```bash
cd /Users/vlad/PhpstormProjects/AutoContent
cp .env.example .env
php artisan key:generate
```

- [ ] **Step 2: Build and start the stack**

```bash
cd /Users/vlad/PhpstormProjects/AutoContent
docker compose build
docker compose up -d
```

- [ ] **Step 3: Run migrations table check and hit the health route**

```bash
cd /Users/vlad/PhpstormProjects/AutoContent
sleep 5
curl -sS -o /dev/null -w "%{http_code}\n" http://localhost:8080/up
```

Expected: `200`.

- [ ] **Step 4: Verify FFmpeg is present in the worker container**

```bash
cd /Users/vlad/PhpstormProjects/AutoContent
docker compose exec worker ffmpeg -version
```

Expected: prints an FFmpeg version banner, exit code 0.

- [ ] **Step 5: Tear down**

```bash
cd /Users/vlad/PhpstormProjects/AutoContent
docker compose down
rm -f .env
```

- [ ] **Step 6: No commit** (verification-only task — nothing to stage)

---

### Task 5: Add `app/Domain` module layout and project README

**Files:**
- Create: `app/Domain/Content/.gitkeep`
- Create: `app/Domain/Video/.gitkeep`
- Create: `app/Domain/Publishing/.gitkeep`
- Create: `app/Domain/Analytics/.gitkeep`
- Create: `app/Domain/Llm/.gitkeep`
- Create: `README.md`

**Interfaces:**
- Consumes: nothing beyond the Laravel skeleton from Task 1
- Produces: directory namespaces `App\Domain\Content`, `App\Domain\Video`, `App\Domain\Publishing`, `App\Domain\Analytics`, `App\Domain\Llm` that Phase 1+ will populate with `Services/`, `Models` support classes, and (per TechnicalTask.md §6.1-6.2) the `Llm` module's `LlmProviderInterface`/`LlmManager`

- [ ] **Step 1: Create the domain module placeholders**

```bash
cd /Users/vlad/PhpstormProjects/AutoContent
mkdir -p app/Domain/Content app/Domain/Video app/Domain/Publishing app/Domain/Analytics app/Domain/Llm
touch app/Domain/Content/.gitkeep app/Domain/Video/.gitkeep app/Domain/Publishing/.gitkeep app/Domain/Analytics/.gitkeep app/Domain/Llm/.gitkeep
```

- [ ] **Step 2: Write `README.md`**

```markdown
# AI Content Factory

Laravel-застосунок для автоматизованого виробництва faceless short-form контенту
та публікації в TikTok, YouTube Shorts, Instagram Reels та X.

- Вимоги: [`TechnicalTask.md`](TechnicalTask.md)
- План виконання по фазах: [`ROADMAP.md`](ROADMAP.md)
- Спеки та implementation plans: `docs/superpowers/`

## Локальний запуск

\`\`\`bash
cp .env.example .env
php artisan key:generate
docker compose up -d --build
\`\`\`

Застосунок доступний на http://localhost:8080, health-check — http://localhost:8080/up.

## Тести

\`\`\`bash
php artisan test
vendor/bin/pint --test
\`\`\`
```

- [ ] **Step 3: Verify the directories are tracked by git**

```bash
cd /Users/vlad/PhpstormProjects/AutoContent
git status --porcelain app/Domain README.md
```

Expected: all five `.gitkeep` files and `README.md` listed as untracked (`??`), ready to add.

- [ ] **Step 4: Commit**

```bash
cd /Users/vlad/PhpstormProjects/AutoContent
git add app/Domain README.md
git commit -m "Add app/Domain module layout and project README"
```
