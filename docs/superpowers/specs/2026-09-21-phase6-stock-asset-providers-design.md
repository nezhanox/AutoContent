# Phase 6 (частково) — Stock asset providers: design spec

Джерело: `TechnicalTask.md` (розділ 12 — "У майбутньому: Pexels, Pixabay,
Unsplash, AI image/video generator. MVP можна почати з локальних assets. Не
робити залежність від конкретного stock provider на першому етапі."),
`ROADMAP.md` (`## Phase 6+ — Post-MVP`: "додаткові asset-провайдери
(Pexels/Pixabay/Unsplash/AI generators)"; секція "Для Phase 6+ — врахувати (з
фінального review Phase 3c)"). Phase 3c (`2026-09-15-phase3c-assets-design.md`)
свідомо обмежилась `LocalAssetProvider` і залишила `AssetProviderInterface` без
прив'язки до конкретного stock-провайдера саме заради цього моменту.

Практичний привід: ручна генерація відео на нову (незнайому наперед) тему
показала, що `LocalAssetProvider` — це фіксована бібліотека з кількох вручну
завантажених файлів; для довільної теми `CollectVideoAssetsJob` майже гарантовано
падає з `AssetNotFoundException`, бо `visual_query`, який пише LLM (звичайні
англійські b-roll описи на кшталт "man waking up in bed with worried
expression"), ніколи не перетинається з вручну підібраними тегами локальних
файлів. Дашборд-кнопка "Generate Video" сьогодні непрацездатна для будь-якої
теми поза тим, що вже є в локальній бібліотеці.

## Мета

Від `Video` з готовою озвучкою (`VoiceGenerated`) до заповненого `asset_id` для
кожної сцени — так само, як зараз, але через живий пошук у зовнішніх
stock-бібліотеках за `visual_query`, з локальною бібліотекою як останнім
резервом, а не єдиним джерелом. `AssetProviderInterface`/`AssetSearchOptions`
(Phase 3c) і `CollectVideoAssetsService`/`CollectVideoAssetsJob` **не
змінюються** — вся робота відбувається за контрактом, який вже існує.

## Скоуп

Входить:

* `config/assets.php` — новий конфіг (той самий підхід, що `config/llm.php` і
  `config/tts.php`): порядок ланцюжка провайдерів + `api_key`/`base_url` для
  кожного.
* `app/Domain/Video/Providers/PixabayAssetProvider.php` — реалізує
  `AssetProviderInterface`. Фото- чи відео-пошук Pixabay REST API (вибір
  ендпоінта за `$options->types`), `orientation=vertical`, `safesearch=true`,
  найбільша доступна роздільність з відповіді.
* `app/Domain/Video/Providers/PexelsAssetProvider.php` — те саме для Pexels
  (`orientation=portrait`, авторизація через заголовок `Authorization`).
* `app/Domain/Video/Providers/ChainedAssetProvider.php` — реалізує той самий
  інтерфейс, обгортає впорядкований список провайдерів (Pixabay → Pexels →
  `LocalAssetProvider`). Виняток з одного провайдера (мережа, невалідний ключ,
  429/402 — вичерпана квота) ловиться й логується (канал `video`,
  `Log::warning`), пошук іде до наступного провайдера в списку. Перший
  непорожній результат — переможець; якщо всі провайдери повернули `[]` —
  повертає `[]` (як і зараз `LocalAssetProvider` у "нічого не знайдено"), і
  `CollectVideoAssetsService` вже вміє перетворити це на видиму помилку.
* `app/Providers/AssetServiceProvider.php` — біндинг `AssetProviderInterface` на
  `ChainedAssetProvider`, зібраний з конфігу (список провайдерів у порядку з
  `config('assets.chain')`).
* Завантажені файли зберігаються на диску
  (`storage/app/private/assets/stock/{provider}/{external_id}.{ext}`) і
  реєструються як звичайний рядок `MediaAsset` (`type` = `Image`/`Video`,
  `provider` = `pixabay`/`pexels`, `metadata` = `['source' => ..., 'query' =>
  ..., 'external_id' => ...]`). Дедуплікація — `MediaAsset::firstOrCreate(['path'
  => $path], [...])`: повторний пошук того самого зовнішнього файла (інше відео,
  той самий термін) не викачує його вдруге і не смітить у квоту.
* Feature/Unit-тести: по одному `Http::fake()`-тесту на кожен провайдер (за
  зразком наявного `ElevenLabsTtsProviderTest`), `ChainedAssetProviderTest` на
  порядок fallback і на "усі впали → `[]`", тест на біндинг у
  `AssetServiceProvider`.

Не входить (свідомо відкладено):

* Unsplash та AI image/video generators (розділ 12 ТЗ, "У майбутньому" — далі
  Phase 6+, не в межах цього кроку).
* Окремий API під власне анімовані `.gif` (Giphy/Tenor) — ні Pixabay, ні Pexels
  не віддають зациклені `.gif` через безкоштовне API; "анімація" тут — це
  короткі mp4-кліпи з їхнього video-пошуку, які зберігаються як звичайний
  `MediaAssetType::Video` і вже рендеряться (`FfmpegVideoRenderer` не
  розрізняє джерело відео-асета, просто циклить `-stream_loop -1` на
  тривалість сцени). **Рендерер не змінюється.**
* "Якісні головні сцени vs. другорядні" як окрема концепція — спрощено до
  "завжди брати найбільшу доступну роздільність", без диференціації за типом
  сцени.
* `LocalAssetProvider`'s відсутній `LIMIT`/GIN-індекс на `metadata->'tags'`
  (нотатка з Phase 3c review) — локальна бібліотека лишається малою й швидкою,
  індекс не потрібен, поки вона не стане основним джерелом.
* Виправлення `MediaAssetForm`'s `TagsInput::make('metadata.tags')`, який
  перезаписує весь `metadata` при збереженні через Filament (та сама нотатка
  Phase 3c review). **Ризик:** якщо адмін відредагує теги stock-завантаженого
  asset'а через форму, `source`/`query`/`external_id` буде втрачено без
  попередження. Не блокує цей крок (dedup working за `path`, не за
  `metadata`), але задокументовано як відомий борг.
* Дашборд/UI для перегляду залишку квоти провайдерів — квота перевіряється
  реактивно (провайдер впав → фолбек), без окремого лічильника чи індикатора.

## Рішення (там, де ТЗ не фіксує деталь явно)

### Чому ланцюжок, а не "менеджер з явним вибором провайдера на виклик"

`LlmManagerInterface`/TTS йдуть іншим шляхом — явний вибір провайдера на кожен
виклик (`providerOverride`), бо там вибір провайдера — свідоме рішення
продукту (вартість/якість моделі). Тут навпаки: провайдер для конкретного
`visual_query` не має продуктового значення, важливий тільки перший, хто
відповість. Ланцюжок з автоматичним fallback ближче до того, як
`CollectVideoAssetsService` вже трактує один провайдер (`search()` повертає
масив або кидає) — `ChainedAssetProvider` лишається прозорим для нього.

### API-контракти

Pixabay (`https://pixabay.com/api/` для фото, `https://pixabay.com/api/videos/`
для відео) — параметри `key`, `q`, `image_type=photo`, `orientation=vertical`,
`safesearch=true`, `per_page`; з відповіді беремо `hits[].largeImageURL`
(фото) чи `hits[].videos.large.url` (відео, з фолбеком на `medium`, якщо
`large` відсутній для конкретного файлу).

Pexels (`https://api.pexels.com/v1/search` — фото, `https://api.pexels.com/videos/search`
— відео) — заголовок `Authorization: {api_key}`, параметри `query`,
`orientation=portrait`, `per_page`; з відповіді беремо `photos[].src.original`
(фото) чи найбільший `video_files[]` за `width*height` (відео).

### Мапінг `AssetSearchOptions->types` на ендпоінт

```php
$wantsImage = array_intersect($options->types, [MediaAssetType::Image, MediaAssetType::Screenshot]) !== [];
$wantsVideo = array_intersect($options->types, [MediaAssetType::Video, MediaAssetType::ScreenRecording]) !== [];
```

`ScreenRecording`/`Thumbnail`/`Subtitle`/`Audio` не мають сенсу для
stock-пошуку — і Pixabay-, і Pexels-провайдер повертають `[]` одразу, не
роблячи запиту, якщо жоден з `types` не мапиться на фото чи відео (ланцюжок
одразу йде до `LocalAssetProvider`, як і зараз для цих типів).

### Обробка помилок усередині одного провайдера

`Http::timeout(15)->get(...)` без `->throw()`; перевірка `$response->failed()`
чи `$response->clientError()`/`serverError()` → `Log::warning('asset-provider
request failed', [...])` і `return []` замість винятку. Виняток назовні (з
`search()`) не кидається ніколи — контракт `ChainedAssetProvider` спирається
на "порожній масив = спробуй наступного", а не на try/catch навколо кожного
виклику.

### Чому `firstOrCreate` за `path`, а не за `hash` файлу

`hash` рахується з байтів файлу — довелось би спершу викачати, тоді
перевіряти дублікат, тоді викидати викачане. `path` детерміновано будується з
`{provider}/{external_id}` ще до мережевого виклику — дедуп-перевірка йде
перед завантаженням, економлячи і трафік, і квоту.

## Ризики / edge cases

* **Немає API-ключів на момент написання цього документа** — `Pixabay
  Http::fake()`/`Pexels Http::fake()` покривають логіку без живих ключів;
  перший реальний прогін через дашборд можливий тільки після того, як
  власник продукту зареєструється на обох сервісах і надасть ключі
  (`PIXABAY_API_KEY`, `PEXELS_API_KEY` у `.env`).
* **NSFW/недоречний контент.** `safesearch=true` є в Pixabay; у Pexels
  окремого прапорця немає (каталог курований самим сервісом) — прийнятний
  рівень ризику для MVP цього кроку, без додаткової модерації на нашій
  стороні.
* **Авторські права/ліцензія.** Обидва сервіси видають вміст під власними
  безкоштовними ліцензіями без обов'язкової атрибуції для комерційного
  використання — не перевіряється програмно, покладаємось на ToS сервісів.
* **Мова `visual_query`.** LLM сьогодні пише запити англійською (підтверджено
  на реальному прогоні) — обидва API шукають англійською нативно, додаткового
  перекладу не потрібно.

## Acceptance criteria

* Натискання "Generate Video" в дашборді для довільної (раніше не бачену)
  теми більше не падає на кроці збору активів через відсутність збігу тегів —
  `CollectVideoAssetsJob` знаходить відповідні фото/відео через Pixabay чи
  Pexels.
* Штучне вимкнення/збій одного провайдера (невалідний ключ, `Http::fake` з
  500/429 у тесті) не ламає job — результат приходить від наступного в
  ланцюжку.
* Повторний пошук за тим самим `visual_query` в іншому відео не робить
  повторного мережевого запиту для вже завантаженого файлу (дедуп за `path`).
* `FfmpegVideoRenderer` не змінено; відео-результати stock-пошуку рендеряться
  так само, як локальні відео-assets.
* `php artisan test`, `vendor/bin/pint --test` проходять чисто.
