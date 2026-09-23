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
