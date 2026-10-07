<?php

namespace App\Http\Controllers;

use App\Services\Export\ExportPresetService;
use App\Support\Export\ExportDefinitions;
use Illuminate\Http\Request;

class ExportDefinitionController extends Controller
{
    public function show(Request $request, string $exportKey, ExportPresetService $service)
    {
        $definition = $service->definition($exportKey, $request->user());
        $fields = $definition->filterSchema();
        foreach ($fields as &$field) {
            $field['label'] = __('exports.advanced.filters.'.$field['name']);
        }
        unset($field);

        return response()->json([
            'export_key' => $exportKey, 'formats' => ['xlsx', 'csv'],
            'supports_columns' => ExportDefinitions::supportsColumns($definition),
            'supports_presets' => ExportDefinitions::supportsColumns($definition),
            'columns' => array_map(fn ($c) => ['key' => $c->key, 'label' => __('exports.headings.'.$c->headingKey).($c->headingSuffix ? ($c->headingSuffix)() : ''), 'default' => $c->default, 'required' => $c->required], ExportDefinitions::allowedColumns($definition)),
            'filters' => $fields,
        ]);
    }
}
