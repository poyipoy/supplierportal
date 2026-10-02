@props([
    'checked' => false,
    'id' => null,
    'name' => null,
    'value' => '1',
])

<input
    type="checkbox"
    role="switch"
    @if($id) id="{{ $id }}" @endif
    @if($name) name="{{ $name }}" @endif
    value="{{ $value }}"
    @checked((bool) $checked)
    {{ $attributes->merge([
        'class' => 'ui-switch'
    ]) }}
>
