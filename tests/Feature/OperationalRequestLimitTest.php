<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OperationalRequestLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_budget_is_per_user_and_does_not_charge_get_confirmation_or_polling(): void
    {
        Route::middleware(['web', 'auth'])->match(['get', 'post'], '/limit-probe/export', fn () => response()->json(['ok' => true]))->name('purchasing.export.limit-probe');
        Route::middleware(['web', 'auth'])->get('/limit-probe/poll', fn () => response()->json(['ok' => true]))->name('exports.status.probe');
        $first = User::factory()->create(['role' => 'purchasing']);
        $second = User::factory()->create(['role' => 'purchasing']);
        $this->actingAs($first);
        for ($i = 0; $i < 12; $i++) {
            $this->getJson('/limit-probe/export')->assertOk();
        }
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/limit-probe/export')->assertOk();
        }
        $this->postJson('/limit-probe/export')->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/limit-probe/poll')->assertOk();
        $this->actingAs($second)->postJson('/limit-probe/export')->assertOk();
    }

    public function test_upload_budget_does_not_disable_the_existing_stricter_route_limit(): void
    {
        Route::middleware(['web', 'auth', 'throttle:3,1'])->post('/limit-probe/upload', fn () => response()->json(['ok' => true]))->name('local-supplier.invoices.store');
        $this->actingAs(User::factory()->create(['role' => 'supplier']));
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/limit-probe/upload')->assertOk();
        }
        $this->postJson('/limit-probe/upload')->assertStatus(429);
    }
}
