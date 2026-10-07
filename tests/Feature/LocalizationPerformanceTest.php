<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\JsTranslations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocalizationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    public function test_middleware_layout_and_translation_payload_reuse_one_preference_read(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'user_preferences')) {
                $queries++;
            }
        });
        $this->actingAs($user)->get(route('profile.customization'))->assertOk();
        for ($i = 0; $i < 100; $i++) {
            __('status.local_invoice.ready_to_pay');
            JsTranslations::payload();
        }
        $this->assertSame(1, $queries);
    }

    public function test_js_payload_is_bounded_to_the_declared_ui_domains_and_plain_data(): void
    {
        foreach (['en', 'id'] as $locale) {
            app()->setLocale($locale);
            $payload = JsTranslations::payload();
            $this->assertSame($locale, $payload['locale']);
            $this->assertNotEmpty($payload['messages']);
            foreach ($payload['messages'] as $key => $value) {
                $this->assertMatchesRegularExpression('/^(js\.|datatables\.|(?:purchasing|supplier|shipments)\.js\.)/', $key);
                $this->assertTrue(is_string($value) || is_array($value));
            }
            $this->assertArrayNotHasKey('user_id', $payload);
            $this->assertArrayNotHasKey('email', $payload);
            $this->assertArrayNotHasKey('password', $payload);
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->assertLessThan(16384, strlen($encoded), 'The JS dictionary must remain below 16 KiB.');
            $this->assertLessThan(5120, strlen(gzencode($encoded)), 'The compressed JS dictionary must remain below 5 KiB.');
        }
    }
}
