<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    Vinkla\Hashids\HashidsServiceProvider::class,
    Yajra\DataTables\DataTablesServiceProvider::class,
    Maatwebsite\Excel\ExcelServiceProvider::class,
];
