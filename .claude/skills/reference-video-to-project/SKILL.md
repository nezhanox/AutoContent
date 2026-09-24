---
name: reference-video-to-project
description: Use when the user gives a path to a local reference video (e.g. a saved TikTok clip) and wants AutoContent to configure a ContentProject that generates videos matching its style/structure — this skill extracts frames+transcript via a CLI adapter, inspects them itself, drafts and self-reviews a test script/scenes before committing to a full render.
---

# Референс-відео → проєкт (AutoContent)

Автоматизація Phase 9b: локальний референс-відеофайл користувача →
повністю налаштований `ContentProject`, що відтворює його стиль і
структуру → перевірене тестове відео. Аналіз кадрів/транскрипту і
самоперевірка якості — це твоя (Claude) власна робота, не LLM-виклик
усередині застосунку. Деталі архітектури:
`docs/superpowers/specs/2026-09-24-phase9b-reference-video-design.md`.

## Передумови

- `docker compose up -d --build` піднято, черга реально споживається
  (`worker`/`horizon` контейнери) — інакше `content-idea:generate`
  ніколи не завершить рендер.
- Працюєш із реальними, не Fake-провайдерами (`.env` застосунку вже
  налаштований користувачем раніше) — це реальна генерація, не тест.
- v1 приймає **тільки локальний файл** (шлях на диску, вже завантажений
  користувачем) — без завантаження за URL з TikTok/YouTube.

## Кроки

1. **Отримай шлях до референс-відео** від користувача (напр.
   `examples/videos/IMG_2514.MP4`).

2. **Проаналізуй його:**

   ```bash
   php artisan video-reference:analyze {path} --frames=8
   ```

   Команда нічого не пише в БД, лише друкує JSON з `duration`,
   `width`/`height`, `frames` (шляхи відносні до кореня проєкту),
   `transcript` (`null`, якщо в референсі немає аудіо-треку — тоді
   `notes` пояснює чому) і `notes`.

3. **Подивись на референс сам** (Claude, не LLM-виклик застосунку):
   - Відкрий кожен шлях з `frames` через `Read`-тул (це зображення,
     шлях у JSON — відносний до кореня проєкту, тож читай його як
     `{корінь_проєкту}/{шлях_з_frames}`) — оціни візуальний стиль
     (кольори, наявність тексту на екрані, композиція, чи це
     talking-head/b-roll/стокові кадри/анімація).
   - Якщо `transcript` не `null` — прочитай `transcript.segments`,
     оціни тон озвучки, темп мовлення, структуру (хук у перші секунди?
     CTA в кінці?), мову оригіналу.
   - Прикинь приблизну "щільність монтажу" зіставивши кількість кадрів
     і `duration` — v1 не робить точного scene-detection, це лише
     орієнтовна оцінка "швидкий монтаж" vs "довгі кадри", досить для
     нотатки в `style`.

4. **Визнач налаштування проєкту** (та сама структура, що в
   `idea-to-project`, плюс структурні нотатки в `style`):
   - `niche` — коротка категорія
   - `language` — ISO-код (з транскрипту, якщо є; інакше — з
     візуального контексту, напр. мова тексту на екрані)
   - `tone` — вільний текст
   - `style` — вільний текст, **явно включає** спостережений
     пейсинг/структуру (напр. "energetic, hook in first 2s, cuts every
     2-3s, bold on-screen captions, upbeat VO") — цей текст напряму
     йде в system-промпт генерації сценарію (`Style: %s`), тож жодних
     нових полів схеми/БД не існує і не потрібно
   - `target_platforms` — підмножина `tiktok`/`youtube`/`instagram`/`x`
   - Початковий `title`/`topic` для чернетки — **не копія** референсу
     (авторське право/оригінальність), а власна тема в тому самому
     стилі

5. **Створи проєкт:**

   ```bash
   php artisan content-project:create "Назва проєкту" \
     --niche=... --language=... \
     --platform=tiktok --platform=youtube \
     --tone="..." --style="..."
   ```

   Запам'ятай надрукований `id` проєкту.

6. **Чернетка (до 2 ітерацій).** Для кожної ітерації придумай
   `title`+`topic` сам (в тому самому стилі, що й референс) і виклич:

   ```bash
   php artisan content-idea:draft {project_id} "Заголовок" "topic текст"
   ```

   Команда нічого не пише в БД — можна викликати скільки завгодно
   разів. Прочитай JSON (`script.script`, `scenes[].text`) і сам
   оціни: чи витримано пейсинг/структуру/тон референсу, чи логічна
   структура сцен, чи немає фактичних помилок/нісенітниці. Якщо ні —
   зміни `title`/`topic` (і за потреби tone/style проєкту через
   `php artisan tinker --execute="App\Models\ContentProject::where('id', {id})->update(['settings->style' => '...'])"`
   — саме через query builder (`::where(...)->update(...)`), НЕ через
   `::find({id})->update(...)`: Eloquent-форма мовчки відкидає ключ
   `'settings->style'`, бо він не входить у `$fillable`, і апдейт не
   застосовується) і повтори. Максимум 2 повторні спроби чернетки
   (тобто до 3 викликів `content-idea:draft` всього) — після цього
   переходь до фіналу з тим, що є, чесно попередивши користувача про
   залишкові сумніви.

7. **Фінал — повний рендер:**

   ```bash
   php artisan content-idea:generate {project_id} "фінальний topic текст"
   ```

   Це реальна LLM-генерація ідеї (може дати трохи інший title/topic,
   ніж чернетка — нормально) + весь автоматичний ланцюжок job'ів до
   рендеру. Зачекай завершення, періодично перевіряючи статус:

   ```bash
   php artisan tinker --execute="dump(App\Models\Video::where('content_project_id', {project_id})->latest('id')->first(['id','status','failed_stage','error_message']))"
   ```

   Рядок `Video` створюється не одразу, а лише коли `GenerateScenesJob`
   доходить до відповідного кроку — тож одразу після запуску
   `content-idea:generate` цей запит може легітимно повернути `null`
   протягом короткого часу. Це не ознака помилки — просто зачекай і
   повтори запит.

   Статуси проходять `Draft → ScriptGenerated → VoiceGenerated →
   AssetsReady → Rendering → Rendered → Approved` (див.
   `docs/architecture.md` §2) — окремого статусу для перевірки якості
   немає: коли `status=Rendered`, `QualityCheckVideoJob` не міняє
   статус, а виставляє на тому ж рядку `Video` поля `quality_passed`
   (bool) і `quality_report` (масив `checks`/`notes`/`metadata`).
   `Approved` — ручна дія адміна, автоматичний пайплайн сам туди не
   доходить: фінальний очікуваний статус — `Rendered`. Якщо
   `status=Failed` — прочитай `failed_stage`/`error_message`, це вже
   технічна проблема поза скоупом цього skill (не намагайся мовчки
   перезапускати рендер втретє).

8. **Звітуй користувачу:**
   - Посилання на відео (`/console/videos` або `/admin/videos/{id}`)
     і на проєкт (`/admin/content-projects/{id}`).
   - Коротко — що саме зі стилю/структури референсу перенесено (пейсинг,
     тон, наявність тексту на екрані тощо), які `niche`/`tone`/`style`/
     `target_platforms` обрано, скільки чернеткових ітерацій знадобилось
     і що саме коригувалось.
   - Якщо в референсі не було аудіо — чесно скажи, що оцінка тону
     ґрунтувалась лише на візуальному ряді.
   - Якщо фінал не пройшов технічну перевірку — чесно скажи це, не
     видавай за успіх.
