<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthSecurityServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Maatwebsite\Excel\ExcelServiceProvider;
use Technikermathe\LucideIcons\BladeLucideIconsServiceProvider;
use Vinkla\Hashids\HashidsServiceProvider;
use Yajra\DataTables\DataTablesServiceProvider;

return [
    AppServiceProvider::class,
    AuthSecurityServiceProvider::class,
    BladeIconsServiceProvider::class,
    BladeLucideIconsServiceProvider::class,
    HashidsServiceProvider::class,
    DataTablesServiceProvider::class,
    ExcelServiceProvider::class,
];
