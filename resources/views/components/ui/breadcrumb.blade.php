@props(['items' => []])

@php
    $normalizedItems = collect($items)->map(function ($item, $label) {
        return is_array($item)
            ? ['label' => $item['label'] ?? $label, 'url' => $item['url'] ?? null]
            : ['label' => $label, 'url' => $item];
    })->values();
@endphp

@if($normalizedItems->isNotEmpty())
    <nav aria-label="Breadcrumb" {{ $attributes }}>
        <ol class="tw-m-0 tw-flex tw-list-none tw-flex-wrap tw-items-center tw-gap-1 tw-p-0 tw-text-ui-xs tw-text-on-surface-variant">
            @foreach($normalizedItems as $item)
                <li class="tw-flex tw-min-w-0 tw-items-center tw-gap-1">
                    @if(!$loop->first)<x-ui.icon name="chevron-right" size="sm" class="tw-text-outline" />@endif
                    @if($loop->last || empty($item['url']))
                        <span class="tw-max-w-[18rem] tw-truncate tw-font-semibold tw-text-on-surface" aria-current="page">{{ $item['label'] }}</span>
                    @else
                        <a href="{{ $item['url'] }}" class="ui-focus-ring tw-rounded-ui-xs tw-text-on-surface-variant hover:tw-text-primary tw-no-underline">{{ $item['label'] }}</a>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
