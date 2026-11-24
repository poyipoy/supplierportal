@props([
    'label' => null,
    'size' => 16,
    'compact' => false,
])

@php
    $resolvedLabel = $label ?: __('js.tables.loading');
    $resolvedSize = max(14, (int) $size);
@endphp

<div {{ $attributes->class(['ui-inline-loading', 'ui-inline-loading--compact' => $compact])->merge(['role' => 'status', 'aria-live' => 'polite']) }}>
    <div class="ui-inline-loading__pill">
        <svg class="ui-dt-loading-pill__spinner" viewBox="0 0 20 20" width="{{ $resolvedSize }}" height="{{ $resolvedSize }}" focusable="false" aria-hidden="true">
            <circle class="ui-dt-loading-pill__track" cx="10" cy="10" r="8" />
            <circle class="ui-dt-loading-pill__arc" cx="10" cy="10" r="8" />
        </svg>
        <span class="ui-inline-loading__text">{{ $resolvedLabel }}</span>
    </div>
</div>
