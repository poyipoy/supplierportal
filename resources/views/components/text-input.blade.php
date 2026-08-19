@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'tw-min-h-[var(--ui-control-height-md)] tw-rounded-ui-sm tw-border tw-border-outline-strong tw-bg-surface tw-text-on-surface tw-shadow-none placeholder:tw-text-on-surface-variant focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary disabled:tw-cursor-not-allowed disabled:tw-bg-surface-container disabled:tw-opacity-50']) }}>
