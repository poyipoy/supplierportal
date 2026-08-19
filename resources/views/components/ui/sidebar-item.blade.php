@props(['href', 'icon', 'active' => false, 'label' => null])

<a
    href="{{ $href }}"
    title="{{ $label ?: trim($slot) }}"
    @if($active) aria-current="page" @endif
    {{ $attributes->class(['sidebar-link ui-motion', 'active' => $active]) }}
>
    <i class="bi {{ $icon }}" aria-hidden="true"></i>
    <span class="sidebar-link-label">{{ $slot }}</span>
    @isset($trailing)<span class="sidebar-link-trailing">{{ $trailing }}</span>@endisset
</a>
