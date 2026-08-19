@props(['label' => 'Navigasi bagian'])

<nav aria-label="{{ $label }}" {{ $attributes->class(['tw-overflow-x-auto tw-border-b tw-border-outline-variant']) }}>
    <div class="tw-flex tw-min-w-max tw-items-center tw-gap-1" role="tablist">{{ $slot }}</div>
</nav>
