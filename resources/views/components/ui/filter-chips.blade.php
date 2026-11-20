@props([
    'items' => [],
    'clearUrl' => null,
    'clearAttrs' => [],
    'id' => null,
    'serverTabs' => false,
    'activeLabel' => null,
    'clearLabel' => null,
    'removeTemplate' => null,
])

@php
    $resolvedActiveLabel = $activeLabel ?? __('common.filters.active');
    $resolvedClearLabel = $clearLabel ?? __('common.filters.clear_all');
    $resolvedRemoveTemplate = $removeTemplate ?? __('common.filters.remove_filter', ['label' => ':label']);
    $normalizedItems = collect($items)
        ->filter(fn ($item) => is_array($item) && filled($item['value'] ?? null))
        ->values()
        ->all();
    $clearAttrBag = new \Illuminate\View\ComponentAttributeBag(is_array($clearAttrs) ? $clearAttrs : []);
@endphp

<div
    @if($id) id="{{ $id }}" @endif
    role="region"
    aria-label="{{ rtrim($resolvedActiveLabel, ':') }}"
    aria-live="polite"
    aria-atomic="false"
    data-filter-bar-chips
    data-active-label="{{ $resolvedActiveLabel }}"
    data-clear-label="{{ $resolvedClearLabel }}"
    data-remove-template="{{ $resolvedRemoveTemplate }}"
    {{ $attributes->class(['ui-filter-chips', 'tw:hidden' => empty($normalizedItems)]) }}
>
    @if(! empty($normalizedItems))
        <span class="ui-filter-chips__label">{{ $resolvedActiveLabel }}</span>

        @foreach($normalizedItems as $item)
            @php
                $itemLabel = (string) ($item['label'] ?? '');
                $itemValue = (string) ($item['value'] ?? '');
                $removeUrl = $item['removeUrl'] ?? null;
                $removeAttrBag = new \Illuminate\View\ComponentAttributeBag(is_array($item['removeAttrs'] ?? null) ? $item['removeAttrs'] : []);
            @endphp
            <span class="ui-filter-chip" data-filter-chip-key="{{ $item['key'] ?? '' }}">
                <span class="ui-filter-chip__key">{{ $itemLabel }}:</span>
                <span class="ui-filter-chip__value">{{ $itemValue }}</span>
                @if(! empty($removeUrl))
                    <a
                        href="{{ $removeUrl }}"
                        class="ui-filter-chip__remove ui-focus-ring"
                        aria-label="{{ __('common.filters.remove_filter', ['label' => $itemLabel.': '.$itemValue]) }}"
                        @if($serverTabs) data-server-tabs-reset @endif
                        {{ $removeAttrBag }}
                    >
                        <x-ui.icon name="x" size="xs" />
                    </a>
                @endif
            </span>
        @endforeach

        @if(! empty($clearUrl))
            <a
                href="{{ $clearUrl }}"
                class="ui-filter-chips__clear ui-focus-ring"
                @if($serverTabs) data-server-tabs-reset @endif
                {{ $clearAttrBag }}
            >{{ $resolvedClearLabel }}</a>
        @endif
    @endif
</div>
