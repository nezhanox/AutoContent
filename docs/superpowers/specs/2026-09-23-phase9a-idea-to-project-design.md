# Phase 9a — Ідея → проєкт (skill-керована автоматизація) — design

Джерело вимог: `ROADMAP.md`, Phase 9 ("Content pipeline automation: 'клонування'
відео та ідея → проєкт"). Phase 9 об'єднує два входи — референс-відео і
текстову ідею — але вони вимагають абсолютно різних нових можливостей
(завантаження+транскрипція зовнішнього відео vs. дослідження теми), тож
фазу розбито: **Phase 9a** закриває лише текстовий вхід ("переказ
Кобзаря", "топ авто по бюджетах" тощо → повністю налаштований
`ContentProject` + тестове відео). Клонування референс-відео лишається
окремою майбутньою під-фазою (Phase 9b), яка спиратиметься на спільний
"хвіст" (auto-configure → test-generate → self-review), закладений тут.

## Проблема

Сьогодні створення `ContentProject` можливе лише вручну через Filament
(`ContentProjectForm`) — жодного CLI/API-шляху немає. Запуск генерації
відео (`ContentIdea` → `Script` → ... → рендер) вимагає вже існуючого
проєкту й ручного вибору topic через Filament row-action або Inertia
Console (`VideoController::generate()`). Немає жодного механізму, який
би сам: (1) розібрався, що за проєкт потрібен під довільний текстовий
задум користувача, (2) прогнав тестову генерацію, (3) оцінив її якість
змістовно (не лише технічно — `QualityCheckVideoJob`/`ffprobe` вже
перевіряє тривалість/чорні кадри, але не читає сам сценарій), і
(4) доопрацював за потреби, перш ніж віддати результат користувачу.

## 1. Хто "дослідує" і "перевіряє" — Claude Code, не новий LLM-сервіс

Ключове архітектурне рішення (підтверджене користувачем): дослідження
теми, вибір `niche`/`language`/`tone`/`style`/`target_platforms` і
змістовна самоперевірка сценарію — це робота **самого Claude Code під
час виконання skill**, а не додатковий LLM-виклик всередині застосунку.
Це узгоджується з ROADMAP-формулюванням ("ймовірний інтерфейс —
skill/slash-команда") і різко звужує обсяг нового PHP-коду: не
з'являється жоден новий `*ServiceProvider`, provider-чейн чи
`purpose=project_brief` запис у `config/llm.php` — увесь "розум"
живе в тексті skill-інструкції, а PHP отримує лише тонкі, тестовані
адаптери для того, чого зараз бракує в CLI.

Наслідок: не потрібен новий web-search provider. LLM-дослідження теми
(крок 2 нижче) спирається на власні знання Claude Code, без реального
web-пошуку — свідоме рішення користувача (YAGNI), фіксується тут, щоб
не сплутати з майбутнім Phase 9b, де для аналізу референс-відео вже
знадобиться реальне завантаження/транскрипція.

## 2. Три нові тонкі PHP-адаптери (без змін схеми БД і без змін Jobs)

Усі існуючі `Generate*Service` уже влаштовані як чисті функції без
побічних ефектів (`GenerateScriptService::generate()`,
`GenerateScenesService::generate()` — обидві лише читають вхідні
Eloquent-моделі й повертають масив, нічого не пишуть у БД;
персистить дані виключно `handle()` відповідного Job). Це дозволяє
"чернетку" (script+scenes) отримати, викликавши сервіси напряму, **без
створення жодного рядка в БД** — і без жодної зміни в
`GenerateScriptJob`/`GenerateScenesJob`/`VideoController`, окрім
винесення дубльованої логіки в спільний сервіс (п. 2.2).

### 2.1 `content-project:create` — новий CLI-шлях створення проєкту

```
php artisan content-project:create {name}
    {--slug=} {--description=}
    {--niche=} {--language=}
    {--platform=* : tiktok|youtube|instagram|x, повторюваний}
    {--tone=} {--style=}
    {--voice= : settings.tts.voice}
    {--ai=* : purpose:provider:model, повторюваний, напр. script:openai:gpt-4o-mini}
    {--status=active}
```

Валідація тими ж правилами, що вже застосовує Filament (`niche`,
`language` required; хоча б одна `--platform`; `platform` ∈
`SocialPlatform::cases()`). На успіх друкує `id` і `slug` створеного
проєкту (skill читає `id` зі stdout для наступних кроків), код виходу
`SUCCESS`. На помилку валідації — список причин у stderr, `FAILURE`,
проєкт не створюється.

Закриває реальний, вже задокументований у ROADMAP гап (Filament-only
створення), корисний і поза межами Phase 9.

### 2.2 `StartVideoGenerationService` + `content-idea:generate` — CLI-паритет з існуючим HTTP-флоу

`VideoController::generate()` (`app/Http/Controllers/Console/VideoController.php:51-72`)
сьогодні інлайн робить: згенерувати `ContentIdea` через
`GenerateContentIdeaService`, позначити `Approved`, задиспатчити
`GenerateScriptJob`. Виношу це один-в-один у
`App\Domain\Content\Services\StartVideoGenerationService`:

```php
final class StartVideoGenerationService
{
    public function __construct(private readonly GenerateContentIdeaService $ideaService) {}

    public function generate(ContentProject $project, string $topic): ContentIdea
    {
        $idea = $this->ideaService->generate($project, $topic);
        $idea->update(['status' => ContentIdeaStatus::Approved]);
        GenerateScriptJob::dispatch($idea->id);

        return $idea;
    }
}
```

`VideoController::generate()` замінює своє тіло на виклик цього
сервісу (той самий `try/catch` → `withErrors`), поведінка й існуючий
`VideoControllerTest::test_generate_creates_an_approved_idea_and_dispatches_script_generation`
не змінюються. Новий `content-idea:generate {project} {topic}` викликає
той самий сервіс із CLI — запускає **весь існуючий автоматичний
ланцюжок** (`GenerateScriptJob → GenerateScenesJob → GenerateVoiceoverJob
→ CollectVideoAssetsJob → GenerateSubtitlesJob → RenderVideoJob →
QualityCheckVideoJob`) без жодної зміни в jobs. Це — крок "фінальний
повний рендер" з п. 3.

### 2.3 `content-idea:draft {project} {title} {topic}` — чернетка без побічних ефектів

```
php artisan content-idea:draft {project} {title} {topic}
```

Будує **непе́рсистовані** (`setRelation()`, без `save()`) `ContentIdea`
і `Script` in-memory — `ContentIdea` отримує `content_project_id`
реального проєкту (relation `contentProject()` резолвиться в БД без
потреби зберігати саму ідею) і задані `title`/`topic`; `Script`
отримує ту саму непер систовану ідею через `setRelation('contentIdea',
...)`. Далі напряму викликає `LlmManager::resolve($project, 'script')`
→ `GenerateScriptService::generate($idea, $target)` →
`GenerateScenesService::generate($script, $target)` (де `$script->content`
і `$script->metadata['title']` виставлені з щойно отриманого
script-масиву перед викликом scenes-сервісу) і друкує в stdout один
JSON-блок:

```json
{"script": {"title": "...", "hook": "...", "script": "...", "estimated_duration": 42, "cta": "..."},
 "scenes": [{"type": "narration", "duration": 5, "visual_query": "...", "text": "..."}, ...]}
```

Нуль записів у БД (окрім читання самого проєкту), тож можна викликати
скільки завгодно разів без прибирання. Помилки генерації
(`ScriptGenerationFailedException`/`SceneGenerationFailedException`) —
у stderr, `FAILURE`.

**Свідомий компроміс:** `title`/`topic` для чернетки задає сам Claude
Code (уже дослідив тему на кроці 2 skill-флоу нижче), а не
`GenerateContentIdeaService` — команда не робить окремого LLM-виклику
для генерації ідеї. Це означає, що фінальний `content-idea:generate`
(п. 2.2) може згенерувати трохи інший `title`/`topic` тим самим
`GenerateContentIdeaService`, ніж той, що був у чернетці — прийнятно,
бо самоперевірка оцінює напрямок/тон/стиль сценарію, а не точний
збіг тексту. Альтернатива (персистити ідею й "промотувати" саме її на
фінальному кроці) відкладена — YAGNI, зайвий четвертий CLI-адаптер
заради точності, яка тут не критична.

## 3. Потік всередині skill (текстова інструкція, не PHP)

Нова project-scoped skill: `.claude/skills/idea-to-project/SKILL.md`.

1. Отримати від користувача сирий текстовий задум.
2. Самостійно дослідити тему (власні знання, без web-search) →
   сформулювати `niche`, `language`, `tone`, `style`, `target_platforms`,
   стартовий `topic`.
3. `content-project:create` → отримати `project_id`.
4. **Чернетка** (до 2 ітерацій): `content-idea:draft {project_id} {title} {topic}`
   → прочитати JSON script+scenes → самому оцінити відповідність
   задуму/якість → якщо не влаштовує, скоригувати `title`/`topic`/
   (за потреби — `tone`/`style` через прямий `ContentProject::update()`
   у tinker, бо на дрібне коригування settings окремий CLI-адаптер не
   виправданий) і повторити.
5. **Фінал**: `content-idea:generate {project_id} {topic}` → запускає
   реальний повний pipeline до рендеру. Дочекатись завершення (опитування
   `Video::status`/`failed_stage` через tinker; черга має реально
   виконуватись — `docker compose up`, `queue:work` — це передумова
   середовища, не частина skill-логіки).
6. Прочитати результат (`Video`, `QualityCheckVideoJob`-висновок). Якщо
   технічна чи змістовна перевірка провалилась — це вже фінальна
   ітерація (за дизайном, п. "Iteration depth" з брейнштормінгу: чернетки
   ловлять сценарні проблеми до дорогого рендеру, тож провал на фіналі
   означає технічну проблему поза скоупом skill, не привід рендерити
   втретє) — повідомити користувачу як є, не намагатись мовчки
   перезапускати.
7. Звітувати користувачу: посилання на відео в Console/Filament,
   коротке пояснення прийнятих рішень (niche/tone/style і чому).

## 4. Тестування

Нового Job/schema-коду немає — покриття зосереджене на трьох
адаптерах, `tests/Feature/Console/`:

- `ContentProjectCreateCommandTest` — повний набір опцій створює проєкт
  з очікуваним `settings` (tone/style/voice/ai-overrides); відсутній
  `--niche`/`--language`/`--platform` → `FAILURE`, проєкт не створено.
- `StartVideoGenerationServiceTest` (unit, `tests/Unit/Domain/Content/`)
  — `FakeLlmProvider`, стверджує: ідея `Approved`,
  `GenerateScriptJob` задиспатчено з правильним `contentIdeaId`.
  Існуючий `VideoControllerTest::test_generate_creates_an_approved_idea_and_dispatches_script_generation`
  лишається без змін і продовжує проходити — доказ, що рефакторинг
  контролера не зламав поведінку.
- `ContentIdeaGenerateCommandTest` — той самий сценарій через
  `Artisan::call('content-idea:generate', ...)`.
- `ContentIdeaDraftCommandTest` — `FakeLlmProvider` окремо на
  script- і scenes-виклик (той самий патерн двох послідовних
  fake-відповідей, що вже використовується в repair-loop тестах
  `GenerateScriptServiceTest`/`GenerateScenesServiceTest`); стверджує
  коректний JSON у виводі команди **і** що жодного рядка `ContentIdea`/
  `Script`/`Video`/`VideoScene` не з'явилось у БД (`assertDatabaseCount`
  до/після викликів команди).

## 5. Definition of Done

- `content-project:create`, `content-idea:generate`, `content-idea:draft`
  працюють, покриті Feature/Unit-тестами вище.
- `VideoController::generate()` делегує в `StartVideoGenerationService`,
  існуючий тест контролера не змінено і проходить.
- `.claude/skills/idea-to-project/SKILL.md` написано й слідує потоку
  з п. 3.
- Ручна перевірка: реальний прогін через skill (з реальними, не
  Fake-провайдерами) — від сирого текстового задуму до готового
  `ContentProject` + відрендереного тестового відео, з хоча б однією
  чернетковою ітерацією, що справді щось скоригувала.
- `php artisan test`, `vendor/bin/pint --test`, `php artisan route:list`
  (без нових HTTP-маршрутів — лише CLI), `php artisan migrate:fresh --seed`
  (без нових міграцій — жодних змін схеми) чисті.

## Поза скоупом Phase 9a

- Клонування референс-відео (Phase 9b) — завантаження/транскрипція
  зовнішнього відео, стильовий аналіз.
- Реальний web-search provider для дослідження теми.
- Console UI (Inertia-форма) для запуску цього флоу — залишається
  CLI/skill-only, як і домовлено з користувачем; Console-інтеграція —
  можлива майбутня ітерація, не тут.
- Коригування `settings.tone`/`settings.style` під час чернеткових
  ітерацій через окремий CLI-адаптер — досить прямого
  `ContentProject::update()` у tinker з рук skill-виконавця.
