<?php

namespace App\Console\Commands;

use App\Models\ContentProject;
use App\Models\Enums\SocialPlatform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateContentProjectCommand extends Command
{
    protected $signature = 'content-project:create
        {name : Project display name}
        {--slug= : URL slug (defaults to a slugified name)}
        {--description= : Optional description}
        {--niche= : Content niche}
        {--language= : ISO language code}
        {--platform=* : Target platform(s): tiktok|youtube|instagram|x, repeatable}
        {--tone= : settings.tone free text}
        {--style= : settings.style free text}
        {--voice= : settings.tts.voice (ElevenLabs voice id)}
        {--ai=* : purpose:provider:model, repeatable, e.g. script:openai:gpt-4o-mini}
        {--status=active : Project status}';

    protected $description = 'Create a ContentProject with full settings from the CLI (no Filament UI required).';

    public function handle(): int
    {
        $platforms = $this->option('platform');
        $name = $this->argument('name');
        $slug = $this->option('slug') ?? Str::slug($name);

        $validator = Validator::make([
            'name' => $name,
            'niche' => $this->option('niche'),
            'language' => $this->option('language'),
            'platforms' => $platforms,
            'slug' => $slug,
        ], [
            'name' => ['required', 'string'],
            'niche' => ['required', 'string'],
            'language' => ['required', 'string'],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['string', Rule::in(array_map(
                fn (SocialPlatform $platform): string => $platform->value,
                SocialPlatform::cases(),
            ))],
            'slug' => ['required', 'string', Rule::unique('content_projects', 'slug')],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $settings = [];

        if ($this->option('tone') !== null) {
            $settings['tone'] = $this->option('tone');
        }

        if ($this->option('style') !== null) {
            $settings['style'] = $this->option('style');
        }

        if ($this->option('voice') !== null) {
            $settings['tts'] = ['voice' => $this->option('voice')];
        }

        $aiSettings = [];
        foreach ($this->option('ai') as $spec) {
            [$purpose, $provider, $model] = array_pad(explode(':', $spec, 3), 3, null);

            if ($purpose === null || $provider === null || $model === null) {
                $this->error("Invalid --ai value [{$spec}], expected purpose:provider:model.");

                return self::FAILURE;
            }

            $aiSettings[$purpose] = ['provider' => $provider, 'model' => $model];
        }

        if (! empty($aiSettings)) {
            $settings['ai'] = $aiSettings;
        }

        $project = ContentProject::create([
            'name' => $name,
            'slug' => $slug,
            'description' => $this->option('description') ?? '',
            'niche' => $this->option('niche'),
            'language' => $this->option('language'),
            'target_platforms' => $platforms,
            'status' => $this->option('status'),
            'settings' => $settings,
        ]);

        $this->info("Created ContentProject #{$project->id} ({$project->slug}).");

        return self::SUCCESS;
    }
}
