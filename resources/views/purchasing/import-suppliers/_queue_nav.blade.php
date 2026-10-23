{{-- Rendered for the full page and for every server-tabs fragment so hrefs keep the search and counts stay fresh. --}}
@php
    $baseQuery = array_filter(['q' => $search !== '' ? $search : null]);
    $tabs = [
        'pending' => ['label' => __('supplier_contact.directory.filter_pending'), 'icon' => 'clock'],
        'missing' => ['label' => __('supplier_contact.directory.filter_missing'), 'icon' => 'user-x'],
        'all' => ['label' => __('supplier_contact.directory.filter_all'), 'icon' => 'layers'],
    ];
@endphp
@foreach($tabs as $key => $tab)
    @php
        // Pending items are real work, so their count keeps a warning tone until the tab is opened.
        $countTone = $key === 'pending' && $queueCounts[$key] > 0
            ? 'tw:bg-warning-container tw:text-warning-container-foreground'
            : 'tw:bg-surface-high tw:text-on-surface';
    @endphp
    <a
        href="{{ route('purchasing.import-suppliers.index', [...$baseQuery, 'contact' => $key]) }}"
        class="ui-focus-ring ui-motion tw:group tw:inline-flex tw:shrink-0 tw:items-center tw:gap-2 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface tw:px-3.5 tw:py-2 tw:text-ui-sm tw:font-medium tw:text-on-surface-variant tw:no-underline tw:hover:bg-surface-container tw:aria-[current=page]:border-primary tw:aria-[current=page]:bg-primary tw:aria-[current=page]:text-primary-foreground"
        data-server-tab data-tab-name="{{ $key }}"
        @if($queue === $key) aria-current="page" @endif
    >
        <x-ui.icon :name="$tab['icon']" size="xs" />
        <span>{{ $tab['label'] }}</span>
        <span class="ui-tabular-nums tw:inline-flex tw:min-w-5 tw:items-center tw:justify-center tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold {{ $countTone }} tw:group-aria-[current=page]:bg-surface tw:group-aria-[current=page]:text-primary">{{ $queueCounts[$key] }}</span>
    </a>
@endforeach
