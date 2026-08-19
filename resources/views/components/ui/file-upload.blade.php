@props([
    'name',
    'id' => null,
    'label' => null,
    'helper' => null,
    'error' => null,
    'required' => false,
    'multiple' => false,
    'disabled' => false,
])

@php
    $resolvedId = $id ?: preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
    $validationKey = trim(preg_replace('/\[([^\]]*)\]/', '.$1', $name), '.');
    $message = $error ?: (isset($errors) && $errors->has($validationKey) ? $errors->first($validationKey) : null);
    $helperId = $helper ? $resolvedId . '-help' : null;
    $errorId = $message ? $resolvedId . '-error' : null;
@endphp

<div {{ $attributes->only('class')->class(['tw-grid tw-gap-1.5']) }}>
    @if($label)
        <label for="{{ $resolvedId }}" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
            {{ $label }}
            @if($required)<span class="tw-text-error" aria-hidden="true">*</span><span class="tw-sr-only"> wajib</span>@endif
        </label>
    @endif
    <input
        id="{{ $resolvedId }}"
        name="{{ $name }}"
        type="file"
        @required($required)
        @disabled($disabled)
        @if($multiple) multiple @endif
        @if($helper || $message) aria-describedby="{{ collect([$helperId, $errorId])->filter()->implode(' ') }}" @endif
        @if($message) aria-invalid="true" @endif
        {{ $attributes->except('class')->class([
            'ui-motion tw-min-h-[var(--ui-control-height-md)] tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-text-ui-sm tw-text-on-surface file:tw-me-3 file:tw-min-h-[var(--ui-control-height-sm)] file:tw-border-0 file:tw-border-e file:tw-border-outline-variant file:tw-bg-surface-container file:tw-px-3 file:tw-font-semibold file:tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary disabled:tw-cursor-not-allowed disabled:tw-opacity-50',
            'tw-border-error' => $message,
            'tw-border-outline-strong' => !$message,
        ]) }}
    >
    @if($helper)<p id="{{ $helperId }}" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ $helper }}</p>@endif
    @if($message)<p id="{{ $errorId }}" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@endif
</div>
