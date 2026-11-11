<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\Payment\StoreManualPaymentVoucherRequest;
use App\Http\Requests\Payment\VoidManualPaymentVoucherRequest;
use App\Models\PaymentVoucher;
use App\Models\PaymentVoucherDocument;
use App\Services\FileSecurity\FileAccessGuard;
use App\Services\Payment\ManualPaymentVoucherService;
use App\Support\BusinessTime;
use App\Support\Security\InlinePreviewResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

trait HandlesManualPaymentVouchers
{
    abstract protected function routePrefix(): string;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', PaymentVoucher::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', Rule::in(PaymentVoucher::DEPARTMENTS)],
            'status' => ['nullable', Rule::in(PaymentVoucher::STATUSES)],
            'payment_method' => ['nullable', Rule::in(PaymentVoucher::METHODS)],
            'payee_type' => ['nullable', Rule::in(PaymentVoucher::PAYEE_TYPES)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $query = PaymentVoucher::with(['creator', 'documents']);

        if (filled($filters['q'] ?? null)) {
            $search = '%'.addcslashes(trim((string) $filters['q']), '%_').'%';
            $query->where(function ($sub) use ($search): void {
                $sub->where('voucher_number', 'like', $search)
                    ->orWhere('payee_name_snapshot', 'like', $search)
                    ->orWhere('reference_number', 'like', $search)
                    ->orWhere('description', 'like', $search);
            });
        }

        foreach (['department' => 'source_department', 'status' => 'status', 'payment_method' => 'payment_method', 'payee_type' => 'payee_type'] as $inputKey => $column) {
            if (filled($filters[$inputKey] ?? null)) {
                $query->where($column, $filters[$inputKey]);
            }
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->where('voucher_date', '>=', $filters['date_from']);
        }
        if (filled($filters['date_to'] ?? null)) {
            $query->where('voucher_date', '<=', $filters['date_to']);
        }

        $vouchers = $query->latest('id')->paginate(20)->withQueryString();

        return view('payment-vouchers.index', [
            'vouchers' => $vouchers,
            'filters' => $filters,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function create(Request $request, ManualPaymentVoucherService $service): View
    {
        Gate::authorize('create', PaymentVoucher::class);

        $duplicate = null;
        if ($request->filled('duplicate_from')) {
            $hash = (string) $request->query('duplicate_from');
            abort_if(ctype_digit($hash), 404);
            $resolved = (new PaymentVoucher)->resolveRouteBinding($hash);
            abort_unless($resolved instanceof PaymentVoucher, 404);
            Gate::authorize('view', $resolved);
            $duplicate = $resolved;
        }

        return view('payment-vouchers.create', array_merge(
            $service->formOptions($duplicate),
            [
                'routePrefix' => $this->routePrefix(),
                'defaultDate' => BusinessTime::today()->toDateString(),
            ]
        ));
    }

    public function store(StoreManualPaymentVoucherRequest $request, ManualPaymentVoucherService $service): JsonResponse|RedirectResponse
    {
        $voucher = $service->issue(
            $request->user(),
            $request->validated(),
            $request->file('supporting')
        );

        $message = __('payment_voucher.feedback.created', ['number' => $voucher->voucher_number]);
        $redirect = route($this->routePrefix().'.show', $voucher);

        if ($request->expectsJson()) {
            $request->session()->flash('success', $message);

            return response()->json(['redirect' => $redirect]);
        }

        return redirect($redirect)->with('success', $message);
    }

    public function show(PaymentVoucher $paymentVoucher): View
    {
        Gate::authorize('view', $paymentVoucher);

        $paymentVoucher->load(['supplierUser.supplier', 'employee', 'creator', 'voider', 'documents']);

        return view('payment-vouchers.show', [
            'voucher' => $paymentVoucher,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function print(Request $request, PaymentVoucher $paymentVoucher): Response
    {
        Gate::authorize('print', $paymentVoucher);

        $paymentVoucher->load(['creator', 'voider']);
        $filename = 'Payment_Voucher_'.preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $paymentVoucher->voucher_number).'.pdf';

        $pdf = Pdf::loadView('pdf.manual-payment-voucher', ['voucher' => $paymentVoucher])
            ->setPaper('a4', 'portrait');

        if ($request->boolean('download')) {
            return $pdf->download($filename)
                ->header('X-Content-Type-Options', 'nosniff')
                ->header('Cache-Control', 'no-store, private');
        }

        return InlinePreviewResponse::applySameOriginFramingHeaders($pdf->stream($filename));
    }

    public function void(VoidManualPaymentVoucherRequest $request, PaymentVoucher $paymentVoucher, ManualPaymentVoucherService $service): RedirectResponse
    {
        $updated = $service->void($paymentVoucher, $request->user(), (string) $request->validated('void_reason'));

        return redirect()
            ->route($this->routePrefix().'.show', $updated)
            ->with('success', __('payment_voucher.feedback.voided', ['number' => $updated->voucher_number]));
    }

    public function showDocument(Request $request, PaymentVoucher $paymentVoucher, PaymentVoucherDocument $document): Response
    {
        abort_unless((int) $document->payment_voucher_id === (int) $paymentVoucher->id, 404);
        Gate::authorize('view', $document);
        app(FileAccessGuard::class)->assertReadable($document);
        abort_unless(Storage::disk('private')->exists($document->file_path), 404);

        return InlinePreviewResponse::diskResponse(
            Storage::disk('private'),
            $document->file_path,
            $document->document_type.'_'.($document->original_filename ?: basename($document->file_path)),
            $document->mime_type,
            $request->boolean('inline')
        );
    }
}
