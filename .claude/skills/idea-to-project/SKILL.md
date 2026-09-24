---
name: idea-to-project
description: Use when the user gives a raw text idea/topic for AutoContent and wants a fully configured ContentProject plus a rendered test video — this skill researches the topic itself, configures niche/tone/style, drafts and self-reviews the script/scenes before committing to a full render.
---

# Ідея → проєкт (AutoContent)

Автоматизація Phase 9a: сирий текстовий задум користувача → повністю
налаштований `ContentProject` → перевірене тестове відео. Дослідження
теми і самоперевірка якості — це твоя (Claude) власна робота, не
LLM-виклик усередині застосунку. Деталі архітектури:
`docs/superpowers/specs/2026-09-23-phase9a-idea-to-project-design.md`.

## Передумови

- `docker compose up -d --build` піднято, черга реально споживається
  (`worker`/`horizon` контейнери) — інакше `content-idea:generate`
  ніколи не завершить рендер.
- Працюєш із реальними, не Fake-провайдерами (`.env` застосунку вже
  налаштований користувачем раніше) — це реальна генерація, не тест.

## Кроки

1. **Отримай задум.** Одне речення/абзац від користувача — тема,
   можливо побажання щодо тону/платформи.

2. **Досліди тему сам.** Власні знання, без web-search. Визнач:
   - `niche` — коротка категорія (напр. `philosophy`, `finance`, `tech_news`)
   - `language` — ISO-код (`en`, `uk`, ...)
   - `tone`/`style` — вільний текст (напр. tone=`calm`, style=`narrative`)
   - `target_platforms` — підмножина `tiktok`/`youtube`/`instagram`/`x`
   - стартовий `topic` — речення для `content-idea:generate`/`content-idea:draft`

3. **Створи проєкт:**

   ```bash
   php artisan content-project:create "Назва проєкту" \
     --niche=philosophy --language=en \
     --platform=tiktok --platform=youtube \
     --tone=calm --style=narrative
   ```

   Запам'ятай надрукований `id` проєкту.

4. **Чернетка (до 2 ітерацій).** Для кожної ітерації придумай
   `title`+`topic` сам (не через LLM-виклик застосунку) і виклич:

   ```bash
   php artisan content-idea:draft {project_id} "Заголовок" "topic текст"
   ```

   Команда нічого не пише в БД — можна викликати скільки завгодно
   разів. Прочитай JSON (`script.script`, `scenes[].text`) і сам
   оціни: чи відповідає задуму користувача, чи логічна структура сцен,
   чи немає фактичних помилок/нісенітниці. Якщо ні — зміни
   `title`/`topic` (і за потреби tone/style проєкту через
   `php artisan tinker --execute="App\Models\ContentProject::where('id', {id})->update(['settings->tone' => '...'])"`
   — саме через query builder (`::where(...)->update(...)`), НЕ через
   `::find({id})->update(...)`: Eloquent-форма мовчки відкидає ключ
   `'settings->tone'`, бо він не входить у `$fillable`, і апдейт не
   застосовується)
   і повтори. Максимум 2 повторні спроби чернетки (тобто до 3
   викликів `content-idea:draft` всього) — після цього переходь до
   фіналу з тим, що є, чесно попередивши користувача про залишкові
   сумніви.

5. **Фінал — повний рендер:**

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
   протягом короткого часу, поки `GenerateScriptJob`/`GenerateScenesJob`
   ще не завершились. Це не ознака помилки — просто зачекай і повтори
   запит.

   Статуси проходять `Draft → ScriptGenerated → VoiceGenerated →
   AssetsReady → Rendering → Rendered → Approved` (див.
   `docs/architecture.md` §2) — окремого статусу для перевірки якості
   немає: коли `status=Rendered`, `QualityCheckVideoJob` не міняє
   статус, а виставляє на тому ж рядку `Video` поля `quality_passed`
   (bool) і `quality_report` (масив `checks`/`notes`/`metadata`).
   Орієнтуйся саме на ці поля, а не на неіснуючий статус. `Approved` —
   ручна дія адміна, автоматичний пайплайн сам туди не доходить: для
   цілей цього skill фінальний очікуваний статус — `Rendered` (з
   `quality_passed`/`quality_report`, виставленими поруч). Якщо
   `status=Failed` — прочитай `failed_stage`/`error_message`, це вже
   технічна проблема поза скоупом цього skill (не намагайся мовчки
   перезапускати рендер втретє).

6. **Звітуй користувачу:**
   - Посилання на відео (`/console/videos` або `/admin/videos/{id}`)
     і на проєкт (`/admin/content-projects/{id}/edit`).
   - Коротко — які `niche`/`tone`/`style`/`target_platforms` обрано і
     чому, скільки чернеткових ітерацій знадобилось і що саме
     коригувалось.
   - Якщо фінал не пройшов технічну перевірку — чесно скажи це, не
     видавай за успіх.
