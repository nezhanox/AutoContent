<?php

namespace Tests\Unit\Domain\Llm;

use App\Domain\Llm\LlmManager;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Models\ContentProject;
use Illuminate\Container\Container;
use Tests\TestCase;

class LlmManagerTest extends TestCase
{
    public function test_resolve_falls_back_to_global_default_with_no_project(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $manager = new LlmManager(Container::getInstance());

        $target = $manager->resolve(null, 'script');

        $this->assertSame('fake', $target->providerName);
        $this->assertSame('fake-model', $target->model);
        $this->assertInstanceOf(FakeLlmProvider::class, $target->provider);
    }

    public function test_resolve_prefers_project_purpose_setting_over_default(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $project = new ContentProject(['settings' => [
            'ai' => [
                'default' => ['provider' => 'fake', 'model' => 'fake-model'],
                'script' => ['provider' => 'fake', 'model' => 'purpose-specific-model'],
            ],
        ]]);

        $manager = new LlmManager(Container::getInstance());

        $target = $manager->resolve($project, 'script');

        $this->assertSame('fake', $target->providerName);
        $this->assertSame('purpose-specific-model', $target->model);
    }

    public function test_resolve_prefers_project_default_over_global_default(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'global-default-model');

        $project = new ContentProject(['settings' => [
            'ai' => ['default' => ['provider' => 'fake', 'model' => 'project-default-model']],
        ]]);

        $manager = new LlmManager(Container::getInstance());

        $target = $manager->resolve($project, 'idea');

        $this->assertSame('fake', $target->providerName);
        $this->assertSame('project-default-model', $target->model);
    }

    public function test_explicit_override_wins_over_everything(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'global-default-model');

        $project = new ContentProject(['settings' => [
            'ai' => [
                'default' => ['provider' => 'fake', 'model' => 'project-default-model'],
                'idea' => ['provider' => 'fake', 'model' => 'purpose-model'],
            ],
        ]]);

        $manager = new LlmManager(Container::getInstance());

        $target = $manager->resolve($project, 'idea', modelOverride: 'explicit-model');

        $this->assertSame('fake', $target->providerName);
        $this->assertSame('explicit-model', $target->model);
    }

    public function test_resolve_prefers_project_purpose_provider_over_project_default_provider(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $project = new ContentProject(['settings' => [
            'ai' => [
                'default' => ['provider' => 'fake'],
                'script' => ['provider' => 'fake_secondary'],
            ],
        ]]);

        $manager = new LlmManager(Container::getInstance());

        $target = $manager->resolve($project, 'script');

        // A genuinely different registered provider name, so a resolver that
        // always fell back to the global default could not pass this.
        $this->assertSame('fake_secondary', $target->providerName);
        $this->assertSame('fake', $manager->resolve($project, 'idea')->providerName);
    }

    public function test_explicit_provider_override_value_is_used_even_when_unconfigured(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $project = new ContentProject(['settings' => [
            'ai' => [
                'default' => ['provider' => 'fake', 'model' => 'fake-model'],
                'idea' => ['provider' => 'fake', 'model' => 'fake-model'],
            ],
        ]]);

        $manager = new LlmManager(Container::getInstance());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does-not-exist-provider');

        $manager->resolve($project, 'idea', providerOverride: 'does-not-exist-provider');
    }
}
