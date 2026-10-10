<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierAudit\CancelSupplierAuditRequest;
use App\Http\Requests\SupplierAudit\ChangeSupplierAuditDeadlineRequest;
use App\Http\Requests\SupplierAudit\RequestSupplierAuditRevisionRequest;
use App\Http\Requests\SupplierAudit\StoreSupplierAuditAssignmentRequest;
use App\Http\Requests\SupplierAudit\UploadSupplierAuditResultRequest;
use App\Models\SupplierAudit;
use App\Models\SupplierAuditTemplate;
use App\Models\User;
use App\Services\SupplierAudit\SupplierAuditAssignmentService;
use App\Services\SupplierAudit\SupplierAuditReviewService;
use App\Support\ServerTabsResponse;
use App\Support\StatusHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SupplierAuditController extends Controller
{
    /** Tab antrean kerja (null = semua; `late` memakai scope late()). */
    public const QUEUES = [
        'review' => [SupplierAudit::STATUS_SUBMITTED],
        'waiting' => SupplierAudit::SUPPLIER_EDITABLE_STATUSES,
        'late' => [],
        'done' => [SupplierAudit::STATUS_RESULT_PUBLISHED, SupplierAudit::STATUS_CANCELLED],
        'all' => null,
    ];

    public function index(Request $request)
    {
        Gate::authorize('viewAny', SupplierAudit::class);

        $filters = $request->validate([
            'queue' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::QUEUES))],
            'period' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'string', 'max:64'],
        ]);
        $supplier = $this->resolveSupplierFilter($filters['supplier'] ?? null);
        $queue = $filters['queue'] ?? 'review';

        // Filter periode/supplier berlaku untuk tabel dan hitungan tab.
        $scoped = fn () => SupplierAudit::query()
            ->when($filters['period'] ?? null, fn ($query, $period) => $query->where('period_label', $period))
            ->when($supplier, fn ($query) => $query->where('supplier_id', $supplier->id));

        $statusCounts = $scoped()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $queueCounts = collect(self::QUEUES)->map(fn ($statuses) => $statuses === null
            ? (int) $statusCounts->sum()
            : (int) collect($statuses)->sum(fn ($status) => (int) $statusCounts->get($status, 0)));
        $queueCounts['late'] = $scoped()->late()->count();

        $audits = $scoped()
            ->with(['supplier.supplier'])
            ->withAnswerProgress()
            ->when($queue === 'late', fn ($query) => $query->late())
            ->when($queue !== 'late' && self::QUEUES[$queue] !== null, fn ($query) => $query->whereIn('status', self::QUEUES[$queue]))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $supplierOptions = User::query()
            ->whereIn('id', SupplierAudit::query()->select('supplier_id'))
            ->with('supplier')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->hash => $user->supplier?->company_name ?: $user->name])
            ->all();

        $viewData = [
            'audits' => $audits,
            'filters' => $filters,
            'queue' => $queue,
            'queueCounts' => $queueCounts,
            'periodOptions' => SupplierAudit::query()->distinct()->orderByDesc('period_label')->pluck('period_label', 'period_label')->all(),
            'supplierOptions' => $supplierOptions,
        ];

        // Tab/filter/paginasi tanpa reload: tabel + nav (href membawa filter, hitungan segar) dirender ulang server.
        if (ServerTabsResponse::wants($request)) {
            return ServerTabsResponse::make(
                view('purchasing.supplier-audits._queue_content', $viewData)->render(),
                $queue,
                $request,
                ['nav' => view('purchasing.supplier-audits._queue_nav', $viewData)->render()],
            );
        }

        return view('purchasing.supplier-audits.index', $viewData);
    }

    public function create()
    {
        Gate::authorize('create', SupplierAudit::class);

        $activeStatuses = SupplierAudit::query()->active()->pluck('status', 'supplier_id');
        // Audit terakhir per supplier (satu query) untuk konteks saat memilih.
        $lastAudits = SupplierAudit::query()
            ->whereIn('id', SupplierAudit::query()->selectRaw('max(id)')->groupBy('supplier_id'))
            ->get(['supplier_id', 'period_label', 'status'])
            ->keyBy('supplier_id');
        $suppliers = User::localEligible()
            ->with('supplier')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'value' => $user->hash,
                'label' => $user->supplier?->company_name ?: $user->name,
                'email' => $user->email,
                'active_status' => $activeStatuses->get($user->id),
                'last_audit' => ($last = $lastAudits->get($user->id))
                    ? $last->period_label.' · '.StatusHelper::supplierAuditLabel($last->status)
                    : null,
            ]);

        return view('purchasing.supplier-audits.create', [
            'template' => SupplierAuditTemplate::activeFor(),
            'suppliers' => $suppliers,
        ]);
    }

    public function store(StoreSupplierAuditAssignmentRequest $request, SupplierAuditAssignmentService $service): JsonResponse|RedirectResponse
    {
        $validated = $request->validated();
        $result = $service->assign($request->user(), $request->supplierIds(), $validated['period_label'], $validated['due_date'] ?? null);

        $message = __('supplier_audit.flash.assigned', ['count' => $result['created']->count()]);
        if ($result['rejected'] !== []) {
            session()->flash('warning', __('supplier_audit.flash.rejected', [
                'list' => collect($result['rejected'])
                    ->map(fn (array $row) => $row['name'].' ('.__('supplier_audit.reject_reasons.'.$row['reason']).')')
                    ->implode(', '),
            ]));
        }

        return $this->respond($request, route('purchasing.supplier-audits.index'), $message);
    }

    public function show(SupplierAudit $supplierAudit)
    {
        Gate::authorize('view', $supplierAudit);

        $supplierAudit->load([
            'supplier.supplier',
            'assigner',
            'template',
            'answers',
            'statusHistories.actor',
            'resultAttachments.uploader',
        ]);

        return view('purchasing.supplier-audits.show', [
            'audit' => $supplierAudit,
            'sections' => $supplierAudit->answerSections(),
            'progress' => $supplierAudit->progress(),
            'answerCounts' => $this->answerCounts($supplierAudit),
        ]);
    }

    public function changeDeadline(ChangeSupplierAuditDeadlineRequest $request, SupplierAudit $supplierAudit, SupplierAuditReviewService $service): JsonResponse|RedirectResponse
    {
        $validated = $request->validated();
        $audit = $service->changeDeadline($request->user(), $supplierAudit, $validated['due_date'] ?? null, $validated['reason'] ?? null);

        return $this->respond($request, route('purchasing.supplier-audits.show', $audit),
            __($audit->due_date ? 'supplier_audit.deadline.flash_changed' : 'supplier_audit.deadline.flash_removed'));
    }

    public function requestRevision(RequestSupplierAuditRevisionRequest $request, SupplierAudit $supplierAudit, SupplierAuditReviewService $service): JsonResponse|RedirectResponse
    {
        $validated = $request->validated();
        $audit = $service->requestRevision(
            $request->user(),
            $supplierAudit,
            $validated['note'],
            $request->boolean('change_due_date'),
            $validated['due_date'] ?? null,
        );

        return $this->respond($request, route('purchasing.supplier-audits.show', $audit), __('supplier_audit.flash.revision_requested'));
    }

    public function cancel(CancelSupplierAuditRequest $request, SupplierAudit $supplierAudit, SupplierAuditReviewService $service): JsonResponse|RedirectResponse
    {
        $audit = $service->cancel($request->user(), $supplierAudit, $request->validated()['reason']);

        return $this->respond($request, route('purchasing.supplier-audits.show', $audit), __('supplier_audit.flash.cancelled'));
    }

    public function uploadResult(UploadSupplierAuditResultRequest $request, SupplierAudit $supplierAudit, SupplierAuditReviewService $service): JsonResponse|RedirectResponse
    {
        $replacing = $supplierAudit->status === SupplierAudit::STATUS_RESULT_PUBLISHED;
        $audit = $service->publishResult($request->user(), $supplierAudit, $request->file('result_file'), $request->validated()['reason'] ?? null);

        return $this->respond($request, route('purchasing.supplier-audits.show', $audit),
            __($replacing ? 'supplier_audit.flash.result_replaced' : 'supplier_audit.flash.result_published'));
    }

    /** Form async (data-async-submit) menerima {redirect}; flash tetap lewat session. */
    private function respond(Request $request, string $url, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            session()->flash('success', $message);

            return response()->json(['redirect' => $url]);
        }

        return redirect()->to($url)->with('success', $message);
    }

    /** Hitungan untuk filter cepat jawaban. */
    private function answerCounts(SupplierAudit $audit): array
    {
        $answers = $audit->answers;

        return [
            'all' => $answers->count(),
            'yes' => $answers->where('answer', 'YES')->count(),
            'no' => $answers->where('answer', 'NO')->count(),
            'low' => $answers->where('answer', 'YES')->filter(fn ($row) => $row->score !== null && $row->score <= 2)->count(),
            'empty' => $answers->filter(fn ($row) => ! $row->isComplete())->count(),
        ];
    }

    private function resolveSupplierFilter(?string $value): ?User
    {
        if ($value === null || $value === '') {
            return null;
        }

        abort_unless(! ctype_digit($value), 404);

        $supplier = (new User)->resolveRouteBinding($value);
        abort_unless($supplier instanceof User && $supplier->role === 'supplier', 404);

        return $supplier;
    }
}
