@props(['value'])

<label {{ $attributes->merge(['class' => 'tw:block tw:text-ui-sm tw:font-semibold tw:text-on-surface']) }}>
    {{ $value ?? $slot }}
</label>
