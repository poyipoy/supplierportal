@props(['href', 'icon', 'active' => false, 'label' => null])

@php
    $displayLabel = $label ?: trim(strip_tags((string) $slot));
@endphp

<a
    href="{{ $href }}"
    title="{{ $displayLabel }}"
    aria-label="{{ $displayLabel }}"
    @if($active) aria-current="page" @endif
    {{ $attributes->class(['sidebar-link ui-motion', 'active' => $active]) }}
>
    <x-ui.icon :name="$icon" size="sm" class="flex-shrink-0" />
    <span class="sidebar-link-label">{{ $slot }}</span>
    @isset($trailing)<span class="sidebar-link-trailing">{{ $trailing }}</span>@endisset
</a>
