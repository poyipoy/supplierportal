<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class RegionalSettingsUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_regional_controls_have_labels_examples_and_error_associations(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->get(route('profile.customization'))->assertOk();
        $response->assertSee('Regional Preferences')->assertSee('Calendar date')->assertSee('event instant');
        foreach (['timezone', 'date_format', 'time_format', 'number_format'] as $field) {
            $response->assertSee('for="regional-'.$field.'"', false);
            $response->assertSee('id="regional-'.$field.'"', false);
            $response->assertSee('name="'.$field.'"', false);
            $response->assertSee('regional-'.$field.'-help', false);
        }
        $response->assertDontSee('Asia/Makassar')->assertDontSee('Asia/Jayapura');
        $response->assertDontSee('aria-invalid=" false "', false);
        $response->assertDontSee('aria-invalid=" true "', false);
        $response->assertSee('aria-invalid="false"', false);
    }

    public function test_old_regional_values_are_restored_using_only_trusted_option_labels(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->withSession(['_old_input' => [
            'timezone' => 'Asia/Jakarta', 'date_format' => 'dmy',
            'time_format' => '12h', 'number_format' => 'indonesian',
        ]])->get(route('profile.customization'))->assertOk()
            ->assertSee('value="Asia/Jakarta" selected', false)
            ->assertSee('value="dmy" selected', false)
            ->assertSee('value="12h" selected', false)
            ->assertSee('value="indonesian" selected', false);
    }

    public function test_regional_errors_are_associated_with_controls_and_hostile_old_input_is_not_rendered(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag([
            'timezone' => ['Choose a supported timezone.'],
            'date_format' => ['Choose a supported date format.'],
            'time_format' => ['Choose a supported time format.'],
            'number_format' => ['Choose a supported number format.'],
        ]));
        $response = $this->actingAs($user)->withSession([
            'errors' => $errors,
            '_old_input' => ['timezone' => '<script>alert(1)</script>'],
        ])->get(route('profile.customization'))->assertOk();
        foreach (['timezone', 'date_format', 'time_format', 'number_format'] as $field) {
            $response->assertSee('aria-describedby="regional-'.$field.'-help regional-'.$field.'-error"', false);
            $response->assertSee('id="regional-'.$field.'-error"', false);
        }
        $response->assertSee('aria-invalid="true"', false);
        $response->assertDontSee('<script>alert(1)</script>', false);
    }
}
