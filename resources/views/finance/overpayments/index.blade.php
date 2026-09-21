@extends('layouts.app')
@section('title', 'Kelebihan Bayar Supplier - Finance AP')
@section('page-title', 'Daftar Kelebihan Bayar Supplier')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Daftar Kelebihan Bayar Supplier"
        description="Kelebihan bayar dicatat sebagai piutang terpisah dan memerlukan pengembalian dana penuh disertai bukti transfer."
        eyebrow="Finance & Accounts Payable"
    />

    <x-ui.card title="Filter Pencarian">
        <form method="GET" class="tw-grid tw-gap-3 md:tw-grid-cols-2 lg:tw-grid-cols-5 lg:tw-items-end">
            <div>
                <label for="overpayment-q" class="form-label tw-text-ui-xs tw-font-semibold">No. Invoice / Referensi Pembayaran</label>
                <input id="overpayment-q" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Cari invoice atau transfer" maxlength="100">
            </div>
            <div>
                <label for="overpayment-supplier" class="form-label tw-text-ui-xs tw-font-semibold">Supplier</label>
                <select id="overpayment-supplier" name="supplier_id" class="form-select form-select-sm">
                    <option value="">Semua Supplier</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->hash }}" @selected((string) request('supplier_id') === (string) $supplier->hash)>{{ $supplier->supplier?->company_name ?: $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="overpayment-status" class="form-label tw-text-ui-xs tw-font-semibold">Status</label>
                <select id="overpayment-status" name="status" class="form-select form-select-sm">
                    <option value="">Semua Status</option>
                    <option value="OPEN" @selected(request('status') === 'OPEN')>OPEN</option>
                    <option value="SETTLED" @selected(request('status') === 'SETTLED')>SETTLED</option>
                </select>
            </div>
            <div class="lg:tw-col-span-2">
                <x-ui.date-range-picker
                    id="overpayment-date-range"
                    start-name="date_from"
                    end-name="date_to"
                    start-label="Dibuat dari"
                    end-label="Dibuat sampai"
                    :start-value="request('date_from')"
                    :end-value="request('date_to')"
                    :compact="true"
                />
            </div>
            <div class="md:tw-col-span-2 lg:tw-col-span-5 tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                <x-ui.button type="submit" size="sm">Terapkan Filter</x-ui.button>
                <x-ui.button :href="route('finance.overpayments.index')" variant="ghost" size="sm">Reset</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.data-table title="Daftar Piutang & Pengembalian">
        <div class="table-responsive">
            <table class="table align-middle tw-text-ui-sm">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Supplier</th>
                        <th>Referensi Pembayaran</th>
                        <th class="text-end">Target / Realisasi</th>
                        <th class="text-end">Kelebihan Bayar</th>
                        <th>Status / Tanggal</th>
                        <th class="text-end" style="min-width: 150px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($overpayments as $refund)
                        <tr>
                            <td class="tw-font-medium">{{ $refund->invoice->invoice_number }}</td>
                            <td>{{ $refund->supplier->supplier?->company_name ?: $refund->supplier->name }}</td>
                            <td>
                                @forelse($refund->payment?->transfers ?? [] as $transfer)
                                    <span class="tw-block tw-font-mono tw-text-ui-xs">{{ $transfer->transfer_reference }}</span>
                                @empty
                                    <span class="tw-text-on-surface-variant">—</span>
                                @endforelse
                            </td>
                            <td class="text-end tw-font-mono">Rp {{ number_format($refund->payment?->expected_amount ?? 0, 2, ',', '.') }} / Rp {{ number_format($refund->payment?->actual_paid_total ?? 0, 2, ',', '.') }}</td>
                            <td class="text-end tw-font-mono tw-font-semibold">Rp {{ number_format($refund->overpayment_amount, 2, ',', '.') }}</td>
                            <td>
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($refund->status)">{{ $refund->status }}</x-ui.status-chip>
                                <span class="tw-mt-1 tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $refund->status === 'SETTLED' ? $refund->refund_date?->format('d M Y') : $refund->created_at?->format('d M Y') }}</span>
                            </td>
                            <td class="text-end">
                                @if($refund->status === 'OPEN')
                                    <x-ui.button
                                        type="button"
                                        size="sm"
                                        variant="primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#refundModal-{{ $refund->id }}"
                                        class="tw-whitespace-nowrap"
                                    >
                                        <x-ui.icon name="hand-coins" size="sm" />
                                        <span>Proses Refund</span>
                                    </x-ui.button>
                                @else
                                    <div class="tw-flex tw-flex-col tw-items-end tw-gap-1">
                                        <span class="tw-font-mono tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                            {{ $refund->refund_reference }}
                                        </span>
                                        @if($refund->attachments->first())
                                            <x-ui.button
                                                :href="route('attachments.show', $refund->attachments->first())"
                                                variant="outline"
                                                size="sm"
                                                target="_blank"
                                                class="tw-whitespace-nowrap"
                                            >
                                                <x-ui.icon name="file-text" size="sm" />
                                                <span>Bukti Transfer</span>
                                            </x-ui.button>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="tw-py-8 tw-text-center tw-text-on-surface-variant">Tidak ada data kelebihan bayar supplier.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:pagination>{{ $overpayments->links() }}</x-slot:pagination>
    </x-ui.data-table>

    {{-- Modal Proses Pengembalian Dana (Ditempatkan di luar tabel agar DOM & Accessibility valid) --}}
    @foreach($overpayments as $refund)
        @if($refund->status === 'OPEN')
            <div
                class="modal fade"
                id="refundModal-{{ $refund->id }}"
                tabindex="-1"
                aria-labelledby="refundModalLabel-{{ $refund->id }}"
                aria-hidden="true"
            >
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <form
                        method="POST"
                        action="{{ route('finance.overpayments.refund', $refund) }}"
                        enctype="multipart/form-data"
                    >
                        @csrf
                        <div class="modal-content tw-rounded-ui-xl tw-border-0 tw-shadow-ui-modal">
                            {{-- Modal Header --}}
                            <div class="modal-header tw-border-b tw-border-outline-variant/60 tw-px-6 tw-py-4">
                                <div class="tw-flex tw-items-center tw-gap-3">
                                    <div class="tw-w-10 tw-h-10 tw-rounded-ui-md tw-bg-primary/10 tw-text-primary tw-flex tw-items-center tw-justify-center tw-shrink-0">
                                        <x-ui.icon name="hand-coins" size="md" />
                                    </div>
                                    <div>
                                        <h5 class="modal-title tw-text-ui-md tw-font-bold tw-text-on-surface" id="refundModalLabel-{{ $refund->id }}">
                                            Proses Pengembalian Kelebihan Bayar
                                        </h5>
                                        <p class="tw-text-ui-xs tw-text-on-surface-variant tw-m-0">
                                            Catat pelunasan pengembalian dana (refund) dari supplier ke rekening ADASI
                                        </p>
                                    </div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                            </div>

                            {{-- Modal Body --}}
                            <div class="modal-body tw-px-6 tw-py-5 tw-space-y-4">
                                {{-- Ringkasan Konteks Transaksi --}}
                                <div class="tw-rounded-ui-md tw-bg-surface-container tw-border tw-border-outline-variant/50 tw-p-4">
                                    <h6 class="tw-text-ui-xs tw-font-bold tw-text-on-surface-variant tw-uppercase tw-tracking-wider tw-mb-3">
                                        Ringkasan Piutang Refund
                                    </h6>
                                    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-3 tw-text-ui-xs">
                                        <div>
                                            <span class="tw-text-on-surface-variant tw-block">Nama Supplier:</span>
                                            <strong class="tw-text-ui-sm tw-text-on-surface">
                                                {{ $refund->supplier->supplier?->company_name ?: $refund->supplier->name }}
                                            </strong>
                                        </div>
                                        <div>
                                            <span class="tw-text-on-surface-variant tw-block">Nomor Invoice:</span>
                                            <strong class="tw-text-ui-sm tw-text-on-surface tw-font-mono">
                                                {{ $refund->invoice->invoice_number }}
                                            </strong>
                                        </div>
                                        <div>
                                            <span class="tw-text-on-surface-variant tw-block">Referensi Pembayaran Asli:</span>
                                            <div class="tw-font-mono tw-text-ui-xs tw-text-on-surface">
                                                @forelse($refund->payment?->transfers ?? [] as $transfer)
                                                    <span class="tw-inline-block">{{ $transfer->transfer_reference }}</span>{{ !$loop->last ? ', ' : '' }}
                                                @empty
                                                    <span class="tw-text-on-surface-variant">—</span>
                                                @endforelse
                                            </div>
                                        </div>
                                        <div>
                                            <span class="tw-text-on-surface-variant tw-block">Nominal Wajib Dikembalikan (100%):</span>
                                            <strong class="tw-text-ui-base tw-font-bold tw-font-mono tw-text-primary">
                                                Rp {{ number_format($refund->overpayment_amount, 2, ',', '.') }}
                                            </strong>
                                        </div>
                                    </div>
                                </div>

                                {{-- Hidden Full Refund Amount (Locked in system, non-negotiable) --}}
                                <input type="hidden" name="refund_amount" value="{{ $refund->overpayment_amount }}">

                                {{-- Form Inputs --}}
                                <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
                                    <div>
                                        <label for="refund_reference_{{ $refund->id }}" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                            Nomor Referensi Transfer / Refund <span class="text-danger">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            id="refund_reference_{{ $refund->id }}"
                                            name="refund_reference"
                                            class="form-control form-control-sm"
                                            placeholder="Contoh: TRF-REFUND-20260901"
                                            required
                                            maxlength="100"
                                        >
                                        <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1">
                                            Masukkan nomor bukti transaksi transfer bank dari supplier.
                                        </div>
                                    </div>

                                    <div>
                                        <x-ui.date-picker
                                            :id="'refund_date_'.$refund->id"
                                            name="refund_date"
                                            label="Tanggal Penerimaan Refund"
                                            :value="now()->format('Y-m-d')"
                                            required
                                        />
                                    </div>

                                    <div class="sm:tw-col-span-2">
                                        <label for="proof_{{ $refund->id }}" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                            Bukti Transfer Dana (PDF / Gambar) <span class="text-danger">*</span>
                                        </label>
                                        <input
                                            type="file"
                                            id="proof_{{ $refund->id }}"
                                            name="proof"
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            class="form-control form-control-sm"
                                            required
                                        >
                                        <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1">
                                            Format file yang didukung: PDF, JPG, JPEG, PNG (Maksimal 10 MB).
                                        </div>
                                    </div>

                                    <div class="sm:tw-col-span-2">
                                        <label for="notes_{{ $refund->id }}" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                            Catatan Rekonsiliasi (Opsional)
                                        </label>
                                        <textarea
                                            id="notes_{{ $refund->id }}"
                                            name="notes"
                                            class="form-control form-control-sm"
                                            rows="2"
                                            placeholder="Catatan tambahan mengenai rekonsiliasi kelebihan bayar..."
                                            maxlength="1000"
                                        ></textarea>
                                    </div>
                                </div>
                            </div>

                            {{-- Modal Footer --}}
                            <div class="modal-footer tw-border-t tw-border-outline-variant/60 tw-px-6 tw-py-3 tw-bg-surface-container-low/50">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                                    Batal
                                </button>
                                <x-ui.button type="submit" variant="primary" size="sm">
                                    <x-ui.icon name="check-circle" size="sm" />
                                    <span>Simpan & Selesaikan Refund</span>
                                </x-ui.button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endforeach
</div>
@endsection
