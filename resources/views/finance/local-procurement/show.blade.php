@extends('layouts.app')
@section('title', __('local_procurement.closure.po_detail_title', ['number' => $purchaseOrder->po_number]))
@section('uses-datatables', true)
@section('page-title', __('local_procurement.list.detail'))

@php
    $activeGrCount = $purchaseOrder->active_gr_count;
    $invoicedTotal = \App\Support\Money::normalize($purchaseOrder->active_invoice_amount);
    $remainingPoAmount = \App\Support\Money::atLeastZero(\App\Support\Money::subtract($purchaseOrder->total_amount, $invoicedTotal));
    $invoicedPercent = bccomp($purchaseOrder->total_amount, '0', 2) > 0 ? bcdiv(bcmul($invoicedTotal, '100', 3), $purchaseOrder->total_amount, 1) : '0';
    $invoicedPercent = bccomp($invoicedPercent, '100', 1) > 0 ? '100' : $invoicedPercent;
@endphp

@section('content')
<div class="tw:grid tw:gap-6 tw:pb-16">
    {{-- Header --}}
    <x-ui.page-header
        :title="'PO ' . $purchaseOrder->po_number"
        :description="($purchaseOrder->supplier->supplier?->company_name ?: $purchaseOrder->supplier->name) . ' · IDR ' . $regionalFormatter->money($purchaseOrder->total_amount)"
        :eyebrow="__('local_procurement.surface.local_audience')">
        <x-slot:actions>
            <x-ui.button :href="route($routePrefix.'.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('local_invoice.form.back_list') }}</span>
            </x-ui.button>

            @if($purchaseOrder->latestPoDocument())
                <x-ui.button :href="route('attachments.show', $purchaseOrder->latestPoDocument())" target="_blank" variant="outline" size="sm">
                    <x-ui.icon name="file-text" size="sm" />
                    <span>{{ __('local_procurement.labels.po_pdf') }}</span>
                </x-ui.button>
            @endif

            @if($purchaseOrder->status === 'OPEN')
                <x-ui.button type="button" size="sm" variant="primary" data-bs-toggle="modal" data-bs-target="#addGrModal">
                    <x-ui.icon name="plus" size="sm" />
                    <span>{{ __('local_procurement.actions.add_gr') }}</span>
                </x-ui.button>

                <x-ui.button :href="route($routePrefix.'.edit', $purchaseOrder)" variant="outline" size="sm">
                    <x-ui.icon name="file-pen" size="sm" />
                    <span>{{ __('local_procurement.actions.edit_po') }}</span>
                </x-ui.button>

                <button type="button" class="btn btn-sm btn-outline-secondary tw:inline-flex tw:items-center tw:gap-1.5" data-bs-toggle="modal" data-bs-target="#closePoModal">
                    <x-ui.icon name="lock" size="sm" />
                    <span>{{ __('local_procurement.actions.close_po') }}</span>
                </button>

                <button type="button" class="btn btn-sm btn-outline-danger tw:inline-flex tw:items-center tw:gap-1.5" data-bs-toggle="modal" data-bs-target="#cancelPoModal">
                    <x-ui.icon name="x-circle" size="sm" />
                    <span>{{ __('local_procurement.actions.cancel_po') }}</span>
                </button>
            @endif
            @if($purchaseOrder->status === 'CANCELLED')
                @can('restore', $purchaseOrder)
                    <x-ui.button type="button" variant="primary" data-bs-toggle="modal" data-bs-target="#restorePoModal">{{ __('local_procurement.recovery.restore_po') }}</x-ui.button>
                @endcan
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- PO Information & Allocation Summary Card --}}
    <div class="tw:grid tw:grid-cols-1 tw:lg:grid-cols-3 tw:gap-4">
        <div class="tw:lg:col-span-2 tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-surface-container tw:p-4 tw:space-y-4">
            <div class="tw:flex tw:flex-col tw:sm:flex-row tw:sm:items-center tw:sm:justify-between tw:gap-3">
                <div class="tw:flex tw:items-center tw:gap-3">
                    <div class="tw:w-10 tw:h-10 tw:rounded-full tw:bg-primary/10 tw:text-primary tw:flex tw:items-center tw:justify-center tw:shrink-0">
                        <x-ui.icon name="file-text" size="md" />
                    </div>
                    <div>
                        <div class="tw:text-ui-base tw:font-bold tw:font-mono tw:text-on-surface">{{ $purchaseOrder->po_number }}</div>
                        <div class="tw:text-ui-xs tw:text-on-surface-variant">
                            {{ __('local_procurement.surface.created_source', ['date' => $regionalFormatter->date($purchaseOrder->po_date, 'human') ?? '-', 'source' => __('local_procurement.sources.'.strtolower($purchaseOrder->source ?: 'MANUAL'))]) }}
                        </div>
                    </div>
                </div>
                <div class="tw:flex tw:items-center tw:gap-2">
                    <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($purchaseOrder->status)">
                        {{ __('common.final_copy.status') }} {{ \App\Support\StatusHelper::localFinanceLabel($purchaseOrder->status) }}
                    </x-ui.status-chip>
                </div>
            </div>

            <div class="tw:grid tw:grid-cols-1 tw:sm:grid-cols-2 tw:gap-3 tw:border-t tw:border-outline-variant tw:pt-3 tw:text-ui-xs">
                <div class="tw:sm:col-span-2 tw:min-w-0">
                    <span class="tw:text-on-surface-variant">{{ __('local_procurement.pr.label') }}:</span>
                    <span class="tw:block tw:font-mono tw:text-on-surface tw:mt-0.5" style="overflow-wrap: anywhere;">{{ filled($purchaseOrder->pr_number) ? $purchaseOrder->pr_number : __('local_procurement.pr.empty') }}</span>
                </div>
                <div>
                    <span class="tw:text-on-surface-variant">{{ __('local_invoice.labels.supplier') }}:</span>
                    <strong class="tw:block tw:text-ui-sm tw:text-on-surface tw:mt-0.5">
                        {{ $purchaseOrder->supplier->supplier?->company_name ?: $purchaseOrder->supplier->name }}
                    </strong>
                    <span class="tw:text-on-surface-variant">
                        {{ $purchaseOrder->supplier->email }} · {{ \App\Support\StatusHelper::vendorCategoryLabel($purchaseOrder->supplier->supplier?->vendor_category ?: $purchaseOrder->supplier->supplier?->category ?: __('local_invoice.labels.supplier')) }}
                    </span>
                </div>
                <div>
                    <span class="tw:text-on-surface-variant">{{ __('local_procurement.labels.po_notes') }}:</span>
                    <p class="tw:text-ui-xs tw:text-on-surface tw:mt-0.5 tw:mb-0">
                        {{ $purchaseOrder->description === 'Imported via Infor ERP Purchase Order' ? __('local_procurement.provenance.imported_po') : ($purchaseOrder->description ?: __('local_procurement.closure.po_no_notes')) }}
                    </p>
                </div>
            </div>
        </div>

        {{-- Financial & Invoiced Progress Card --}}
        <div class="tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-surface-container tw:p-4 tw:space-y-3">
            <div class="tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant tw:uppercase tw:tracking-wider">
                {{ __('local_procurement.labels.bill_status') }}
            </div>

            <div class="tw:space-y-2 tw:text-ui-xs">
                <div class="tw:flex tw:items-center tw:justify-between">
                    <span class="tw:text-on-surface-variant">{{ __('local_procurement.surface.po_ceiling_label') }}</span>
                    <strong class="tw:font-mono tw:text-on-surface">Rp {{ $regionalFormatter->money($purchaseOrder->total_amount) }}</strong>
                </div>
                <div class="tw:flex tw:items-center tw:justify-between">
                    <span class="tw:text-on-surface-variant">{{ __('local_procurement.surface.active_invoiced') }}</span>
                    <strong class="tw:font-mono tw:text-success">Rp {{ $regionalFormatter->money($invoicedTotal, 2) }}</strong>
                </div>
                <div class="tw:flex tw:items-center tw:justify-between">
                    <span class="tw:text-on-surface-variant">{{ __('local_procurement.labels.gr_list') }}:</span>
                    <strong class="tw:font-mono tw:text-on-surface">{{ $activeGrCount }} GR</strong>
                </div>
                <div class="tw:flex tw:items-center tw:justify-between tw:border-t tw:border-outline-variant tw:pt-1.5">
                    <span class="tw:text-on-surface-variant">{{ __('local_procurement.labels.remaining_ceiling') }}:</span>
                    <strong class="tw:font-mono {{ \App\Support\Money::compare($remainingPoAmount, \App\Support\Money::ZERO) > 0 ? 'tw:text-primary' : 'tw:text-on-surface-variant' }}">
                        Rp {{ $regionalFormatter->money($remainingPoAmount, 2) }}
                    </strong>
                </div>
            </div>

            {{-- Progress bar --}}
            <div class="tw:space-y-1 tw:pt-1">
                <div class="tw:flex tw:items-center tw:justify-between tw:text-[11px] tw:text-on-surface-variant">
                    <span>{{ __('local_procurement.surface.invoiced') }}</span>
                    <span class="tw:font-semibold tw:text-on-surface">{{ $invoicedPercent }}%</span>
                </div>
                <div class="tw:w-full tw:bg-surface-high tw:rounded-full tw:h-2 tw:overflow-hidden">
                    <div class="tw:bg-primary tw:h-2 tw:rounded-full tw:transition-all" style="width: {{ $invoicedPercent }}%"></div>
                </div>
            </div>
        </div>
    </div>

    @include('finance.local-procurement._gr_table')

</div>

{{-- Modal Tambah GR Baru --}}
@if($purchaseOrder->status === 'OPEN')
    <div class="modal fade" id="addGrModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route($routePrefix.'.goods-receipts.store', $purchaseOrder) }}">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title tw:text-ui-sm tw:font-bold tw:text-on-surface tw:flex tw:items-center tw:gap-2">
                            <x-ui.icon name="plus-circle" size="sm" class="tw:text-primary" />
                            <span>{{ __('local_procurement.actions.add_gr_full') }}</span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
                    </div>
                    <div class="modal-body tw:space-y-3">
                        <div class="tw:rounded-lg tw:bg-surface-container tw:p-3 tw:text-ui-xs tw:space-y-1">
                            <div class="tw:flex tw:justify-between">
                                <span class="tw:text-on-surface-variant">{{ __('terms.purchase_order') }}:</span>
                                <strong class="tw:font-mono">{{ $purchaseOrder->po_number }}</strong>
                            </div>
                        </div>

                        <div>
                            <label class="form-label tw:text-ui-xs tw:font-semibold">{{ __('local_procurement.surface.gr_number') }}<span class="text-danger">*</span></label>
                            <input name="gr_number" class="form-control form-control-sm" placeholder="{{ __('common.reference_example', ['reference' => 'GR-2026-09-001']) }}" required>
                        </div>

                        <div>
                            <x-ui.date-picker
                                id="add_new_gr_date"
                                name="gr_date"
                                :label="__('local_procurement.labels.gr_date')"
                                :value="now()->format('Y-m-d')"
                                required="true"
                            />
                        </div>

                        <div>
                            <label class="form-label tw:text-ui-xs tw:font-semibold">{{ __('local_procurement.closure.quantity') }} <span class="text-danger">*</span></label>
                            <input
                                name="qty"
                                type="number"
                                step="0.001"
                                min="0.001"
                                class="form-control form-control-sm tw:font-mono"
                                placeholder="1.125"
                                required
                            >
                        </div>
                        <div>
                            <label for="gr_uom_new" class="form-label tw:text-ui-xs tw:font-semibold">{{ __('local_procurement.labels.uom') }} <span class="text-danger">*</span></label>
                            <select name="uom" id="gr_uom_new" class="form-select form-select-sm @error('uom') is-invalid @enderror" @error('uom') aria-invalid="true" aria-describedby="gr_uom_new_error" @enderror required>
                                <option value="">{{ __('local_procurement.labels.choose_uom') }}</option>
                                @foreach(\App\Models\LocalGoodsReceipt::UOMS as $uom)
                                    <option value="{{ $uom }}" @selected(old('uom') === $uom)>{{ $uom }}</option>
                                @endforeach
                            </select>
                            @error('uom')<div id="gr_uom_new_error" class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div>
                            <label class="form-label tw:text-ui-xs tw:font-semibold">{{ __('local_procurement.labels.goods_description_optional') }}</label>
                            <input name="description" class="form-control form-control-sm" placeholder="{{ __('local_procurement.surface.material_placeholder') }}">
                        </div>

                        <div>
                            <label class="form-label tw:text-ui-xs tw:font-semibold">{{ __('local_procurement.surface.notes') }}</label>
                            <input name="notes" class="form-control form-control-sm" placeholder="{{ __('local_procurement.surface.physical_notes') }}">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __('local_invoice.actions.cancel') }}</x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="plus" size="sm" />
                            <span>{{ __('local_procurement.actions.save_gr') }}</span>
                        </x-ui.button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Konfirmasi Tutup PO --}}
    <div class="modal fade" id="closePoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route($routePrefix.'.close', $purchaseOrder) }}">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title tw:text-ui-sm tw:font-bold tw:text-on-surface tw:flex tw:items-center tw:gap-2">
                            <x-ui.icon name="lock" size="sm" class="tw:text-warning" />
                            <span>{{ __('local_procurement.actions.close_po_full') }}</span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
                    </div>
                    <div class="modal-body tw:space-y-2">
                        <p class="tw:text-ui-sm tw:text-on-surface tw:mb-0">
                            {{ __('local_procurement.surface.close_po', ['number' => $purchaseOrder->po_number]) }}
                        </p>
                        <p class="tw:text-ui-xs tw:text-on-surface-variant tw:mb-0">{{ __('local_procurement.surface.close_po_help') }}</p>
                    </div>
                    <div class="modal-footer">
                        <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __('local_invoice.actions.cancel') }}</x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="lock" size="sm" />
                            <span>{{ __('local_procurement.surface.close_po_yes') }}</span>
                        </x-ui.button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Konfirmasi Batal PO --}}
    <div class="modal fade" id="cancelPoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route($routePrefix.'.cancel', $purchaseOrder) }}">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title tw:text-ui-sm tw:font-bold tw:text-error tw:flex tw:items-center tw:gap-2">
                            <x-ui.icon name="alert-triangle" size="sm" />
                            <span>{{ __('local_procurement.actions.cancel_po_full') }}</span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
                    </div>
                    <div class="modal-body tw:space-y-2">
                        <p class="tw:text-ui-sm tw:text-on-surface tw:mb-0">
                            {{ __('local_procurement.surface.cancel_po', ['number' => $purchaseOrder->po_number]) }}
                        </p>
                        <p class="tw:text-ui-xs tw:text-on-surface-variant tw:mb-0">{{ __('local_procurement.surface.cancel_po_help') }}</p>
                    </div>
                    <div class="modal-footer">
                        <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __('local_invoice.actions.cancel') }}</x-ui.button>
                        <button type="submit" class="btn btn-sm btn-danger tw:inline-flex tw:items-center tw:gap-1">
                            <x-ui.icon name="trash-2" size="sm" />
                            <span>{{ __('local_procurement.surface.cancel_po_yes') }}</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endif
@if($purchaseOrder->status === 'CANCELLED')
    @can('restore', $purchaseOrder)
        @include('finance.local-procurement._restore_modal', ['recoveryKind' => 'po', 'recoveryTarget' => $purchaseOrder])
    @endcan
@endif
@endsection
