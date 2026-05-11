<?php

namespace Database\Seeders;

use App\Models\ExchangeRate;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // ─── Admin ───
        $admin = User::create([
            'name'     => 'Administrator',
            'email'    => 'admin@adasi.com',
            'password' => Hash::make('password'),
            'role'     => 'admin',
            'is_active' => true,
        ]);

        // ─── Purchasing ───
        User::create([
            'name'     => 'Purchasing ADASI',
            'email'    => 'purchasing@adasi.com',
            'password' => Hash::make('password'),
            'role'     => 'purchasing',
            'is_active' => true,
        ]);

        // ─── QC ───
        User::create([
            'name'     => 'QC Inspector',
            'email'    => 'qc@adasi.com',
            'password' => Hash::make('password'),
            'role'     => 'qc',
            'is_active' => true,
        ]);

        // ─── Supplier 1 ───
        $supplier1 = User::create([
            'name'     => 'Supplier Satu',
            'email'    => 'supplier1@test.com',
            'password' => Hash::make('password'),
            'role'     => 'supplier',
            'is_active' => true,
        ]);

        Supplier::create([
            'user_id'      => $supplier1->id,
            'company_name' => 'PT. Supplier Satu',
            'address'      => 'Jl. Industri No. 1, Karawang',
            'phone'        => '021-12345678',
            'npwp'         => '01.234.567.8-012.000',
            'category'     => 'Steel',
        ]);

        // ─── Supplier 2 ───
        $supplier2 = User::create([
            'name'     => 'Supplier Dua',
            'email'    => 'supplier2@test.com',
            'password' => Hash::make('password'),
            'role'     => 'supplier',
            'is_active' => true,
        ]);

        Supplier::create([
            'user_id'      => $supplier2->id,
            'company_name' => 'PT. Supplier Dua',
            'address'      => 'Jl. Industri No. 2, Bekasi',
            'phone'        => '021-87654321',
            'npwp'         => '09.876.543.2-098.000',
            'category'     => 'Steel',
        ]);

        // ─── Exchange Rates (valid_from: hari ini) ───
        ExchangeRate::create([
            'currency'    => 'USD',
            'rate_to_idr' => 16200.0000,
            'valid_from'  => now()->toDateString(),
            'created_by'  => $admin->id,
        ]);

        ExchangeRate::create([
            'currency'    => 'JPY',
            'rate_to_idr' => 108.0000,
            'valid_from'  => now()->toDateString(),
            'created_by'  => $admin->id,
        ]);
    }
}
