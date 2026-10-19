<?php

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__.'/density-browser-bootstrap.php';
if (($argv[1] ?? '') !== '--prepare') {
    echo json_encode(['database' => DB::selectOne('SELECT DATABASE() AS db')->db, 'density_column' => DB::selectOne("SHOW COLUMNS FROM user_preferences LIKE 'density'")->Type], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
$actors = DB::transaction(function (): array {
    $actors = [];
    foreach (['admin' => 'admin', 'purchasing' => 'purchasing', 'finance' => 'finance', 'accounting' => 'accounting', 'qc' => 'qc', 'ga' => 'ga', 'import' => 'supplier', 'local' => 'supplier', 'dual' => 'supplier'] as $context => $role) {
        $email = 'density-g2-'.$context.'@example.test';
        $user = User::where('email', $email)->first();
        if (! $user) {
            $user = User::factory()->create(['name' => 'Density QA '.ucfirst($context), 'email' => $email,
                'role' => $role, 'password' => 'DensityPreview!2026', 'is_active' => true,
                'account_status' => User::ACCOUNT_STATUS_ACTIVE, 'must_change_password' => false]);
            if ($role === 'supplier') {
                if ($context === 'local') {
                    $user->supplierScopes()->where('scope', 'import')->delete();
                }
                if ($context !== 'import') {
                    $user->supplierScopes()->firstOrCreate(['scope' => 'local']);
                }
                Supplier::create(['user_id' => $user->id, 'company_name' => 'Density Example '.ucfirst($context),
                    'address' => 'Synthetic QA address', 'phone' => '000000', 'npwp' => 'DENSITY-'.$user->id,
                    'category' => 'QA', 'payment_term_days' => 30]);
            }
            $user->preference()->create(config('user_preferences.defaults'));
        }
        if ($user->role !== $role || ! $user->is_active || $user->account_status !== User::ACCOUNT_STATUS_ACTIVE) {
            throw new RuntimeException('Existing fixture identity mismatch; refusing to modify it.');
        }
        $actors[$context] = ['id' => $user->id, 'email' => $email];
    }

    return $actors;
});
echo json_encode(['database' => 'adasi_portal_test', 'actors' => $actors], JSON_THROW_ON_ERROR).PHP_EOL;
