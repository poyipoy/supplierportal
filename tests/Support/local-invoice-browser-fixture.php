<?php

use App\Models\Supplier;
use App\Models\User;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$app = require __DIR__.'/local-invoice-browser-bootstrap.php';
foreach (['local' => ['local'], 'both' => ['import', 'local'], 'import' => ['import'], 'accounting' => [], 'finance' => []] as $name => $scopes) {
    $user = User::updateOrCreate(['email' => $name.'-browser@example.test'], ['name' => ucfirst($name).' Browser QA', 'password' => 'LocalBrowser!2026', 'role' => $scopes ? 'supplier' : $name, 'is_active' => true]);
    if ($scopes) {
        Supplier::updateOrCreate(['user_id' => $user->id], ['company_name' => 'QA '.ucfirst($name).' Supplier', 'address' => 'QA Address', 'phone' => '123', 'npwp' => '123', 'category' => 'QA', 'payment_term_days' => 30]);
        foreach ($scopes as $scope) {
            $user->supplierScopes()->firstOrCreate(['scope' => $scope]);
        }
    }
}
echo "Browser fixtures created in adasi_portal_test only.\n";
