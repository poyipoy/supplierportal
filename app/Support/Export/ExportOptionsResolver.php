<?php

namespace App\Support\Export;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ExportOptionsResolver
{
    public static function resolve(string $key, User $actor, array $input): ExportOptions
    {
        try {
            $definition = ExportDefinitions::get($key);
            abort_unless($definition->authorize($actor), 403);
            $keys = $input['columns'] ?? ExportDefinitions::defaultKeys($definition);
            $options = new ExportOptions($keys, $input['format'] ?? 'xlsx', explode('.', $key, 2)[0]);
            ExportDefinitions::sanitizeKeys($definition, $options->columns);

            return $options;
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['options.columns' => __('exports.advanced.invalid_columns')]);
        }
    }

    public static function authorizeStored(string $class, User $actor, ExportOptions $options): void
    {
        $definition = ExportDefinitions::forClass($class, $options->audience);
        abort_unless($definition->authorize($actor), 403);
        ExportDefinitions::sanitizeKeys($definition, $options->columns);
    }
}
