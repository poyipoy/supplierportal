@props(['kind' => 'list'])

{{-- Neutral outline of what a dashboard panel contains. It stands in for content that is not rendered
     (a hidden panel in the layout editor, a tile in the Customization wireframe), so it carries no data. --}}
@php
    $bar = 'tw:block tw:rounded-ui-xs tw:bg-outline-variant';
    $chartBars = ['tw:h-2/5', 'tw:h-3/4', 'tw:h-3/5', 'tw:h-full', 'tw:h-4/5', 'tw:h-1/2'];
@endphp

<div {{ $attributes->class(['tw:w-full']) }} data-widget-skeleton="{{ $kind }}" aria-hidden="true">
    @switch($kind)
        @case('metrics')
            <div class="tw:grid tw:grid-cols-4 tw:gap-1.5">
                @for($index = 0; $index < 4; $index++)
                    <span class="{{ $bar }} tw:h-7"></span>
                @endfor
            </div>
            @break
        @case('chart')
            <div class="tw:flex tw:h-14 tw:items-end tw:gap-1.5">
                @foreach($chartBars as $height)
                    <span class="{{ $bar }} tw:flex-1 {{ $height }}"></span>
                @endforeach
            </div>
            @break
        @case('table')
            <div class="tw:grid tw:gap-1.5">
                @for($index = 0; $index < 4; $index++)
                    <span class="{{ $bar }} tw:h-2 {{ $index === 0 ? 'tw:w-full' : 'tw:w-11/12' }}"></span>
                @endfor
            </div>
            @break
        @case('summary')
            <div class="tw:grid tw:gap-1.5">
                @for($index = 0; $index < 3; $index++)
                    <span class="tw:flex tw:gap-2"><span class="{{ $bar }} tw:h-2 tw:w-1/4"></span><span class="{{ $bar }} tw:h-2 tw:flex-1"></span></span>
                @endfor
            </div>
            @break
        @default
            <div class="tw:grid tw:gap-1.5">
                @foreach(['tw:w-full', 'tw:w-5/6', 'tw:w-2/3'] as $width)
                    <span class="{{ $bar }} tw:h-2 {{ $width }}"></span>
                @endforeach
            </div>
    @endswitch
</div>
