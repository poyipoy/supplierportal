@props(['href', 'icon', 'active' => false, 'label' => null, 'disabled' => false, 'disabledHint' => null])

@php
    $visibleLabel = html_entity_decode(trim(strip_tags((string) $slot)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $displayLabel = $label ?: $visibleLabel;
    $typingSteps = max(6, min(24, mb_strlen($visibleLabel)));
@endphp

@if($disabled)
    {{-- Menu tetap terlihat tetapi nonaktif; alasan dibacakan screen reader dan tampil sebagai tooltip. --}}
    <span
        role="link"
        aria-disabled="true"
        tabindex="0"
        aria-label="{{ $disabledHint ? $displayLabel.'. '.$disabledHint : $displayLabel }}"
        title="{{ $disabledHint ?: $displayLabel }}"
        data-sidebar-item
        data-sidebar-disabled
        data-sidebar-tooltip
        data-bs-title="{{ $disabledHint ? $displayLabel.' — '.$disabledHint : $displayLabel }}"
        {{ $attributes->class(['sidebar-link sidebar-link--disabled ui-motion ui-focus-ring']) }}
    >
        <span class="sidebar-link-icon" aria-hidden="true">
            <x-ui.icon :name="$icon" size="sm" class="tw-shrink-0" />
        </span>
        <span class="sidebar-link-label sidebar-type-text" style="--sidebar-type-steps: {{ $typingSteps }};">{{ $slot }}</span>
        <span class="sidebar-link-trailing" aria-hidden="true"><x-ui.icon name="lock" size="sm" /></span>
    </span>
@else
<a
    href="{{ $href }}"
    aria-label="{{ $displayLabel }}"
    data-sidebar-item
    data-sidebar-tooltip
    data-bs-title="{{ $displayLabel }}"
    @if($active) aria-current="page" @endif
    {{ $attributes->class(['sidebar-link ui-motion ui-focus-ring', 'active' => $active]) }}
>
    <span class="sidebar-link-icon" aria-hidden="true">
        <x-ui.icon :name="$icon" size="sm" class="tw-shrink-0" />
    </span>
    <span class="sidebar-link-label sidebar-type-text" style="--sidebar-type-steps: {{ $typingSteps }};">{{ $slot }}</span>
    @isset($trailing)<span class="sidebar-link-trailing">{{ $trailing }}</span>@endisset
</a>
@endif
