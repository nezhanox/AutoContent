# Phase 3b — Voiceover: design spec

Джерело: `TechnicalTask.md` (розділи 4, 6.2, 7, 8, 17-19), `ROADMAP.md` (Phase 3,
частина "TtsProviderInterface + ElevenLabsTtsProvider, GenerateVoiceoverService,
GenerateVoiceoverJob").

## Мета

Від `Video` з готовими сценами (Phase 3a) до `Voiceover` — аудіофайлу озвучки,
згенерованого через ElevenLabs TTS. Другий з чотирьох запланованих під-фаз Phase 3
(3a Сцени → **3b Voiceover** → 3c Assets → 3d Subtitles → 3e Rendering+QualityCheck
— розбито ще раз під час брейнштормінгу 3b, оскільки Assets і Voiceover виявились
незалежними підсистемами порівнянного з усією 3a масштабу).

## Скоуп

Входить:

* `app/Domain/Video/TtsProviderInterface.php` + `VoiceSettings`/`VoiceResult` DTO
  (розділ 6.2 ТЗ).
* `app/Domain/Video/Providers/ElevenLabsTtsProvider.php` — реальна реалізація через
  Laravel `Http`, `app/Domain/Video/Providers/FakeTtsProvider.php` — тестовий
  двійник.
* `app/Providers/TtsServiceProvider.php` — біндинг `TtsProviderInterface` напряму
  на `ElevenLabsTtsProvider` (без Manager-шару — лише один провайдер у MVP).
* `config/tts.php` — окремий конфіг-файл (той самий підхід, що `config/llm.php`).
* `app/Domain/Video/Services/GenerateVoiceoverService.php`.
* ALTER-міграція: `VoiceoverStatus` enum замість plain string, unique-індекс на
  `voiceovers.video_id`.
* `app/Jobs/GenerateVoiceoverJob.php` — idempotent, timeout/retry.
* Filament: row action "Generate Voiceover" на `VideosTable`.
* Feature/Unit-тести: провайдер, сервіс, job, Filament-дія.

Не входить (свідомо відкладено):

* `AssetProviderInterface`/`MediaAsset`-прив'язка до сцен — Phase 3c.
* Whisper/субтитри — Phase 3d.
* `FfmpegVideoRenderer`/рендеринг/`QualityCheckJob` — Phase 3e.
* Реальна тривалість аудіофайлу (`Voiceover.duration`) — ElevenLabs не повертає її
  в заголовках відповіді, а парсинг MP3-тривалості вимагає `ffprobe`, якого в 3b ще
  нема (з'явиться в 3e разом з FFmpeg-рендерингом). `Voiceover.duration` лишається
  `null` після 3b — колонка вже `nullable()` з Phase 1, міграції не потребує.
* Перетворення `VideoResource`/`VideoSceneResource` на view-only — рішення про це
  й далі відкладено до фінальної під-фази Phase 3 (за аналогією зі `ScriptResource`
  у Phase 2), тепер додатково відзначено в ROADMAP.md carry-over з review Phase 3a.

## Рішення (там, де ТЗ не фіксує деталь явно)

### `TtsProviderInterface` і бінарна відповідь ElevenLabs

```php
interface TtsProviderInterface
{
    public function generate(string $text, VoiceSettings $settings): VoiceResult;
}
```

`VoiceSettings` (`app/Domain/Video/VoiceSettings.php`):

```php
final class VoiceSettings
{
    public function __construct(
        public readonly string $voiceId,
        public readonly float $stability = 0.5,
        public readonly float $similarityBoost = 0.75,
    ) {}
}
```

`VoiceResult` (`app/Domain/Video/VoiceResult.php`):

```php
final class VoiceResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $audioContent,
        public readonly string $provider,
        public readonly string $voice,
        public readonly array $metadata = [],
    ) {}
}
```

`ElevenLabsTtsProvider::generate()`: `POST {base_url}/text-to-speech/{voice_id}`,
заголовок `xi-api-key` (не `Authorization: Bearer`, на відміну від OpenAI/Anthropic
— специфіка ElevenLabs API), JSON body `{text, model_id, voice_settings: {stability,
similarity_boost}}`. **Відповідь — сирі байти `audio/mpeg`, не JSON** — це єдиний
провайдер у проєкті з небінарним/невалідованим JSON-контрактом відповіді; успішна
відповідь читається через `$response->body()` напряму, без `json_decode()`. Помилкові
відповіді ElevenLabs повертає як JSON (`{"detail": {...}}`) — провайдер намагається
`json_decode($response->body())` лише в error-гілці для кращого повідомлення про
помилку, з фолбеком на сирий текст, якщо це не JSON.

`Http::timeout(120)->retry(3, 500, when: ...)` — та сама семантика, що
`OpenAiLlmProvider`/`AnthropicLlmProvider`/`GenerateScenesService`'s LLM-виклики
(ретраїться лише `ConnectionException` і 5xx, весь ланцюг помилок зводиться до
`\RuntimeException`) — довший HTTP-timeout (120с проти 60с у LLM-провайдерів),
оскільки синтез аудіо для довшого сценарію займає реально більше часу, ніж
JSON-відповідь LLM.

### `config/tts.php`

Новий файл, окремо від `config/llm.php` (той самий підхід — dedicated config на
кожен клас зовнішніх провайдерів):

```php
return [
    'default_voice' => env('ELEVENLABS_DEFAULT_VOICE'),
    'providers' => [
        'elevenlabs' => [
            'api_key' => env('ELEVENLABS_API_KEY'),
            'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io/v1'),
            'model_id' => env('ELEVENLABS_MODEL_ID', 'eleven_multilingual_v2'),
        ],
    ],
];
```

`.env.example` отримує нові ключі `ELEVENLABS_BASE_URL`, `ELEVENLABS_MODEL_ID`,
`ELEVENLABS_DEFAULT_VOICE` (усі порожні/з дефолтами, без секретів — розділ 18 ТЗ).
`eleven_multilingual_v2` — дефолтна модель ElevenLabs, що підтримує багато мов,
свідомий вибір з огляду на carry-over з review Phase 3a про мовну гнучкість
пайплайна (сама LLM-генерація мови контролюється в 3a/Phase 2, тут — лише вибір
TTS-моделі, що не обрізає мовну підтримку синтезу).

### `GenerateVoiceoverService` — без repair-loop

```php
final class GenerateVoiceoverService
{
    public function __construct(private readonly TtsProviderInterface $ttsProvider) {}

    /**
     * @return array{text: string, audio: string, provider: string, voice: string, metadata: array<string, mixed>}
     */
    public function generate(Video $video): array
    {
        $text = $this->buildText($video);
        $settings = $this->resolveVoiceSettings($video);
        $result = $this->ttsProvider->generate($text, $settings);

        return [
            'text' => $text,
            'audio' => $result->audioContent,
            'provider' => $result->provider,
            'voice' => $result->voice,
            'metadata' => $result->metadata,
        ];
    }
}
```

Без repair-loop (на відміну від `GenerateScriptService`/`GenerateScenesService`) —
немає JSON-структури для валідації; TTS-виклик або повертає аудіо, або HTTP-шар
кидає `\RuntimeException` напряму (job-level retry обробляє повторні спроби).

`buildText(Video $video)`: `$video->scenes` (вже впорядковані через
`orderBy('order')` у моделі), конкатенація `VideoScene.text` через пробіл.

`resolveVoiceSettings(Video $video)`: `$video->contentProject->settings['tts']['voice']
?? config('tts.default_voice')` — якщо жодного значення нема (ні в проєкті, ні в
`.env`), кидає `\InvalidArgumentException` до виклику провайдера (явна помилка
конфігурації, а не мовчазний виклик ElevenLabs з порожнім voice_id).
`stability`/`similarityBoost` — фіксовані дефолти `VoiceSettings`, без per-project
налаштування (не запитувались, MVP-мінімум).

### `GenerateVoiceoverJob` — порядок операцій

```php
class GenerateVoiceoverJob implements ShouldBeUnique, ShouldQueue
{
    public int $timeout = 180;
    public int $tries = 3;
    public function uniqueId(): string { return (string) $this->videoId; }
    public int $uniqueFor = 200;
    public function backoff(): array { return [10, 30, 60]; }
}
```

Ті самі значення, що `GenerateScriptJob`/`GenerateScenesJob`.

`handle()`:

1. `Video::with('scenes')->findOrFail($this->videoId)`.
2. Guard: якщо `status !== VideoStatus::ScriptGenerated` АБО `voiceover()->exists()`
   — вихід без дій (той самий подвійний guard, що в 3a: неправильний стан пайплайна
   АБО вже зроблено).
3. `GenerateVoiceoverService::generate($video)` — TTS-виклик, поза транзакцією.
4. `Storage::disk(config('filesystems.default'))->put("projects/{$video->
   content_project_id}/audio/{$video->id}.mp3", $result['audio']);` — запис файлу
   поза транзакцією, після успішного зовнішнього виклику (урок з review Phase 3a:
   зовнішні side-effects — до БД-запису, ніколи всередині транзакції).
5. `DB::transaction()`: `Voiceover::create([...text, provider, voice, file_path,
   metadata, status: Completed])` + `$video->update(['status' =>
   VideoStatus::VoiceGenerated])` — обидва DB-записи атомарні разом, той самий
   підхід, що фінальний фікс `GenerateScenesJob` у review Phase 3a.

`failed(Throwable $exception)`: `Log::channel('video')->error(...)` — без зміни
DB-стану (той самий підхід, що `GenerateScenesJob::failed()` — немає окремого
"voiceover failed"-статусу на рівні `Video`, кнопка в Filament ховається лише коли
`voiceover()->exists()`, тож при permanent failure кнопка залишається видимою і
дозволяє повторний ручний запуск).

### Ідемпотентність: `voiceovers.video_id` unique + `VoiceoverStatus`

ALTER-міграція: `Schema::table('voiceovers', ...)` — `$table->unique('video_id')`
(колонка вже має звичайний `index('video_id')` з Phase 1 — замінюється на unique,
той самий підхід, що `videos.script_id` у 3a). `App\Models\Enums\VoiceoverStatus`
(`Pending`/`Processing`/`Completed`/`Failed`) — новий enum, каст на
`Voiceover.status` замість plain string (той самий перехід, що `ScriptStatus` у
Phase 2). Job у 3b завжди створює `Voiceover` вже як `Completed` (немає
repair-loop чи проміжних станів між HTTP-викликом і записом) — `Pending`/
`Processing` кейси існують для узгодженості словника статусів пайплайна й на
випадок майбутнього розширення (напр. streaming TTS), не використовуються активно
в 3b.

### Filament

Row action "Generate Voiceover" на `app/Filament/Resources/Videos/Tables/
VideosTable.php` (поруч із наявним `EditAction`):

```php
Action::make('generateVoiceover')
    ->label('Generate Voiceover')
    ->visible(fn (Video $record): bool => $record->status === VideoStatus::ScriptGenerated
        && ! $record->voiceover()->exists())
    ->requiresConfirmation()
    ->action(function (Video $record): void {
        GenerateVoiceoverJob::dispatch($record->id);
        Notification::make()->title('Voiceover generation queued')->success()->send();
    }),
```

### Тести

* Unit: `ElevenLabsTtsProviderTest` — успішна бінарна відповідь, HTTP-помилка (JSON
  error body), retry на 5xx (не на 4xx), timeout-конфігурація — той самий набір
  сценаріїв, що `OpenAiLlmProviderTest`/`AnthropicLlmProviderTest`, адаптований під
  бінарну відповідь.
* Feature: `GenerateVoiceoverServiceTest` — конкатенація сцен у правильному
  порядку, резолвінг voice_id (project override vs `.env`-дефолт vs відсутність
  обох → виняток), `FakeTtsProvider`.
* Feature: `GenerateVoiceoverJobTest` — happy path (Voiceover створюється,
  Video.status → VoiceGenerated, файл записаний на диск), no-op якщо
  `status !== ScriptGenerated`, no-op якщо voiceover вже існує, DB-рівня unique на
  `video_id`.
* Feature: Filament-тест на видимість/dispatch дії `generateVoiceover`.

## Acceptance criteria (для 3b, частина ширшого DoD Phase 3 — пункт 5 розділу 24 ТЗ)

* Можна натиснути "Generate Voiceover" на `Video` зі згенерованими сценами і
  отримати `Voiceover` (аудіофайл на диску, `file_path` заповнено, `Video.status =
  VoiceGenerated`).
* Повторний dispatch того самого `Video` не створює другий `Voiceover` (DB-рівня
  unique, не лише job-рівня).
* `php artisan test`, `vendor/bin/pint --test`, `php artisan route:list`,
  `php artisan migrate:fresh --seed` проходять чисто.
