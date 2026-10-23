@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'tw:min-h-[var(--ui-control-height-md)] tw:rounded-ui-sm tw:border tw:border-outline-strong tw:bg-surface tw:text-on-surface tw:shadow-none tw:placeholder:text-on-surface-variant tw:focus:border-primary tw:focus:ring-2 tw:focus:ring-primary tw:disabled:cursor-not-allowed tw:disabled:bg-surface-container tw:disabled:opacity-50']) }}>
