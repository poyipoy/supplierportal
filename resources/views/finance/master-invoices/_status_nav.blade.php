@php
    $tabs = [
        'ALL' => [
            'label' => __('local_invoice.filters.all_invoices'),
            'icon' => 'layers',
            'tone' => 'tw:bg-surface-container-high tw:text-on-surface-variant',
        ],
        'UNPAID' => [
            'label' => __('finance.drp.unpaid_label'),
            'icon' => 'clock',
            'tone' => 'tw:bg-warning-container tw:text-on-warning-container',
        ],
        'PAID' => [
            'label' => __('finance.drp.paid_label'),
            'icon' => 'badge-check',
            'tone' => 'tw:bg-success-container tw:text-on-success-container',
        ],
    ];

    $activePaymentStatus = strtoupper((string) ($paymentStatus ?? request('payment_status', 'ALL')));
    if (! in_array($activePaymentStatus, ['ALL', 'UNPAID', 'PAID'], true)) {
        $activePaymentStatus = 'ALL';
    }

    $baseQuery = collect($filters ?? request()->except('page'))
        ->except(['page', 'payment_status'])
        ->filter(fn ($value) => $value !== null && $value !== '')
        ->all();
@endphp

<div class="tw:flex tw:items-center tw:gap-2 tw:overflow-x-auto tw:pb-1">
    @foreach($tabs as $key => $tab)
        @php
            $isActive = $activePaymentStatus === $key;
            $queryParams = $key === 'ALL'
                ? $baseQuery
                : array_merge($baseQuery, ['payment_status' => $key]);
        @endphp
        <a
            href="{{ route('finance.master-invoices', $queryParams) }}"
            data-server-tab
            data-tab-name="{{ $key }}"
            @if($isActive) aria-current="page" @endif
            class="ui-focus-ring ui-motion tw:group tw:inline-flex tw:shrink-0 tw:items-center tw:gap-2 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface tw:px-3.5 tw:py-2 tw:text-ui-sm tw:font-medium tw:text-on-surface-variant tw:no-underline tw:hover:bg-surface-container tw:aria-[current=page]:border-primary tw:aria-[current=page]:bg-primary tw:aria-[current=page]:text-primary-foreground"
        >
            <x-ui.icon :name="$tab['icon']" size="xs" />
            <span>{{ $tab['label'] }}</span>
            <span
                class="ui-tabular-nums tw:inline-flex tw:min-w-5 tw:items-center tw:justify-center tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold {{ $tab['tone'] }} tw:group-aria-[current=page]:bg-surface tw:group-aria-[current=page]:text-primary"
                data-tab-count="{{ $key }}"
            >{{ number_format($counts[$key] ?? 0) }}</span>
        </a>
    @endforeach
</div>

