@props([
    'label',
    'value' => null,
    'fullWidth' => false,
])

<div {{ $attributes->class(['tw:flex tw:flex-col', 'tw:col-span-2' => $fullWidth]) }}>
    <span class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">{{ $label }}</span>
    <span class="tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mt-0.5">
        {{ $value ?? $slot }}
    </span>
</div>

