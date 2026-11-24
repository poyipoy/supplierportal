@props([
    'orientation' => 'vertical',
    'controls' => null,
    'label' => null,
    'min' => 1,
    'max' => 99,
    'value' => 50,
    'valuetext' => null,
    'lineClass' => '',
    'gripClass' => '',
])

<div
    {{ $attributes->merge([
        'class' => 'ui-splitter ui-focus-ring',
        'role' => 'separator',
        'aria-orientation' => $orientation,
        'tabindex' => '0',
        'aria-controls' => $controls,
        'aria-valuemin' => $min,
        'aria-valuemax' => $max,
        'aria-valuenow' => $value,
        'aria-valuetext' => $valuetext,
        'aria-label' => $label,
        'title' => $label,
    ]) }}
>
    <span class="{{ trim('ui-splitter__line '.$lineClass) }}" aria-hidden="true">
        <span class="{{ trim('ui-splitter__grip '.$gripClass) }}"></span>
    </span>
</div>

