<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MaintenanceRecordRequest;
use App\Models\MaintenanceRecord;
use App\Services\Maintenance\MaintenanceResolver;
use App\Services\Maintenance\MaintenanceService;
use App\Support\BusinessTime;
use App\Support\Maintenance\MaintenanceModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MaintenanceController extends Controller
{
    public function __construct(
        private readonly MaintenanceService $service,
        private readonly MaintenanceModuleRegistry $registry,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'type' => ['nullable', 'string', Rule::in(MaintenanceRecord::TYPES)],
            'status' => ['nullable', 'string', Rule::in(MaintenanceRecord::DISPLAY_STATUSES)],
            'module' => ['nullable', 'string', Rule::in($this->registry->keys())],
        ]);
        $now = BusinessTime::now();

        $query = MaintenanceRecord::query()->with(['modules', 'creator:id,name']);
        match ($filters['status'] ?? null) {
            MaintenanceRecord::DISPLAY_DELETED => $query->onlyTrashed(),
            MaintenanceRecord::STATUS_DRAFT => $query->where('status', MaintenanceRecord::STATUS_DRAFT),
            MaintenanceRecord::STATUS_SCHEDULED => $query->upcoming($now),
            MaintenanceRecord::STATUS_ACTIVE => $query->runningOnTime($now),
            MaintenanceRecord::DISPLAY_OVERDUE => $query->overdue($now),
            MaintenanceRecord::STATUS_COMPLETED => $query->where('status', MaintenanceRecord::STATUS_COMPLETED),
            default => null,
        };

        $records = $query
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->where('title', 'like', '%'.addcslashes(trim($term), '\\%_').'%'))
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($filters['module'] ?? null, fn ($query, string $module) => $query
                ->where('type', MaintenanceRecord::TYPE_MODULE)
                ->whereHas('modules', fn ($modules) => $modules->where('module_key', $module)))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $resolver = new MaintenanceResolver($now);

        return view('admin.maintenance.index', [
            'records' => $records,
            'filters' => $filters,
            'now' => $now,
            'registry' => $this->registry,
            'globalRecords' => $resolver->globalRecords(),
            'blockedModules' => $resolver->blockedModuleCounts(),
            'counts' => [
                MaintenanceRecord::STATUS_SCHEDULED => MaintenanceRecord::query()->upcoming($now)->count(),
                MaintenanceRecord::DISPLAY_OVERDUE => MaintenanceRecord::query()->overdue($now)->count(),
                MaintenanceRecord::STATUS_DRAFT => MaintenanceRecord::query()->where('status', MaintenanceRecord::STATUS_DRAFT)->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.maintenance.create', [
            'record' => null,
            'stage' => MaintenanceRecordRequest::STAGE_PLAN,
            'registry' => $this->registry,
        ]);
    }

    public function store(MaintenanceRecordRequest $request): RedirectResponse
    {
        $record = $this->service->create($request->user(), $request->payload(), $request->action());

        return redirect()
            ->route('admin.maintenance.show', $record)
            ->with('success', __('maintenance.flash.saved_'.$request->action()));
    }

    public function show(MaintenanceRecord $maintenance): View
    {
        $maintenance->load(['modules', 'creator:id,name', 'updater:id,name']);
        $now = BusinessTime::now();
        $resolver = new MaintenanceResolver($now);

        $otherBlockers = [];
        foreach ($maintenance->modules as $module) {
            $otherBlockers[$module->module_key] = $resolver->otherBlockersFor($maintenance, $module->module_key);
        }

        return view('admin.maintenance.show', [
            'record' => $maintenance,
            'now' => $now,
            'registry' => $this->registry,
            'otherBlockers' => $otherBlockers,
            'stage' => MaintenanceRecordRequest::stageOf($maintenance->trashed() ? null : $maintenance),
        ]);
    }

    public function edit(MaintenanceRecord $maintenance): View
    {
        return view('admin.maintenance.edit', [
            'record' => $maintenance->load('modules'),
            'stage' => MaintenanceRecordRequest::stageOf($maintenance),
            'registry' => $this->registry,
        ]);
    }

    public function update(MaintenanceRecordRequest $request, MaintenanceRecord $maintenance): RedirectResponse
    {
        $this->service->update($request->user(), $maintenance, $request->payload(), $request->action());

        return redirect()
            ->route('admin.maintenance.show', $maintenance)
            ->with('success', __('maintenance.flash.saved_'.$request->action()));
    }

    public function activate(Request $request, MaintenanceRecord $maintenance): RedirectResponse
    {
        $this->service->activate($request->user(), $maintenance);

        return redirect()->route('admin.maintenance.show', $maintenance)->with('success', __('maintenance.flash.activated'));
    }

    public function deactivate(Request $request, MaintenanceRecord $maintenance): RedirectResponse
    {
        $this->service->deactivate($request->user(), $maintenance);

        return redirect()->route('admin.maintenance.show', $maintenance)->with('success', __('maintenance.flash.deactivated'));
    }

    public function deactivateModule(Request $request, MaintenanceRecord $maintenance, string $module): RedirectResponse
    {
        abort_unless($this->registry->has($module), 404);
        $this->service->deactivateModule($request->user(), $maintenance, $module);

        return redirect()
            ->route('admin.maintenance.show', $maintenance)
            ->with('success', __('maintenance.flash.module_deactivated', ['module' => $this->registry->label($module)]));
    }

    public function destroy(Request $request, MaintenanceRecord $maintenance): RedirectResponse
    {
        $this->service->delete($request->user(), $maintenance);

        return redirect()->route('admin.maintenance.index')->with('success', __('maintenance.flash.deleted'));
    }
}
