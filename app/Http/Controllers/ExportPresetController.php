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
        $user = $request->user();
        $exportKey = $data['export_key'];
        $service->definition($exportKey, $user, presets: true);

        $personal = ExportPreset::query()
            ->where('user_id', $user->id)
            ->where('export_key', $exportKey)
            ->where('scope', ExportPreset::SCOPE_PERSONAL)
            ->orderBy('name')
            ->get()
            ->map(fn (ExportPreset $preset) => $service->present($preset, $user))
            ->values();

        $team = (! $user->isSupplier() && ! str_starts_with($exportKey, 'supplier.'))
            ? ExportPreset::query()
                ->where('export_key', $exportKey)
                ->where('scope', ExportPreset::SCOPE_TEAM)
                ->orderBy('name')
                ->get()
                ->map(fn (ExportPreset $preset) => $service->present($preset, $user))
                ->values()
            : collect();

        return response()->json([
            'data' => $personal->concat($team)->values(),
            'personal' => $personal,
            'team' => $team,
            'can_manage_team' => Gate::forUser($user)->allows('createTeam', [ExportPreset::class, $exportKey]),
        ]);
    }

    private function input(Request $request, bool $creating): array
    {
        return $request->validate([
            'export_key' => [$creating ? 'required' : 'sometimes', 'string', 'max:64'],
            'scope' => ['sometimes', Rule::in([ExportPreset::SCOPE_PERSONAL, ExportPreset::SCOPE_TEAM])],
            'name' => ['required', 'string', 'max:80'],
            'columns' => ['required', 'array', 'min:1', 'max:64'],
            'columns.*' => ['string', 'distinct', 'regex:/^[a-z][a-z0-9_]{0,63}$/D'],
            'filters' => ['nullable', 'array'],
            'format' => ['required', Rule::in(['xlsx', 'csv'])],
            'csv_delimiter' => ['sometimes', Rule::in([',', ';'])],
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
        Gate::authorize('makeDefault', $preset);
        $preset = $service->makeDefault($request->user(), $preset);

        return response()->json($service->present($preset, $request->user()));
    }

    public function duplicate(Request $request, ExportPreset $preset, ExportPresetService $service)
    {
        Gate::authorize('view', $preset);
        $validated = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        $copy = $service->duplicateToPersonal($request->user(), $preset, $validated['name'] ?? null);

        return response()->json($service->present($copy, $request->user()), 201);
    }
}
