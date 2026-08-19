@props(['messages'])

@if ($messages)
    <ul role="alert" {{ $attributes->merge(['class' => 'tw-space-y-1 tw-text-ui-sm tw-text-error']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
