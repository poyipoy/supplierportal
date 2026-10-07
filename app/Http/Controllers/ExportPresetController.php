<?php

namespace App\Http\Controllers;

use App\Models\ExportPreset;
use App\Services\Export\ExportPresetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ExportPresetController extends Controller
{
    public function index(Request $request, ExportPresetService $service)
    {
        $data = $request->validate(['export_key' => ['required', 'string', 'max:64']]);
        $service->definition($data['export_key'], $request->user(), presets: true);

        return response()->json(['data' => ExportPreset::query()->where('user_id', $request->user()->id)->where('export_key', $data['export_key'])->orderBy('name')->get()->map(fn ($p) => $service->present($p, $request->user()))]);
    }

    private function input(Request $request, bool $creating): array
    {
        return $request->validate([
            'export_key' => [$creating ? 'required' : 'sometimes', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:80'],
            'columns' => ['required', 'array', 'min:1', 'max:64'],
            'columns.*' => ['string', 'distinct', 'regex:/^[a-z][a-z0-9_]{0,63}$/D'],
            'filters' => ['nullable', 'array'], 'format' => ['required', Rule::in(['xlsx', 'csv'])],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    public function store(Request $request, ExportPresetService $service)
    {
        $preset = $service->save($request->user(), $this->input($request, true));

        return response()->json($service->present($preset, $request->user()), 201);
    }

    public function update(Request $request, ExportPreset $preset, ExportPresetService $service)
    {
        Gate::authorize('update', $preset);
        $preset = $service->save($request->user(), $this->input($request, false), $preset);

        return response()->json($service->present($preset, $request->user()));
    }

    public function destroy(Request $request, ExportPreset $preset, ExportPresetService $service)
    {
        Gate::authorize('delete', $preset);
        $service->delete($request->user(), $preset);

        return response()->noContent();
    }

    public function makeDefault(Request $request, ExportPreset $preset, ExportPresetService $service)
    {
        Gate::authorize('update', $preset);
        $preset = $service->makeDefault($request->user(), $preset);

        return response()->json($service->present($preset, $request->user()));
    }
}
