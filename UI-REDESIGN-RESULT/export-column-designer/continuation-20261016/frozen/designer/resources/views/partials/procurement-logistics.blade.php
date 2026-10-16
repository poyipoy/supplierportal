{{-- Read-only Port of Discharge / Incoterms (PR value or PO snapshot). NULL renders "Not set". --}}
<dl class="tw-m-0 tw-grid tw-gap-px tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-outline-variant sm:tw-grid-cols-2" aria-label="{{ __('procurement.logistics.section_title') }}">
    <div class="tw-bg-surface tw-px-3 tw-py-2">
        <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-text-on-surface-variant">{{ __('procurement.logistics.port_of_discharge') }}</dt>
        <dd class="tw-m-0 tw-text-ui-sm tw-font-semibold tw-text-on-surface">{{ \App\Support\Procurement\LogisticsTerms::portLabel($port) }}</dd>
    </div>
    <div class="tw-bg-surface tw-px-3 tw-py-2">
        <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-text-on-surface-variant">{{ __('procurement.logistics.incoterm') }}</dt>
        <dd class="tw-m-0 tw-text-ui-sm tw-font-semibold tw-text-on-surface">{{ \App\Support\Procurement\LogisticsTerms::incotermLabel($incoterm) }}</dd>
    </div>
</dl>
