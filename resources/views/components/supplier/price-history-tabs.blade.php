@props(['active'])

<nav {{ $attributes->class(['tw-flex tw-gap-1.5 tw-overflow-x-auto tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface tw-p-1.5']) }} aria-label="Supplier price history views">
    <a
        href="{{ route('supplier.price-history.index') }}"
        @if($active === 'overview') aria-current="page" @endif
        @class([
            'ui-focus-ring ui-motion tw-inline-flex tw-min-h-[var(--ui-control-height-sm)] tw-shrink-0 tw-items-center tw-gap-1.5 tw-rounded-ui-sm tw-px-3 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-no-underline',
            'tw-bg-primary tw-text-primary-foreground' => $active === 'overview',
            'tw-text-on-surface-variant hover:tw-bg-surface-container hover:tw-text-on-surface' => $active !== 'overview',
        ])
    >
        <x-ui.icon name="list" size="sm" />
        <span>Material Overview</span>
    </a>
    <a
        href="{{ route('supplier.price-history.historical') }}"
        @if($active === 'historical') aria-current="page" @endif
        @class([
            'ui-focus-ring ui-motion tw-inline-flex tw-min-h-[var(--ui-control-height-sm)] tw-shrink-0 tw-items-center tw-gap-1.5 tw-rounded-ui-sm tw-px-3 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-no-underline',
            'tw-bg-primary tw-text-primary-foreground' => $active === 'historical',
            'tw-text-on-surface-variant hover:tw-bg-surface-container hover:tw-text-on-surface' => $active !== 'historical',
        ])
    >
        <x-ui.icon name="trending-up" size="sm" />
        <span>Price Trends</span>
    </a>
</nav>
