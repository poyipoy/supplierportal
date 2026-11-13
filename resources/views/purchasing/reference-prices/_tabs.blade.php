@php
    $rp = $rp ?? 'materials.reference_prices';
    $prefix = $prefix ?? (request()->user()?->role === 'admin' ? 'admin.reference-prices' : 'purchasing.master-data.reference-prices');
    $referencePriceTabs = [
        'rules' => [
            'label' => __($rp.'.tabs.rules'),
            'icon' => 'list-filter',
        ],
        'benchmark' => [
            'label' => __($rp.'.tabs.benchmark'),
            'icon' => 'history',
        ],
        'legacy' => [
            'label' => __($rp.'.tabs.legacy'),
            'icon' => 'archive',
        ],
    ];
@endphp

@foreach($referencePriceTabs as $tab => $tabConfig)
    @php
        $isActive = ($activeTab === $tab) || ($tab === 'benchmark' && $activeTab === 'historical');
    @endphp
    <a
        data-server-tab
        data-tab-name="{{ $tab }}"
        href="{{ route($prefix.'.index', ['tab' => $tab]) }}"
        @if($isActive) aria-current="page" @endif
        class="ui-focus-ring ui-motion tw:inline-flex tw:min-h-[var(--ui-control-height-sm)] tw:shrink-0 tw:items-center tw:gap-2 tw:rounded-ui-sm tw:px-3.5 tw:py-1.5 tw:text-ui-sm tw:font-semibold tw:no-underline tw:text-on-surface-variant tw:hover:bg-surface tw:hover:text-on-surface tw:aria-[current=page]:bg-primary tw:aria-[current=page]:text-primary-foreground tw:aria-[current=page]:shadow-xs"
    >
        <x-ui.icon :name="$tabConfig['icon']" size="sm" />
        <span>{{ $tabConfig['label'] }}</span>
    </a>
@endforeach

