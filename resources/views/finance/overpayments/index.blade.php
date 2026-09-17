@extends('layouts.app')
@section('title', 'Supplier Overpayment')
@section('page-title', 'Supplier Overpayment Register')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Supplier Overpayment Register"
        description="Overpayments remain separate receivables and require one exact full refund with proof."
        eyebrow="Finance AP"
    />

    <x-ui.card title="Filters">
        <form method="GET" class="tw-grid tw-gap-3 md:tw-grid-cols-2 lg:tw-grid-cols-5 lg:tw-items-end">
            <div>
                <label for="overpayment-q" class="form-label tw-text-ui-xs tw-font-semibold">Invoice / payment reference</label>
                <input id="overpayment-q" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Search invoice or transfer" maxlength="100">
            </div>
            <div>
                <label for="overpayment-supplier" class="form-label tw-text-ui-xs tw-font-semibold">Supplier</label>
                <select id="overpayment-supplier" name="supplier_id" class="form-select form-select-sm">
                    <option value="">All suppliers</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->hash }}" @selected((string) request('supplier_id') === (string) $supplier->hash)>{{ $supplier->supplier?->company_name ?: $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="overpayment-status" class="form-label tw-text-ui-xs tw-font-semibold">Status</label>
                <select id="overpayment-status" name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    <option value="OPEN" @selected(request('status') === 'OPEN')>OPEN</option>
                    <option value="SETTLED" @selected(request('status') === 'SETTLED')>SETTLED</option>
                </select>
            </div>
            <div class="lg:tw-col-span-2">
                <x-ui.date-range-picker
                    id="overpayment-date-range"
                    start-name="date_from"
                    end-name="date_to"
                    start-label="Created from"
                    end-label="Created to"
                    :start-value="request('date_from')"
                    :end-value="request('date_to')"
                    :compact="true"
                />
            </div>
            <div class="md:tw-col-span-2 lg:tw-col-span-5 tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                <x-ui.button type="submit" size="sm">Apply filters</x-ui.button>
                <x-ui.button :href="route('finance.overpayments.index')" variant="ghost" size="sm">Reset</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.data-table title="Receivables">
        <div class="table-responsive">
            <table class="table align-middle tw-text-ui-sm">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Supplier</th>
                        <th>Payment reference(s)</th>
                        <th class="text-end">Expected / Actual</th>
                        <th class="text-end">Overpayment</th>
                        <th>Status / Date</th>
                        <th>Action</th>
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
                            <td>
                                @if($refund->status === 'OPEN')
                                    <details>
                                        <summary class="tw-cursor-pointer tw-text-primary">Settle full refund</summary>
                                        <form method="POST" action="{{ route('finance.overpayments.refund', $refund) }}" enctype="multipart/form-data" class="tw-mt-2 tw-grid tw-min-w-[260px] tw-gap-2">
                                            @csrf
                                            <input name="refund_amount" type="number" step="0.01" value="{{ $refund->overpayment_amount }}" class="form-control form-control-sm" required>
                                            <input name="refund_reference" class="form-control form-control-sm" placeholder="Refund reference" required>
                                            <x-ui.date-picker :id="'refund_date_'.$refund->id" name="refund_date" :value="now()->format('Y-m-d')" required />
                                            <input name="proof" type="file" accept=".pdf,.jpg,.jpeg,.png" class="form-control form-control-sm" required>
                                            <textarea name="notes" class="form-control form-control-sm" placeholder="Notes"></textarea>
                                            <x-ui.button type="submit" size="sm">Settle Refund</x-ui.button>
                                        </form>
                                    </details>
                                @else
                                    <span class="tw-font-mono tw-text-ui-xs">{{ $refund->refund_reference }}</span>
                                    @if($refund->attachments->first())
                                        <a class="tw-mt-1 tw-block tw-text-ui-xs tw-text-primary" href="{{ route('attachments.show', $refund->attachments->first()) }}">View proof</a>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="tw-py-8 tw-text-center tw-text-on-surface-variant">No supplier overpayments.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:pagination>{{ $overpayments->links() }}</x-slot:pagination>
    </x-ui.data-table>
</div>
@endsection
