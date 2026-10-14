<?php

namespace App\Support;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ExportConfirmation
{
    public static function make(Request $request, string $label): View
    {
        $fields = [];
        self::appendFields($fields, $request->except('_token', '_method'), '');

        return view('exports.confirm', [
            'label' => $label,
            'action' => $request->getPathInfo(),
            'fields' => $fields,
        ]);
    }

    private static function appendFields(array &$fields, array $values, string $parent, int $depth = 0): void
    {
        if ($depth > 8) {
            return;
        }

        foreach ($values as $key => $value) {
            $name = $parent === '' ? (string) $key : $parent.'['.$key.']';
            if (is_array($value)) {
                self::appendFields($fields, $value, $name, $depth + 1);
            } elseif (is_scalar($value) || $value === null) {
                $fields[] = ['name' => $name, 'value' => $value ?? ''];
            }
        }
    }
}
