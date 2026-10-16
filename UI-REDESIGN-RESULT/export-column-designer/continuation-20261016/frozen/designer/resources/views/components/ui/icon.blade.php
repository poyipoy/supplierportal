@props([
    'name',
    'size' => 'md',
])

@php
    $sizes = [
        'xs' => 14,
        'sm' => 16,
        'md' => 18,
        'lg' => 20,
    ];
    $aliases = [
        'arrow-counterclockwise' => 'rotate-ccw',
        'arrow-repeat' => 'refresh-cw',
        'arrow-right-short' => 'arrow-right',
        'arrows' => 'move',
        'bar-chart-line' => 'chart-no-axes-combined',
        'bell-fill' => 'bell',
        'box-arrow-in-right' => 'log-in',
        'box-arrow-right' => 'log-out',
        'box-arrow-up-right' => 'external-link',
        'box-seam' => 'package',
        'calendar3' => 'calendar-days',
        'calendar-event' => 'calendar-days',
        'cash-stack' => 'banknote',
        'chat-dots' => 'message-circle-more',
        'chat-left-text' => 'message-square-text',
        'chat-square-dots' => 'message-square-more',
        'chat-square-text' => 'message-square-text',
        'chat-text' => 'message-square-text',
        'check2' => 'check',
        'check2-all' => 'check-check',
        'check2-circle' => 'circle-check',
        'check-circle-fill' => 'circle-check',
        'circle-fill' => 'circle',
        'clipboard2-check' => 'clipboard-check',
        'clipboard-data' => 'clipboard-list',
        'currency-exchange' => 'badge-dollar-sign',
        'diagram-3' => 'network',
        'envelope' => 'mail',
        'envelope-arrow-up' => 'send',
        'exclamation-circle' => 'circle-alert',
        'exclamation-octagon' => 'octagon-alert',
        'exclamation-triangle' => 'triangle-alert',
        'exclamation-triangle-fill' => 'triangle-alert',
        'eye-slash' => 'eye-off',
        'file-earmark' => 'file',
        'file-earmark-arrow-down' => 'file-down',
        'file-earmark-bar-graph' => 'file-chart-column',
        'file-earmark-check' => 'file-check',
        'file-earmark-excel' => 'file-spreadsheet',
        'file-earmark-pdf' => 'file-text',
        'file-earmark-spreadsheet' => 'file-spreadsheet',
        'file-earmark-text' => 'file-text',
        'folder2' => 'folder',
        'folder2-open' => 'folder-open',
        'geo-alt' => 'map-pin',
        'graph-up' => 'chart-no-axes-combined',
        'graph-up-arrow' => 'trending-up',
        'hourglass-split' => 'hourglass',
        'info-circle' => 'info',
        'info-circle-fill' => 'info',
        'lightning-charge' => 'zap',
        'list-ul' => 'list',
        'pencil-square' => 'square-pen',
        'people' => 'users',
        'person' => 'user',
        'person-check' => 'user-check',
        'person-circle' => 'circle-user-round',
        'person-fill' => 'user',
        'person-gear' => 'user-cog',
        'plus-circle' => 'circle-plus',
        'plus-lg' => 'plus',
        'qr-code-scan' => 'scan-qr-code',
        'question-circle' => 'circle-help',
        'question-circle-fill' => 'circle-help',
        'send-check' => 'send',
        'send-fill' => 'send',
        'shield-exclamation' => 'shield-alert',
        'shield-lock-fill' => 'shield-lock',
        'speedometer2' => 'gauge',
        'star-fill' => 'star',
        'telephone' => 'phone',
        'trash3' => 'trash-2',
        'x-circle-fill' => 'circle-x',
        'x-lg' => 'x',
    ];
    $rawTokens = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $rawName = array_shift($rawTokens) ?: 'circle-help';
    $legacyPrefix = 'bi' . '-';
    if (str_starts_with($rawName, $legacyPrefix)) {
        $rawName = substr($rawName, strlen($legacyPrefix));
    }
    $pixels = $sizes[$size] ?? $sizes['md'];
    $candidateName = $aliases[$rawName] ?? $rawName;
    $resolvedName = preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $candidateName)
        && is_file(base_path('vendor/technikermathe/blade-lucide-icons/resources/svg/' . $candidateName . '.svg'))
        ? $candidateName
        : 'circle-help';
    $component = 'lucide-' . $resolvedName;
    $isDecorative = ! $attributes->has('aria-label') && ! $attributes->has('aria-labelledby');
    $iconClass = trim(implode(' ', array_filter([
        'ui-icon',
        implode(' ', $rawTokens),
        (string) $attributes->get('class', ''),
    ])));
    $iconAttributes = $attributes->except('class')->getAttributes();
    $iconAttributes['width'] = $pixels;
    $iconAttributes['height'] = $pixels;
    $iconAttributes['stroke-width'] = '1.75';
    $iconAttributes['focusable'] = 'false';
    if ($isDecorative) {
        $iconAttributes['aria-hidden'] = 'true';
    }
@endphp

@if($candidateName === 'whatsapp')
    <svg viewBox="0 0 24 24" width="{{ $pixels }}" height="{{ $pixels }}" fill="none" xmlns="http://www.w3.org/2000/svg" {{ $attributes->merge(['class' => $iconClass]) }} {!! $isDecorative ? 'aria-hidden="true"' : '' !!} focusable="false">
        <circle cx="12" cy="12" r="12" fill="#25D366"/>
        <path fill-rule="evenodd" clip-rule="evenodd" d="M18.06 6.01A8.47 8.47 0 0 0 12.06 3.5C7.4 3.5 3.6 7.3 3.6 11.96c0 1.49.39 2.94 1.13 4.22L3.5 20.5l4.44-1.16a8.44 8.44 0 0 0 4.12 1.06h.01c4.66 0 8.46-3.8 8.46-8.46 0-2.26-.88-4.39-2.47-5.93zm-6 13.06h-.01a7.03 7.03 0 0 1-3.58-.98l-.26-.15-2.66.7.71-2.59-.17-.27a7.03 7.03 0 0 1-1.09-3.77c0-3.88 3.16-7.04 7.04-7.04 1.88 0 3.65.73 4.98 2.06a7 7 0 0 1 2.06 4.98c0 3.88-3.16 7.04-7.04 7.04zm3.86-5.27c-.21-.11-1.26-.62-1.45-.69-.2-.07-.34-.11-.48.11-.14.21-.55.69-.67.83-.12.14-.25.16-.46.05-.21-.11-.89-.33-1.7-1.05-.63-.56-1.06-1.25-1.18-1.46-.12-.21-.01-.33.09-.43.1-.1.21-.25.32-.37.11-.12.14-.21.21-.35.07-.14.04-.27-.02-.37-.05-.11-.48-1.15-.65-1.57-.17-.42-.35-.36-.48-.37h-.41c-.14 0-.37.05-.56.27-.19.21-.74.72-.74 1.77s.76 2.05.86 2.19c.11.14 1.49 2.28 3.62 3.2.51.22.9.35 1.21.45.51.16.97.14 1.33.09.41-.06 1.26-.51 1.44-1.01.18-.5.18-.92.12-1.01-.05-.09-.2-.14-.42-.25z" fill="#FFFFFF"/>
    </svg>
@else
    {{ svg($component, $iconClass, $iconAttributes) }}
@endif
