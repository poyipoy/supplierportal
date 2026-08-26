@props([
    'title' => null,
    'description' => null,
    'variant' => 'default',
    'padding' => 'md',
])

@php
    $variants = [
        'default' => 'tw-border-outline tw-bg-surface tw-shadow-none',
        'flat' => 'tw-border-outline tw-bg-surface tw-shadow-none',
        'tonal' => 'tw-border-outline tw-bg-surface-container tw-shadow-none',
        'elevated' => 'tw-border-outline tw-bg-surface tw-shadow-ui-2',
    ];
    $paddings = ['none' => '', 'sm' => 'tw-p-3.5', 'md' => 'tw-p-4 shell:tw-p-5'];
@endphp

<section {{ $attributes->class(['tw-rounded-ui-md tw-border tw-text-on-surface', $variants[$variant] ?? $variants['default']]) }}>
    @if($title || $description || isset($header) || isset($actions))
        <header class="tw-flex tw-flex-col tw-gap-2.5 tw-border-b tw-border-outline-variant tw-p-3.5 shell:tw-flex-row shell:tw-items-center shell:tw-justify-between shell:tw-px-4">
            <div class="tw-min-w-0">
                @isset($header)
                    {{ $header }}
                @else
                    @if($title)<h2 class="tw-m-0 tw-text-sm tw-font-bold tw-text-on-surface">{{ $title }}</h2>@endif
                    @if($description)<p class="tw-m-0 tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">{{ $description }}</p>@endif
                @endisset
            </div>
            @isset($actions)<div class="tw-flex tw-shrink-0 tw-flex-wrap tw-items-center tw-gap-2">{{ $actions }}</div>@endisset
        </header>
    @endif
    <div class="{{ $paddings[$padding] ?? $paddings['md'] }}">{{ $slot }}</div>
    @isset($footer)<footer class="tw-border-t tw-border-outline-variant tw-p-3.5 shell:tw-px-4 tw-bg-surface-low">{{ $footer }}</footer>@endisset
</section>
