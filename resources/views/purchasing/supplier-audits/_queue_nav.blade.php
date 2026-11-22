{{-- Isi nav antrean; dirender server untuk halaman penuh maupun fragmen server tabs agar href (filter terbawa) dan hitungan selalu segar. --}}
@php
    $queueTabs = [
        'review' => 'clipboard-check',
        'waiting' => 'clock',
        'late' => 'triangle-alert',
        'done' => 'circle-check',
        'all' => 'layers',
    ];
    $baseQuery = array_filter(['period' => $filters['period'] ?? null, 'supplier' => $filters['supplier'] ?? null]);
@endphp
@foreach($queueTabs as $key => $icon)
    @php
        $count = $queueCounts[$key] ?? 0;
        $badgeTone = ($key === 'late' && $count > 0)
            ? 'tw:bg-error-container tw:text-error-container-foreground'
            : 'tw:bg-surface tw:text-on-surface-variant';
    @endphp
    <a
        href="{{ route('purchasing.supplier-audits.index', [...$baseQuery, 'queue' => $key]) }}"
        class="tw:group ui-focus-ring ui-motion tw:inline-flex tw:min-h-[var(--ui-control-height-sm)] tw:pointer-coarse:min-h-[var(--ui-control-height-md)] tw:grow tw:items-center tw:justify-center tw:gap-2 tw:sm:grow-0 tw:rounded-ui-sm tw:px-3.5 tw:py-1.5 tw:text-ui-xs tw:font-semibold tw:no-underline tw:text-on-surface-variant tw:not-aria-[current=page]:hover:bg-surface tw:not-aria-[current=page]:hover:text-on-surface tw:aria-[current=page]:bg-primary tw:aria-[current=page]:text-primary-foreground"
        data-server-tab
        data-tab-name="{{ $key }}"
        @if($queue === $key) aria-current="page" @endif data-queue-tab="{{ $key }}"
    >
        <x-ui.icon :name="$icon" size="sm" class="tw:shrink-0" />
        <span>{{ __('supplier_audit.queues.'.$key) }}</span>
        <span class="ui-tabular-nums tw:inline-flex tw:min-w-5 tw:items-center tw:justify-center tw:rounded-full tw:px-1.5 tw:py-0.5 tw:text-[11px] tw:font-bold tw:leading-none {{ $badgeTone }} tw:group-aria-[current=page]:bg-primary-foreground tw:group-aria-[current=page]:text-primary" data-queue-count="{{ $key }}">{{ $count }}</span>
    </a>
@endforeach
