<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\EnvironmentTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvironmentTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_environment_tag_is_always_hidden_in_production_even_when_enabled_with_custom_label(): void
    {
        foreach (['production', 'prod', 'prd', 'PRODUCTION'] as $prodEnv) {
            config()->set('app.env', $prodEnv);
            config()->set('ui.environment_tag.enabled', true);
            config()->set('ui.environment_tag.label', 'LEAKED');

            $this->assertNull(EnvironmentTag::resolve(), "EnvironmentTag must return null for {$prodEnv}");
        }

        config()->set('app.env', 'production');
        config()->set('ui.environment_tag.enabled', true);
        config()->set('ui.environment_tag.label', 'LEAKED');

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('data-environment-tag=', false)
            ->assertDontSee('LEAKED', false);

        $user = User::factory()->create(['role' => 'purchasing']);
        $this->actingAs($user)
            ->get(route('purchasing.shipments.index'))
            ->assertOk()
            ->assertDontSee('data-environment-tag=', false)
            ->assertDontSee('LEAKED', false);
    }

    public function test_non_production_environments_render_tag_on_auth_and_navbar(): void
    {
        config()->set('ui.environment_tag.enabled', true);
        config()->set('ui.environment_tag.label', null);

        $cases = [
            ['local', 'LOCAL', 'info'],
            ['development', 'DEV', 'info'],
            ['staging', 'STG', 'warning'],
            ['uat', 'UAT', 'warning'],
            ['testing', 'TST', 'neutral'],
        ];

        foreach ($cases as [$env, $expectedCode, $expectedTone]) {
            config()->set('app.env', $env);

            $resolved = EnvironmentTag::resolve();
            $this->assertNotNull($resolved);
            $this->assertSame($expectedCode, $resolved['code']);
            $this->assertSame($expectedTone, $resolved['tone']);

            $this->get(route('login'))
                ->assertOk()
                ->assertSee('data-environment-tag="'.$expectedCode.'"', false)
                ->assertSee('data-environment-tone="'.$expectedTone.'"', false);
        }

        $user = User::factory()->create(['role' => 'purchasing']);

        foreach ($cases as [$env, $expectedCode, $expectedTone]) {
            config()->set('app.env', $env);

            $this->actingAs($user)
                ->get(route('purchasing.shipments.index'))
                ->assertOk()
                ->assertSee('data-environment-tag="'.$expectedCode.'"', false)
                ->assertSee('data-environment-tone="'.$expectedTone.'"', false);
        }
    }

    public function test_environment_tag_supports_sanitized_custom_label_and_disable_toggle(): void
    {
        config()->set('app.env', 'staging');
        config()->set('ui.environment_tag.enabled', true);
        config()->set('ui.environment_tag.label', 'stage-01<script>');

        $resolved = EnvironmentTag::resolve();
        $this->assertNotNull($resolved);
        $this->assertSame('STAGE-01SCRIPT', $resolved['code']);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('data-environment-tag="STAGE-01SCRIPT"', false)
            ->assertDontSee('<script>', false);

        config()->set('ui.environment_tag.enabled', false);
        $this->assertNull(EnvironmentTag::resolve());

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('data-environment-tag=', false);
    }
}
