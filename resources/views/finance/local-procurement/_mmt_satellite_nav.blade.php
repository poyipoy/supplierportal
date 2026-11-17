@php
    $tabs = [
        'goods-receipts' => [
            'label' => __('local_procurement.mmt.tabs.goods_receipts'),
            'icon' => 'package-check',
        ],
        'invoices' => [
            'label' => __('local_procurement.mmt.tabs.invoices'),
            'icon' => 'receipt',
        ],
        'documents' => [
            'label' => __('local_procurement.mmt.tabs.documents'),
            'icon' => 'file-text',
        ],
    ];
@endphp

<div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2">
    <div class="tw:flex tw:items-center tw:gap-2 tw:overflow-x-auto tw:pb-1">
        @foreach($tabs as $key => $tab)
            @php
                $isActive = $activeTab === $key;
                $count = $counts[$key] ?? 0;
                $countTone = $count > 0
                    ? 'tw:bg-surface-high tw:text-on-surface'
                    : 'tw:bg-surface-container tw:text-on-surface-variant';
            @endphp
            <a
                href="{{ route($routePrefix.'.satellite', ['purchaseOrder' => $purchaseOrder, 'tab' => $key]) }}"
                data-server-tab
                data-tab-name="{{ $key }}"
                @if($isActive) aria-current="page" @endif
                class="ui-focus-ring ui-motion tw:group tw:inline-flex tw:shrink-0 tw:items-center tw:gap-2 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface tw:px-3 tw:py-1.5 tw:text-ui-xs tw:font-medium tw:text-on-surface-variant tw:no-underline tw:hover:bg-surface-container tw:aria-[current=page]:border-primary tw:aria-[current=page]:bg-primary tw:aria-[current=page]:text-primary-foreground tw:pointer-coarse:min-h-11"
            >
                <x-ui.icon :name="$tab['icon']" size="xs" />
                <span>{{ $tab['label'] }}</span>
                <span class="ui-tabular-nums tw:inline-flex tw:min-w-5 tw:items-center tw:justify-center tw:rounded-full tw:px-1.5 tw:py-0.5 tw:text-[11px] tw:font-bold {{ $countTone }} tw:group-aria-[current=page]:bg-surface tw:group-aria-[current=page]:text-primary">{{ $count }}</span>
            </a>
        @endforeach
    </div>

    <x-ui.button :href="route($routePrefix.'.show', $purchaseOrder)" variant="outline" size="sm">
        <x-ui.icon name="external-link" size="xs" />
        <span>{{ __('local_procurement.mmt.open_detail') }}</span>
    </x-ui.button>
</div>
