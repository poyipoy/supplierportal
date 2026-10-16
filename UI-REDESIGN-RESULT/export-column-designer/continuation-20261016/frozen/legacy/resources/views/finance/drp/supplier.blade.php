@extends('layouts.app')
@section('title', __('finance.closure.supplier_title', ['audience' => 'Finance AP']))
@section('page-title', __('finance.drp.supplier_heading'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('finance.drp.supplier_title')"
        :description="__('finance.drp_surface.supplier_help')"
        :eyebrow="__('finance.labels.finance_ap')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.drp.ga')" variant="outline" size="sm">
                <x-ui.icon name="credit-card" size="sm" />
                <span>{{ __('finance.drp.open_ga') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Form Buat Batch DRP Baru dari Tagihan Ready to Pay --}}
    <x-ui.card
        :title="__('finance.drp.create_supplier')"
        :description="__('common.final_review.ready_invoice_help')"
    >
        <form method="GET" action="{{ route('finance.drp.supplier') }}" class="tw-mb-4 tw-flex tw-flex-wrap tw-items-end tw-gap-3">
            <input type="hidden" name="sort" value="{{ $candidateSort }}">
            <input type="hidden" name="direction" value="{{ $candidateDirection }}">
            <div><label for="candidate_supplier" class="form-label tw-text-ui-xs">{{ __('finance.drp_surface.supplier_filter') }}</label><select id="candidate_supplier" name="supplier_id" class="form-select form-select-sm"><option value="">{{ __('finance.drp_surface.local_suppliers') }}</option>@foreach(($suppliers ?? []) as $supplier)<option value="{{ $supplier->hash }}" @selected($supplierFilter?->id === $supplier->id)>{{ $supplier->supplier?->company_name ?: $supplier->name }}</option>@endforeach</select></div>
            <x-ui.date-range-picker id="candidate-due-range" start-name="due_date_from" end-name="due_date_to" :start-label="__('finance.candidates.due_from')" :end-label="__('finance.candidates.due_to')" :start-value="request('due_date_from')" :end-value="request('due_date_to')" :compact="true" />
            <x-ui.date-range-picker id="candidate-verification-range" start-name="verification_date_from" end-name="verification_date_to" :start-label="__('finance.candidates.verification_from')" :end-label="__('finance.candidates.verification_to')" :start-value="request('verification_date_from')" :end-value="request('verification_date_to')" :compact="true" />
            <x-ui.button type="submit" size="sm" variant="outline">{{ __('common.labels_review.filter') }}</x-ui.button>
            <x-ui.button :href="route('finance.drp.supplier')" size="sm" variant="ghost">{{ __('finance.candidates.reset') }}</x-ui.button>
        </form>
        <form method="POST" action="{{ route('finance.drp.supplier.create') }}">
            @csrf
            <div class="tw-space-y-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="width: 40px;">{{ __('common.actions.choose') }}</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('finance.drp.payee_account') }}</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('finance.drp_surface.invoice_po') }}</th>
                                @foreach(['due_date' => __('local_invoice.labels.due_date'), 'verification_date' => __('finance.candidates.verification_date')] as $sortKey => $sortLabel)
                                    <th scope="col" class="tw-text-ui-xs tw-font-semibold" aria-sort="{{ $candidateSort === $sortKey ? ($candidateDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                        <a class="tw-inline-flex tw-items-center tw-gap-1 tw-min-h-6" href="{{ route('finance.drp.supplier', array_merge(request()->except('candidate_page'), ['sort' => $sortKey, 'direction' => $candidateSort === $sortKey && $candidateDirection === 'asc' ? 'desc' : 'asc'])) }}">
                                            {{ $sortLabel }}
                                            @if($candidateSort === $sortKey)<x-ui.icon :name="$candidateDirection === 'asc' ? 'arrow-up' : 'arrow-down'" size="sm" />@endif
                                        </a>
                                    </th>
                                @endforeach
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('local_invoice.labels.dpp_amount') }}</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('finance.drp_ui.ppn_amount') }}</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('finance.drp.total_payment') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($eligibleInvoices as $inv)
                                @php
                                    $bank = $inv->supplier->activeSupplierBankAccount;
                                    $totalNominal = $inv->currentVerification?->calculateNetPayable((float) $inv->invoice_amount) ?? ((float) $inv->invoice_amount + (float) $inv->tax_amount);
                                @endphp
                                <tr>
                                    <td>
                                        <input type="checkbox" name="invoice_ids[]" value="{{ $inv->id }}" class="form-check-input" aria-label="{{ __('finance.candidates.select_invoice', ['number' => $inv->invoice_number]) }}">
                                    </td>
                                    <td>
                                        <strong class="tw-text-on-surface">{{ $inv->supplier->supplier?->company_name ?: $inv->supplier->name }}</strong>
                                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">
                                            {{ $bank ? __('finance.drp_ui.bank_details', ['bank' => $bank->bank_name, 'account' => $bank->account_number, 'holder' => $bank->account_holder_name]) : __('finance.drp_ui.no_active_bank_account') }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="tw-font-medium">{{ $inv->invoice_number }}</span>
                                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ __('finance.drp_ui.po_reference', ['number' => $inv->po_number]) }}</span>
                                    </td>
                                    <td>
                                        <span class="tw-text-ui-xs {{ $inv->isOverdue() ? 'tw-text-error tw-font-bold' : '' }}">
                                            {{ $regionalFormatter->date($inv->due_date, 'human') ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="tw-text-ui-xs">@bizdt($inv->ready_to_pay_at)</td>
                                    <td class="text-end tw-font-mono">Rp {{ number_format($inv->invoice_amount, 0, ',', '.') }}</td>
                                    <td class="text-end tw-font-mono">Rp {{ number_format($inv->tax_amount, 0, ',', '.') }}</td>
                                    <td class="text-end tw-font-mono tw-font-bold tw-text-primary">
                                        Rp {{ number_format($totalNominal, 0, ',', '.') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                        {{ __('finance.drp.empty_supplier_candidates') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $eligibleInvoices->links() }}

                @if($eligibleInvoices->isNotEmpty())
                    <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3 tw-border-t tw-border-outline-variant tw-pt-3">
                        <div class="tw-flex-1">
                            <input type="text" name="notes" class="form-control form-control-sm" aria-label="{{ __('common.final_review.batch_notes') }}" placeholder="{{ __('common.final_review.batch_notes') }}">
                        </div>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="plus" size="sm" />
                            <span>{{ __('finance.drp.create_draft') }}</span>
                        </x-ui.button>
                    </div>
                @endif
            </div>
        </form>
    </x-ui.card>

    {{-- Daftar Batch DRP Supplier --}}
    <x-ui.data-table
        :title="__('finance.drp.supplier_list')"
        :description="__('finance.drp_surface.supplier_history')"
    >
        <x-slot:toolbar>
            <x-ui.button
                type="button"
                variant="primary"
                size="sm"
                id="btnExportTransfer"
                disabled
                :title="__('finance.drp_surface.choose_batch')"
            >
                <x-ui.icon name="download" size="sm" />
                <span>{{ __('exports.actions.transfer') }}</span>
                <span id="exportTransferCount" class="tw-hidden tw-ml-1.5 tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-bg-white/20 tw-text-white tw-text-[10px] tw-font-bold tw-px-1.5 tw-py-0.5"></span>
            </x-ui.button>
        </x-slot:toolbar>

        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100" id="drpBatchTable">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width: 40px;">
                            <input type="checkbox" class="form-check-input" id="selectAllBatches" title="{{ __('finance.drp_surface.all_batches') }}">
                        </th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('finance.drp_surface.batch_number') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.labels.date') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('finance.drp.group_payee') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('finance.drp.total_amount_rp') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('finance.drp.total_fee_rp') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.labels.status') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('local_invoice.labels.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($batches as $batch)
                        <tr>
                            <td>
                                @if($batch->status !== \App\Models\PaymentBatch::STATUS_CANCELLED)
                                    <input type="checkbox" class="form-check-input batch-checkbox" value="{{ $batch->hash }}" data-batch-number="{{ $batch->batch_number }}">
                                @endif
                            </td>
                            <td>
                                <strong class="tw-font-mono tw-text-on-surface">{{ $batch->batch_number }}</strong>
                            </td>
                            <td>{{ $regionalFormatter->date($batch->created_at, 'human') }}</td>
                            <td>{{ trans_choice('finance.copy_review.accounts_count', $batch->groups_count, ['count' => $batch->groups_count]) }}</td>
                            <td class="text-end tw-font-mono tw-font-semibold">
                                Rp {{ number_format($batch->total_subtotal, 0, ',', '.') }}
                            </td>
                            <td class="text-end tw-font-mono tw-text-on-surface-variant">
                                Rp {{ number_format($batch->total_bank_fee, 0, ',', '.') }}
                            </td>
                            <td>
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::paymentBatchTone($batch->status)">
                                    {{ \App\Support\StatusHelper::localFinanceLabel($batch->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('finance.drp.show', $batch)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>{{ __('finance.drp.open_batch') }}</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                {{ __('finance.drp.empty_supplier') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($batches->hasPages())
            <x-slot:pagination>
                {{ $batches->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAll = document.getElementById('selectAllBatches');
    const checkboxes = () => document.querySelectorAll('.batch-checkbox');
    const btnExport = document.getElementById('btnExportTransfer');
    const countBadge = document.getElementById('exportTransferCount');

    function updateExportButton() {
        const checked = document.querySelectorAll('.batch-checkbox:checked');
        const count = checked.length;

        btnExport.disabled = count === 0;

        if (count > 0) {
            countBadge.textContent = count;
            countBadge.classList.remove('tw-hidden');
        } else {
            countBadge.classList.add('tw-hidden');
        }

        // Update select-all checkbox state
        const allBoxes = checkboxes();
        if (allBoxes.length > 0) {
            selectAll.checked = checked.length === allBoxes.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < allBoxes.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes().forEach(cb => cb.checked = selectAll.checked);
            updateExportButton();
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('batch-checkbox')) {
            updateExportButton();
        }
    });

    if (btnExport) {
        btnExport.addEventListener('click', function () {
            const selected = document.querySelectorAll('.batch-checkbox:checked');
            if (selected.length === 0) return;

            const batchIds = Array.from(selected).map(cb => cb.value);

            // Confirm action
            const confirmMsg = @js(__('finance.async_copy.export_confirm')).replace(':count', String(batchIds.length));
            if (window.AdasiAlert && typeof window.AdasiAlert.confirm === 'function') {
                AdasiAlert.confirm({
                    title: @js(__('finance.async_copy.export_title')),
                    message: confirmMsg,
                    confirmText: @js(__('finance.async_copy.export_yes')),
                    cancelText: @js(__('finance.async_copy.cancel')),
                }).then(confirmed => {
                    if (confirmed) {
                        dispatchExport(batchIds);
                    }
                });
            } else {
                if (confirm(confirmMsg)) {
                    dispatchExport(batchIds);
                }
            }
        });
    }

    function dispatchExport(batchIds) {
        btnExport.disabled = true;

        fetch("{{ route('finance.drp.export-transfer') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '{{ csrf_token() }}',
            },
            body: JSON.stringify({ batch_ids: batchIds }),
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(data => { throw data; });
            }
            return response.json();
        })
        .then(data => {
            if (window.AdasiToast && typeof window.AdasiToast.success === 'function') {
                AdasiToast.success(data.message || @js(__('finance.async_copy.dispatched_transfer')));
            } else {
                alert(data.message || @js(__('finance.async_copy.dispatched')));
            }

            // Persist to pending export storage
            try {
                const existing = JSON.parse(localStorage.getItem('adasi:pending-export-jobs:v1') || '[]');
                existing.push({
                    exportJobId: String(data.export_job_id),
                    statusUrl: data.status_url,
                    startedAt: Date.now(),
                    exportsUrl: data.exports_url || null,
                    cancelUrl: data.cancel_url || null,
                    status: 'queued',
                    stage: 'queued',
                    progress: 0,
                    processedRows: 0,
                    totalRows: 0,
                });
                localStorage.setItem('adasi:pending-export-jobs:v1', JSON.stringify(existing.slice(-25)));
            } catch (e) {}

            // Poll for completion and trigger download
            if (data.status_url) {
                pollExportStatus(data.status_url);
            }

            // Uncheck all after dispatch
            checkboxes().forEach(cb => cb.checked = false);
            if (selectAll) selectAll.checked = false;
            updateExportButton();
        })
        .catch(err => {
            const msg = err.message || err.error || @js(__('finance.async_copy.export_error'));
            if (window.AdasiToast && typeof window.AdasiToast.error === 'function') {
                AdasiToast.error(msg);
            } else {
                alert(msg);
            }
            updateExportButton();
        });
    }

    function pollExportStatus(statusUrl) {
        let attempts = 0;
        const maxAttempts = 120; // 3 minutes max

        const check = () => {
            attempts++;
            if (attempts > maxAttempts) return;

            fetch(statusUrl, {
                headers: { 'Accept': 'application/json' }
            })
            .then(res => res.json())
            .then(job => {
                if (job.status === 'completed' && job.download_url) {
                    if (typeof AdasiToast !== 'undefined') {
                        AdasiToast.success(@js(__('finance.async_copy.export_complete')));
                    }
                    const a = document.createElement('a');
                    a.href = job.download_url;
                    a.download = job.file_name || 'DRP_TRANSFER.xlsx';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                } else if (job.status === 'failed') {
                    const failMsg = job.message || @js(__('finance.async_copy.export_failed'));
                    if (typeof AdasiToast !== 'undefined') {
                        AdasiToast.error(failMsg);
                    }
                } else if (job.status === 'queued' || job.status === 'processing') {
                    setTimeout(check, 1500);
                }
            })
            .catch(() => {
                // Silently stop polling on network error
            });
        };

        setTimeout(check, 1500);
    }
});
</script>
@endpush
