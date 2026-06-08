@props(['items' => []])
@php
    $normalizedItems = collect($items)->map(function ($item, $label) {
        if (is_array($item)) {
            return [
                'label' => $item['label'] ?? $label,
                'url' => $item['url'] ?? null,
            ];
        }

        return [
            'label' => $label,
            'url' => $item,
        ];
    })->values();
@endphp
@if($normalizedItems->isNotEmpty())
<nav aria-label="breadcrumb" class="bg-light py-2 px-3 rounded shadow-sm mb-4">
    <ol class="breadcrumb mb-0" style="font-size: 0.85rem;">
        @foreach($normalizedItems as $item)
            @if($loop->last || empty($item['url']))
                <li class="breadcrumb-item active fw-bold text-truncate" style="max-width: 250px;" aria-current="page">{{ $item['label'] }}</li>
            @else
                <li class="breadcrumb-item"><a href="{{ $item['url'] }}" class="text-decoration-none">{{ $item['label'] }}</a></li>
            @endif
        @endforeach
    </ol>
</nav>
@endif
