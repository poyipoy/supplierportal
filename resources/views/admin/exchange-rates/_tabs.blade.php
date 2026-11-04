@php
    $exchangeRateTabs = [
        'effective' => [
            'label' => __('annual_rates.effective_tab'),
            'icon' => 'badge-dollar-sign',
        ],
        'annual' => [
            'label' => __('annual_rates.annual_tab'),
            'icon' => 'calendar-days',
        ],
    ];
@endphp

@foreach($exchangeRateTabs as $tab => $tabConfig)
    <a
        data-server-tab
        data-tab-name="{{ $tab }}"
        href="{{ route('admin.exchange-rates.index', ['tab' => $tab]) }}"
        @if($activeTab === $tab) aria-current="page" @endif
        class="ui-focus-ring ui-motion tw:inline-flex tw:min-h-[var(--ui-control-height-sm)] tw:shrink-0 tw:items-center tw:gap-2 tw:rounded-ui-sm tw:px-3.5 tw:py-1.5 tw:text-ui-sm tw:font-semibold tw:no-underline tw:text-on-surface-variant tw:hover:bg-surface tw:hover:text-on-surface tw:aria-[current=page]:bg-primary tw:aria-[current=page]:text-primary-foreground tw:aria-[current=page]:shadow-xs"
    >
        <x-ui.icon :name="$tabConfig['icon']" size="sm" />
        <span>{{ $tabConfig['label'] }}</span>
    </a>
@endforeach
