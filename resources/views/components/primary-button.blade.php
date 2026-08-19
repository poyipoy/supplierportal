<button {{ $attributes->merge(['type' => 'submit', 'class' => 'tw-inline-flex tw-min-h-[var(--ui-control-height-md)] tw-items-center tw-justify-center tw-rounded-ui-sm tw-border tw-border-transparent tw-bg-primary tw-px-4 tw-py-2 tw-text-ui-sm tw-font-semibold tw-text-primary-foreground tw-transition tw-duration-fast tw-ease-standard hover:tw-brightness-95 focus-visible:tw-outline-none focus-visible:tw-ring-2 focus-visible:tw-ring-primary focus-visible:tw-ring-offset-2 active:tw-brightness-90 disabled:tw-cursor-not-allowed disabled:tw-opacity-50']) }}>
    {{ $slot }}
</button>
