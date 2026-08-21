@props(['active'])

@php
    $tabs = [
        'inter-supplier' => [
            'label' => 'Inter-Supplier',
            'icon' => 'users',
            'route' => 'purchasing.comparison.inter-supplier',
        ],
        'historical' => [
            'label' => 'Historical',
            'icon' => 'chart-no-axes-combined',
            'route' => 'purchasing.comparison.historical',
        ],
        'vs-best' => [
            'label' => 'vs Best Price',
            'icon' => 'trophy',
            'route' => 'purchasing.comparison.vs-best',
        ],
    ];
@endphp

<nav {{ $attributes->class(['tw-flex tw-gap-2 tw-overflow-x-auto tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface tw-p-2 tw-shadow-ui-1']) }} aria-label="Price comparison views">
    @foreach($tabs as $key => $tab)
        <a
            href="{{ \App\Support\PurchasingNavigation::listUrl($tab['route']) }}"
            @if($active === $key) aria-current="page" @endif
            @class([
                'ui-focus-ring ui-motion tw-inline-flex tw-min-h-[var(--ui-control-height-sm)] tw-shrink-0 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-px-3 tw-py-2 tw-text-ui-sm tw-font-semibold tw-no-underline',
                'tw-bg-primary tw-text-primary-foreground' => $active === $key,
                'tw-text-on-surface-variant hover:tw-bg-surface-container hover:tw-text-on-surface' => $active !== $key,
            ])
        >
            <x-ui.icon :name="$tab['icon']" />
            <span>{{ $tab['label'] }}</span>
        </a>
    @endforeach
</nav>
