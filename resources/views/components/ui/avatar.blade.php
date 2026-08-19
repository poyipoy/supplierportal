@props(['name', 'src' => null, 'size' => 'md'])

@php
    $sizes = ['sm' => 'tw-h-8 tw-w-8 tw-text-ui-xs', 'md' => 'tw-h-10 tw-w-10 tw-text-ui-sm', 'lg' => 'tw-h-12 tw-w-12 tw-text-ui-base'];
    $initials = collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
@endphp

<span {{ $attributes->class(['tw-inline-flex tw-shrink-0 tw-items-center tw-justify-center tw-overflow-hidden tw-rounded-ui-full tw-bg-secondary-container tw-font-semibold tw-text-secondary-container-foreground', $sizes[$size] ?? $sizes['md']]) }}>
    @if($src)
        <img src="{{ $src }}" alt="" class="tw-h-full tw-w-full tw-object-cover">
    @else
        <span aria-hidden="true">{{ $initials ?: '?' }}</span>
    @endif
    <span class="tw-sr-only">Avatar {{ $name }}</span>
</span>
