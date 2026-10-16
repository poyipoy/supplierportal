@props(['status'])

@if ($status)
    <div role="status" {{ $attributes->merge(['class' => 'tw-text-ui-sm tw-font-medium tw-text-success']) }}>
        {{ $status }}
    </div>
@endif
