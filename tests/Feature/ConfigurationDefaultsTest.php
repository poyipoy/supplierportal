<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ConfigurationDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public static function invalidProductionSalts(): array
    {
        return [
            [''], ['  '], ['AdasiPortalSupplier2026_Secure'],
            ['CHANGE_ME_WITH_A_LONG_RANDOM_VALUE'], ['your-salt-string'],
            ['adasi-isolated-test-salt-never-use-in-production'],
        ];
    }

    #[DataProvider('invalidProductionSalts')]
    public function test_production_boot_rejects_missing_or_public_placeholder_salts(string $salt): void
    {
        $this->app->instance('env', 'production');
        config([
            'hashids.connections.main.salt' => $salt,
            'finance.limits.ga_claim_max_amount' => '100000000.00',
            'finance.limits.bank_fee_max_amount' => '100000.00',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HASHIDS_SALT');
        (new AppServiceProvider($this->app))->boot();
    }

    public function test_a_user_without_explicit_account_flags_starts_pending(): void
    {
        $user = User::create([
            'name' => 'Pending by default',
            'email' => 'pending-default@example.test',
            'password' => 'StrongPassword123!',
        ])->fresh();

        $this->assertSame(User::ACCOUNT_STATUS_PENDING, $user->account_status);
        $this->assertFalse($user->isImportEligible());
        $this->assertFalse($user->isLocalEligible());
    }

    public function test_dead_registration_and_payment_routes_are_absent(): void
    {
        $this->assertNull(app('router')->getRoutes()->getByName('finance.drp.mark-paid'));
        $this->assertNull(app('router')->getRoutes()->getByName('register'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Auth/RegisteredUserController.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Accounting/InvoiceWorkflowController.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Qc/QcController.php'));
        $this->assertFileDoesNotExist(resource_path('views/auth/register.blade.php'));
    }
}
