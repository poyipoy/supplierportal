<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthSecurityServiceProvider;
use Maatwebsite\Excel\ExcelServiceProvider;
use Vinkla\Hashids\HashidsServiceProvider;
use Yajra\DataTables\DataTablesServiceProvider;

return [
    AppServiceProvider::class,
    AuthSecurityServiceProvider::class,
    HashidsServiceProvider::class,
    DataTablesServiceProvider::class,
    ExcelServiceProvider::class,
];
