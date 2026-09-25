@extends('layouts.app')
@section('title', 'DRP Supplier - Finance AP')
@section('page-title', 'Daftar Rencana Pembayaran (DRP) Supplier')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="DRP Supplier (Daftar Rencana Pembayaran)"
        description="Kelola batch rencana pembayaran supplier, pengelompokan rekening tujuan, penyesuaian biaya transfer bank, dan penerbitan voucher bayar."
        eyebrow="Finance & Accounts Payable"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Dashboard</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.drp.ga')" variant="outline" size="sm">
                <x-ui.icon name="credit-card" size="sm" />
                <span>Buka DRP GA</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Form Buat Batch DRP Baru dari Tagihan Ready to Pay --}}
    <x-ui.card
        title="Buat Batch DRP Supplier Baru"
        description="Pilih tagihan invoice berstatus Ready to Pay yang belum masuk dalam batch aktif (Draft/Finalized)."
    >
        <form method="GET" action="{{ route('finance.drp.supplier') }}" class="tw-mb-4 tw-flex tw-flex-wrap tw-items-end tw-gap-3">
            <div><label class="form-label tw-text-ui-xs">Supplier filter</label><select name="supplier_id" class="form-select form-select-sm"><option value="">All Local Suppliers</option>@foreach(($suppliers ?? []) as $supplier)<option value="{{ $supplier->hash }}" @selected($supplierFilter?->id === $supplier->id)>{{ $supplier->supplier?->company_name ?: $supplier->name }}</option>@endforeach</select></div>
            <x-ui.button type="submit" size="sm" variant="outline">Filter</x-ui.button>
        </form>
        <form method="POST" action="{{ route('finance.drp.supplier.create') }}">
            @csrf
            <div class="tw-space-y-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="width: 40px;">Pilih</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">Supplier / Rekening</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">Nomor Invoice / PO</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">Jatuh Tempo</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">DPP (Rp)</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">PPN (Rp)</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Total Bayar (Rp)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($eligibleInvoices as $inv)
                                @php
                                    $bank = $inv->supplier->supplier?->activeBankAccount;
                                    $totalNominal = $inv->currentVerification?->calculateNetPayable((float) $inv->invoice_amount) ?? ((float) $inv->invoice_amount + (float) $inv->tax_amount);
                                @endphp
                                <tr>
                                    <td>
                                        <input type="checkbox" name="invoice_ids[]" value="{{ $inv->id }}" class="form-check-input">
                                    </td>
                                    <td>
                                        <strong class="tw-text-on-surface">{{ $inv->supplier->supplier?->company_name ?: $inv->supplier->name }}</strong>
                                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">
                                            {{ $bank ? "{$bank->bank_name} - {$bank->account_number} a.n {$bank->account_holder_name}" : 'Belum ada rekening aktif' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="tw-font-medium">{{ $inv->invoice_number }}</span>
                                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">PO: {{ $inv->po_number }}</span>
                                    </td>
                                    <td>
                                        <span class="tw-text-ui-xs {{ $inv->isOverdue() ? 'tw-text-error tw-font-bold' : '' }}">
                                            {{ $inv->due_date?->format('d M Y') ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="text-end tw-font-mono">Rp {{ number_format($inv->invoice_amount, 0, ',', '.') }}</td>
                                    <td class="text-end tw-font-mono">Rp {{ number_format($inv->tax_amount, 0, ',', '.') }}</td>
                                    <td class="text-end tw-font-mono tw-font-bold tw-text-primary">
                                        Rp {{ number_format($totalNominal, 0, ',', '.') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                        Tidak ada tagihan supplier Ready to Pay yang tersedia saat ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($eligibleInvoices->isNotEmpty())
                    <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3 tw-border-t tw-border-outline-variant tw-pt-3">
                        <div class="tw-flex-1">
                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="Catatan batch DRP (opsional)...">
                        </div>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="plus" size="sm" />
                            <span>Buat Batch DRP Draft</span>
                        </x-ui.button>
                    </div>
                @endif
            </div>
        </form>
    </x-ui.card>

    {{-- Daftar Batch DRP Supplier --}}
    <x-ui.data-table
        title="Daftar Batch DRP Supplier"
        description="Riwayat dan status seluruh batch DRP Supplier yang terdaftar. Pilih satu atau lebih batch untuk export transfer."
    >
        <x-slot:toolbar>
            <x-ui.button
                type="button"
                variant="primary"
                size="sm"
                id="btnExportTransfer"
                disabled
                title="Pilih minimal satu batch DRP untuk melakukan export transfer"
            >
                <x-ui.icon name="download" size="sm" />
                <span>Export Transfer</span>
                <span id="exportTransferCount" class="tw-hidden tw-ml-1.5 tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-bg-white/20 tw-text-white tw-text-[10px] tw-font-bold tw-px-1.5 tw-py-0.5"></span>
            </x-ui.button>
        </x-slot:toolbar>

        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100" id="drpBatchTable">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width: 40px;">
                            <input type="checkbox" class="form-check-input" id="selectAllBatches" title="Pilih semua batch">
                        </th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Batch Number</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Tanggal</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Grup Penerima</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Total Nominal (Rp)</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Total Fee Bank (Rp)</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Status</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Aksi</th>
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
                            <td>{{ $batch->created_at?->format('d M Y') }}</td>
                            <td>{{ $batch->groups_count }} rekening tujuan</td>
                            <td class="text-end tw-font-mono tw-font-semibold">
                                Rp {{ number_format($batch->total_subtotal, 0, ',', '.') }}
                            </td>
                            <td class="text-end tw-font-mono tw-text-on-surface-variant">
                                Rp {{ number_format($batch->total_bank_fee, 0, ',', '.') }}
                            </td>
                            <td>
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($batch->status)">
                                    {{ $batch->status }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('finance.drp.show', $batch)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>Buka Batch</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                Belum ada batch DRP Supplier.
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
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Export Transfer DRP',
                    html: `Anda akan mengexport <strong>${batchIds.length}</strong> batch DRP menjadi satu file TARIKAN TRANSFER.<br><br>Lanjutkan?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Export',
                    cancelButtonText: 'Batal',
                }).then(result => {
                    if (result.isConfirmed) {
                        dispatchExport(batchIds);
                    }
                });
            } else {
                if (confirm('Export ' + batchIds.length + ' batch DRP menjadi satu file transfer?')) {
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
            if (typeof AdasiToast !== 'undefined') {
                AdasiToast.success(data.message || 'Export transfer berhasil didispatch.');
            } else if (typeof Swal !== 'undefined') {
                Swal.fire('Berhasil', data.message || 'Export transfer berhasil didispatch.', 'success');
            } else {
                alert(data.message || 'Export berhasil didispatch.');
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
            const msg = err.message || err.error || 'Terjadi kesalahan saat export transfer.';
            if (typeof AdasiToast !== 'undefined') {
                AdasiToast.error(msg);
            } else if (typeof Swal !== 'undefined') {
                Swal.fire('Gagal', msg, 'error');
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
                        AdasiToast.success('Export transfer selesai. Mengunduh file...');
                    }
                    const a = document.createElement('a');
                    a.href = job.download_url;
                    a.download = job.file_name || 'DRP_TRANSFER.xlsx';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                } else if (job.status === 'failed') {
                    const failMsg = job.message || 'Export transfer gagal diproses.';
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

