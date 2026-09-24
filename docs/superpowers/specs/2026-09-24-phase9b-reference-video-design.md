# Phase 9b — референс-відео → проєкт (design)

Дата: 2026-09-24
Статус: затверджено користувачем (brainstorming), готово до writing-plans.

## Мета

Другий вхід у Phase 9 (перший — Phase 9a, текстова ідея): користувач дає
шлях до **локального** референс-відео (напр. збережений з TikTok
роликом, який йому сподобався стилем/структурою) → Claude Code аналізує
його (транскрипт озвучки + приклад кадрів) → налаштовує `ContentProject`
так, щоб система генерувала схожі за стилем/структурою відео → прогонить
той самий цикл чернетка(и) → фінальний рендер → звіт, що вже є в Phase 9a.

Дослідження теми в 9a і аналіз стилю/структури тут — та сама
архітектурна позиція: це робота Claude Code під час виконання skill, не
LLM-виклик усередині застосунку (без нового `purpose` в `LlmManager`,
без нового provider). PHP-частина лишається тонким, тестованим CLI-
адаптером, який видає Claude сирі матеріали для аналізу (кадри,
транскрипт, метадані), а не готовий вердикт.

## Скоуп v1 (узгоджено з користувачем)

- **Тільки локальний файл** (шлях на диску, наприклад вже підготовлений
  користувачем `examples/videos/IMG_2514.MP4`). Завантаження за URL
  (TikTok/YouTube, через `yt-dlp`) — свідомо поза v1: новий бінарний
  залежність, робота з захистом платформ від скрапінгу, стабільність
  зовнішнього інструменту — все це окрема майбутня ітерація, якщо
  з'ясується, що вона реально потрібна.
- Без scene-cut detection (ffmpeg `select='gt(scene,...)'`) — v1 бере
  рівномірно розподілені по часу кадри. Простіше, детерміновано,
  легко тестується через `Process::fake()`. Якщо аналізу за рівномірними
  кадрами виявиться недостатньо для реальних референсів — окрема
  майбутня ітерація.
- Без автоочищення тимчасових артефактів аналізу (кадри/аудіо
  лишаються на диску в `storage/app/private/reference-analysis/{uuid}/`,
  який вже гітігнорований — `storage/app/private/.gitignore` містить
  `*`). Ручне прибирання диска — відповідальність користувача/майбутній
  cron, не частина цього скоупу.

## Нова підсистема (PHP)

### `App\Domain\Video\Services\ExtractReferenceMediaService`

Новий доменний сервіс (не інтерфейс + Fake-двійник — на відміну від
`LlmProviderInterface`/`AssetProviderInterface`/`TtsProviderInterface`/
`SocialPublisherInterface`, тут немає взаємозамінних реалізацій: єдиний
бекенд — локальний `ffmpeg`/`ffprobe`, той самий підхід, що вже є в
`FfmpegVideoRenderer`/`FfprobeVideoQualityChecker` — зовнішні CLI-виклики
йдуть через `Illuminate\Support\Facades\Process`, тестуються через
`Process::fake()`, без окремого `Fake*`-класу).

```php
final class ExtractReferenceMediaService
{
    public function __construct(
        private readonly TranscriptionProviderInterface $transcriptionProvider,
    ) {}

    /**
     * @return array{
     *   duration: float, width: int, height: int,
     *   frames: array<int, string>,
     *   transcript: array{language: string, segments: array<int, array{start: float, end: float, text: string}>}|null,
     *   notes: array<int, string>,
     * }
     */
    public function analyze(string $sourcePath, int $frameCount, ?string $language, string $workDir): array
}
```

Кроки всередині `analyze()`:

1. **`probe($sourcePath)`** — `ffprobe -show_format -show_streams`, той
   самий парсинг-паттерн, що в `FfmpegVideoRenderer::probe()`: дістає
   `duration`, `width`/`height` з video-стріму, і додатково — чи є
   `audio`-стрім (`codec_type === 'audio'`). Ненульовий exit-код
   `ffprobe` → `RuntimeException` (не валідний медіафайл — команда це
   перетворить на читабельну помилку користувачу, деталі в розділі
   команди нижче).
2. Якщо немає video-стріму → `RuntimeException('No video stream found')`.
3. **Аудіо + транскрипт** (тільки якщо є audio-стрім, інакше
   `transcript: null` і `notes[] = 'No audio stream detected — transcript skipped.'`):
   - `ffmpeg -i {source} -vn -acodec pcm_s16le -ar 16000 -ac 1 {workDir}/audio.wav -y`
   - `$this->transcriptionProvider->transcribe("{workDir}/audio.wav", $language)`
     — перевикористовує вже існуючий `TranscriptionProviderInterface`
     (той самий біндинг, що й `GenerateSubtitlesService`, включно з
     `FakeTranscriptionProvider` у тестах) без жодних змін в інтерфейсі
     чи в `WhisperCliTranscriptionProvider`.
4. **Кадри** — `frameCount` рівномірно розподілених JPEG, mid-point
   семплінг (уникає точного `0` і точного кінця файлу, де декодування
   може дати чорний/збитий кадр):
   `timestamp[i] = duration * (i + 0.5) / frameCount`, `i = 0..frameCount-1`.
   Один викликffmpeg на кадр (як і решта проєкту робить по одному
   викливу ffmpeg на крок, а не один величезний filter-graph):
   `ffmpeg -ss {timestamp} -i {source} -frames:v 1 -q:v 2 {workDir}/frame-{01}.jpg -y`.
5. Кожен `ffmpeg`/`ffprobe`-виклик — `Process::timeout(config('render.timeout'))`
   (перевикористовує вже існуючий `render.timeout`, не новий config-ключ:
   ці виклики — того самого класу операцій, що вже описані тим таймаутом).
   Будь-який ненульовий exit-код кидає `RuntimeException` з
   `trim($result->errorOutput() ?: $result->output())` — той самий
   паттерн, що і в `FfmpegVideoRenderer`.

### Новий artisan-command `video-reference:analyze`

```
php artisan video-reference:analyze {path}
    {--frames=8 : Number of evenly-spaced frames to extract}
    {--language= : ISO language hint for transcription, e.g. en/uk}
```

- Тонкий шар над `ExtractReferenceMediaService` (той самий принцип, що
  `content-idea:draft`/`content-idea:generate` — команда не містить
  доменної логіки, лише I/O та валідацію аргументів).
- Валідація перед викликом сервісу: `{path}` існує і читається
  (`is_file`) — інакше `$this->error(...)`, `self::FAILURE`, без падіння
  в сервіс.
- `workDir` — новий каталог `storage/app/private/reference-analysis/{Str::uuid()}/`
  (`Storage::disk('local')->makeDirectory(...)`).
- **Жодного запису в БД** — той самий контракт, що й `content-idea:draft`.
- Виводить у stdout **єдиний JSON-рядок** (`$this->line(json_encode(...))`)
  такої форми:

  ```json
  {
    "duration": 42.3,
    "width": 1080,
    "height": 1920,
    "frames": [
      "storage/app/private/reference-analysis/<uuid>/frame-01.jpg",
      "storage/app/private/reference-analysis/<uuid>/frame-02.jpg"
    ],
    "transcript": {
      "language": "en",
      "segments": [{"start": 0.0, "end": 2.3, "text": "..."}]
    },
    "notes": []
  }
  ```

  **Ключове рішення:** шляхи у `frames` — **відносні до кореня проєкту**
  (`base_path()`), не абсолютні. Причина: artisan-команда типово
  виконується всередині `worker`/`app`-контейнера
  (`storage_path()` там резолвиться в `/var/www/html/storage/...`), а
  Claude Code, що потім читає ці JPEG через `Read`-тул, працює з
  host-файловою системою. Оскільки `docker-compose.yml` монтує
  `.:/var/www/html` (bind mount — той самий фізичний файл видно з обох
  боків), відносний шлях + відомий host-корінь проєкту (робоча
  директорія сесії Claude Code) дають правильний абсолютний шлях без
  жодного знання про те, де саме команда виконувалась. Реалізація:
  `Str::after($absolutePath, base_path().DIRECTORY_SEPARATOR)`.

- Помилки (файл не знайдено, не медіафайл, немає video-стріму,
  ffmpeg/ffprobe впав) — читабельне повідомлення через `$this->error()`
  + `self::FAILURE`, без stack trace користувачу (та сама планка, що і
  в `CreateContentProjectCommand`).

### Конфігурація

Без нових config-файлів/ключів — усе перевикористовує
`config('render.ffmpeg_binary')`, `config('render.ffprobe_binary')`,
`config('render.timeout')`. `--frames`/`--language` — CLI-опції команди,
не config (per-run параметри, не глобальні дефолти застосунку).

## Нова skill

`.claude/skills/reference-video-to-project/SKILL.md` — самодостатній
файл (без cross-skill залежності на `idea-to-project` — невелике
дублювання "хвоста" є свідомим рішенням: skill має читатись і
виконуватись сам по собі, без стрибків між файлами під час реального
прогону).

Кроки:

1. **Отримай шлях до локального відео** від користувача (напр.
   `examples/videos/IMG_2514.MP4`).
2. **Проаналізуй:**
   ```bash
   php artisan video-reference:analyze {path} --frames=8
   ```
   Прочитай JSON. Якщо `transcript` — `null`, врахуй `notes` (немає
   аудіо-треку — орієнтуйся лише на візуальний стиль).
3. **Подивись на референс сам** (Claude, не LLM-виклик застосунку):
   - Відкрий кожен `frames[i]` через `Read`-тул (зображення) — оціни
     візуальний стиль (кольори, наявність тексту на екрані, композиція,
     чи це talking-head/b-roll/стокові кадри/анімація).
   - Прочитай `transcript.segments` — оціни тон озвучки, темп мовлення,
     структуру (хук у перші секунди? CTA в кінці?), мову оригіналу.
   - Порахуй приблизну частоту зміни кадру із самих `frames`-таймстемпів
     відносно `duration` (v1 не робить точного scene-detection — це
     орієнтовна оцінка "швидкий монтаж" vs "довгі кадри", досить для
     `style`-нотатки).
4. **Визнач налаштування проєкту** — та сама структура, що в
   `idea-to-project`, плюс структурні нотатки в `style`:
   - `niche`, `language` (з транскрипту, якщо є; інакше — з візуального
     контексту), `tone`
   - `style` — вільний текст, тепер **явно включає** спостережений
     пейсинг/структуру (напр. "energetic, hook in first 2s, cuts every
     2-3s, bold on-screen captions, upbeat VO") — саме цей текст
     напряму йде в system-промпт `GenerateScriptService`
     (`Style: %s`), тож жодних нових полів схеми/БД не потрібно.
   - `target_platforms`
   - Початковий `title`/`topic` для чернетки — не копія референсу
     (авторське право/оригінальність), а власна тема **в тому самому
     стилі**.
5. **Той самий цикл, що в `idea-to-project` (кроки 3-6):**
   `content-project:create` → до 2 ітерацій `content-idea:draft`
   (порівнюючи чернетку і з задумом, і зі стилем референсу — чи
   витримано пейсинг/структуру) → `content-idea:generate` → очікування
   `Video.status`/`quality_passed` → звіт користувачу (посилання на
   відео/проєкт, що саме взято зі стилю референсу, скільки ітерацій
   знадобилось).

## Тестування

`tests/Feature/Console/VideoReferenceAnalyzeCommandTest.php` (за
прикладом `CreateContentProjectCommandTest`/`FfmpegVideoRendererTest`):

- `Process::fake(function ($process) { ... })` — той самий паттерн, що в
  `FfmpegVideoRendererTest::fakeFfmpegProcesses()`: розпізнає виклик
  по аргументах команди (`-show_streams` → фейкове ffprobe JSON з
  duration/video+audio стрімами; вихідний файл, що закінчується на
  `.wav`/`.jpg` → `file_put_contents($output, 'fake-bytes')`, щоб
  подальший код (якщо читає файл) не впав).
- Container-біндинг `TranscriptionProviderInterface` → `FakeTranscriptionProvider`
  (вже існує з Phase 3d, ті самі canned segments).
- Кейси:
  1. Happy path: відео з audio-стрімом, `--frames=5` → JSON має рівно
     5 шляхів у `frames`, `transcript` не `null`, шляхи відносні
     (починаються з `storage/app/private/...`, без leading `/`).
  2. Немає audio-стріму (ffprobe fake без audio-стріму) → `transcript: null`,
     `notes` містить попередження, команда все ще `SUCCESS`.
  3. Файл не існує → `self::FAILURE`, читабельне повідомлення, без
     викликів `Process`.
  4. `ffprobe` повертає ненульовий exit-код (не медіафайл) →
     `self::FAILURE`, читабельне повідомлення (не сирий stack trace).
  5. Немає video-стріму (лише audio) → `self::FAILURE`, читабельне
     повідомлення.

`tests/Unit/Domain/Video/ExtractReferenceMediaServiceTest.php` —
пряме юніт-покриття сервісу (ті самі кейси 1-2-5 вище, але на рівні
сервісу, без командного шару) + перевірка точних timestamp'ів
мід-поінт семплінгу для заданих `duration`/`frameCount`.

## DoD

1. `php artisan video-reference:analyze examples/videos/IMG_2514.MP4 --frames=8`
   на реальному файлі (не Fake) реально повертає валідний JSON з 8
   кадрами (файли фізично існують за вказаними відносними шляхами) і
   транскриптом (реальний Whisper-виклик).
2. Нова skill `reference-video-to-project` існує і документує повний
   потік.
3. **Наскрізна ручна перевірка** (як у Phase 9a): прогнати весь потік
   на `examples/videos/IMG_2514.MP4` через нову skill до фінального
   рендеру, підтвердити `Video.status=Rendered` і `quality_passed`.
4. `php artisan test`, `pint --test`, `route:list`,
   `migrate:fresh --seed` — без регресій (нова функціональність не
   додає міграцій/маршрутів — команда і сервіс, без Filament/Console UI).

## Свідомо поза скоупом (для майбутніх фаз)

- Завантаження референсу за URL (`yt-dlp`).
- Scene-cut detection замість рівномірного семплінгу кадрів.
- Автоочищення `storage/app/private/reference-analysis/`.
- Дедублікація ffprobe-парсингу (третє місце в коді після
  `FfmpegVideoRenderer`/`FfprobeVideoQualityChecker`, вже занотовано
  як борг у ROADMAP Phase 3e review) — не торкаємось у цьому скоупі,
  щоб не розширювати задачу непов'язаним рефакторингом.
