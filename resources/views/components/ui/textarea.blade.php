@props([
    'name',
    'id' => null,
    'label' => null,
    'value' => null,
    'helper' => null,
    'error' => null,
    'rows' => 4,
    'required' => false,
    'disabled' => false,
    'readonly' => false,
])

@php
    $resolvedId = $id ?: preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
    $validationKey = trim(preg_replace('/\[([^\]]*)\]/', '.$1', $name), '.');
    $message = $error ?: (isset($errors) && $errors->has($validationKey) ? $errors->first($validationKey) : null);
    $resolvedValue = old($validationKey, $value);
    $helperId = $helper ? $resolvedId . '-help' : null;
    $errorId = $message ? $resolvedId . '-error' : null;
    $describedBy = collect([$helperId, $errorId])->filter()->implode(' ');
@endphp

<div {{ $attributes->only('class')->class(['tw-grid tw-gap-1.5']) }}>
    @if($label)
        <label for="{{ $resolvedId }}" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
            {{ $label }}
            @if($required)<span class="tw-text-error" aria-hidden="true">*</span><span class="tw-sr-only"> required</span>@endif
        </label>
    @endif

    <textarea
        id="{{ $resolvedId }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        @required($required)
        @disabled($disabled)
        @readonly($readonly)
        @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
        @if($message) aria-invalid="true" @endif
        {{ $attributes->except('class')->class([
            'ui-motion tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-py-2 tw-text-ui-sm tw-text-on-surface tw-shadow-none placeholder:tw-text-on-surface-variant focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary disabled:tw-cursor-not-allowed disabled:tw-bg-surface-container disabled:tw-opacity-50',
            'tw-border-error' => $message,
            'tw-border-outline-strong' => !$message,
        ]) }}
    >{{ $resolvedValue }}</textarea>

    @if($helper)<p id="{{ $helperId }}" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ $helper }}</p>@endif
    @if($message)
        <p id="{{ $errorId }}" class="tw-m-0 tw-flex tw-items-start tw-gap-1.5 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">
            <x-ui.icon name="circle-alert" size="sm" class="tw-mt-0.5" /><span>{{ $message }}</span>
        </p>
    @endif
</div>
