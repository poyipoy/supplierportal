<?php

namespace App\Exports\Advanced;

use App\Models\User;

interface ExportDefinition
{
    public function key(): string;

    public function exportClass(): string;

    public static function columns(): array;

    public function filterSchema(): array;

    public function authorize(User $user): bool;
}
