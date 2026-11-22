@props([
    'href' => null,
    'tone' => 'primary',
    'label' => null,
    'context' => null,
    'icon' => null,
    'type' => 'button',
])

@php
    $slotContent = trim((string) $slot);
    $hasCustomSlot = $slotContent !== '';
    $resolvedLabel = $label ?? ($hasCustomSlot ? $slot : __('common.actions.details'));
    $visibleLabelText = trim(strip_tags((string) $resolvedLabel));
    $isDefaultLabel = ($label === null && ! $hasCustomSlot)
        || $visibleLabelText === __('common.actions.details');
    $baseClasses = \App\Support\Ui\RowAction::classesForTone((string) $tone);
    $resolvedAriaLabel = \App\Support\Ui\RowAction::resolveAriaLabel(
        $visibleLabelText,
        $context !== null ? (string) $context : null,
        $attributes->get('aria-label'),
        $isDefaultLabel
    );
    $resolvedHref = $href !== null ? \App\Support\Ui\RowAction::sanitizeUrl((string) $href) : null;
    $mergedAttributes = $attributes->except(['aria-label', 'href', 'type'])->class([$baseClasses]);

    if ($resolvedAriaLabel !== null) {
        $mergedAttributes = $mergedAttributes->merge(['aria-label' => $resolvedAriaLabel]);
    }

    if ($resolvedHref !== null && $attributes->get('target') === '_blank' && ! $attributes->has('rel')) {
        $mergedAttributes = $mergedAttributes->merge(['rel' => 'noopener']);
    }
@endphp

@if($resolvedHref !== null)
    <a href="{{ $resolvedHref }}" {{ $mergedAttributes }}>@if($icon)<x-ui.icon :name="$icon" size="xs" /><span>{{ $resolvedLabel }}</span>@else{{ $resolvedLabel }}@endif</a>
@else
    <button type="{{ $type }}" {{ $mergedAttributes }}>@if($icon)<x-ui.icon :name="$icon" size="xs" /><span>{{ $resolvedLabel }}</span>@else{{ $resolvedLabel }}@endif</button>
@endif
