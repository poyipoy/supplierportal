@props([
    'id' => null,
    'testId' => null,
    'icon' => 'bi-clipboard-data',
    'title' => 'No data available',
    'text' => '',
    'actionUrl' => null,
    'actionText' => null,
    'actionIcon' => 'bi-plus-circle'
])
@php
    $baseAttributes = ['class' => 'text-center py-5'];

    if ($id) {
        $baseAttributes['id'] = $id;
    }

    if ($testId) {
        $baseAttributes['data-testid'] = $testId;
    }
@endphp
<div {{ $attributes->merge($baseAttributes) }}>
    <i class="bi {{ $icon }} text-secondary" style="font-size: 3rem; opacity: 0.4;"></i>
    <h6 class="mt-3 fw-bold">{{ $title }}</h6>
    @if($text)
        <p class="text-muted small mb-3">{{ $text }}</p>
    @endif
    @if($actionUrl && $actionText)
        <a href="{{ $actionUrl }}" class="btn btn-primary btn-sm mt-2">
            <i class="bi {{ $actionIcon }} me-1"></i> {{ $actionText }}
        </a>
    @endif
    {{ $slot }}
</div>
