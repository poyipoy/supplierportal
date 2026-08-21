@props([
    'name',
    'size' => 'md',
])

@php
    $sizes = [
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

{{ svg($component, $iconClass, $iconAttributes) }}
