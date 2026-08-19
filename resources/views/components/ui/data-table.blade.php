@props([
    'title' => null,
    'description' => null,
    'loading' => false,
    'empty' => false,
    'error' => null,
])

<section {{ $attributes->class(['ui-data-table tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface tw-shadow-ui-1']) }}>
    @if($title || $description || isset($toolbar) || isset($filters))
        <header class="tw-grid tw-gap-3 tw-border-b tw-border-outline-variant tw-p-4 shell:tw-px-5">
            <div class="tw-flex tw-flex-col tw-gap-3 shell:tw-flex-row shell:tw-items-start shell:tw-justify-between">
                <div class="tw-min-w-0">
                    @if($title)<h2 class="tw-m-0 tw-text-ui-lg tw-font-semibold tw-text-on-surface">{{ $title }}</h2>@endif
                    @if($description)<p class="tw-m-0 tw-mt-1 tw-text-ui-sm tw-text-on-surface-variant">{{ $description }}</p>@endif
                </div>
                @isset($toolbar)<div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">{{ $toolbar }}</div>@endisset
            </div>
            @isset($filters)<div class="tw-flex tw-flex-col tw-gap-3 shell:tw-flex-row shell:tw-flex-wrap shell:tw-items-end">{{ $filters }}</div>@endisset
        </header>
    @endif

    @if($loading)
        <div class="tw-grid tw-gap-2 tw-p-4" role="status" aria-label="Memuat data">
            @for($row = 0; $row < 5; $row++)<x-ui.skeleton class="tw-h-10 tw-w-full" />@endfor
        </div>
    @elseif($error)
        <div class="tw-p-4"><x-ui.alert tone="error" title="Data tidak dapat dimuat">{{ $error }}</x-ui.alert></div>
    @elseif($empty)
        @isset($emptyState){{ $emptyState }}@else<x-ui.empty-state title="Belum ada data" description="Data akan muncul di sini setelah tersedia." />@endisset
    @else
        <div class="ui-data-table__scroll tw-overflow-x-auto">{{ $slot }}</div>
    @endif

    @isset($pagination)<footer class="tw-border-t tw-border-outline-variant tw-p-4">{{ $pagination }}</footer>@endisset
</section>
