@props([
    'label',
    'tabs',
    'active' => null,
])

{{--
    Segmented pill tab triggers for Bootstrap tab panes (data-bs-toggle="tab"), styled like the
    price comparison and DRP paid bars. The selected look is keyed on aria-selected only. The plain
    "active" class carries no styles; Bootstrap's Tab plugin needs it on the selected trigger to know
    which pane to hide, and keeps both attributes in sync afterwards.
    $tabs: list of ['id', 'label', 'icon' => ?string, 'count' => ?int, 'tone' => 'error'|'warning'|null];
    each id names its pane (#id) and its trigger (#id-tab). The tone only shows on a non-zero count.
--}}
@php
    $active ??= $tabs[0]['id'] ?? null;
    $chipTones = [
        'error' => 'tw:bg-error-container tw:text-error-container-foreground',
        'warning' => 'tw:bg-warning-container tw:text-warning-container-foreground',
    ];
@endphp

<div {{ $attributes->class(['tw:flex tw:max-w-full']) }}>
    <div role="tablist" aria-label="{{ $label }}" class="tw:flex tw:w-full tw:flex-wrap tw:items-center tw:gap-1.5 tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface-container tw:p-1.5 tw:sm:inline-flex tw:sm:w-auto">
        @foreach($tabs as $tab)
            @php($selected = $tab['id'] === $active)
            <button
                type="button"
                role="tab"
                id="{{ $tab['id'] }}-tab"
                data-bs-toggle="tab"
                data-bs-target="#{{ $tab['id'] }}"
                aria-controls="{{ $tab['id'] }}"
                aria-selected="{{ $selected ? 'true' : 'false' }}"
                @unless($selected) tabindex="-1" @endunless
                class="tw:group ui-focus-ring ui-motion tw:inline-flex tw:min-h-[var(--ui-control-height-sm)] tw:pointer-coarse:min-h-[var(--ui-control-height-md)] tw:grow tw:items-center tw:justify-center tw:gap-2 tw:sm:grow-0 tw:rounded-ui-sm tw:border-0 tw:bg-transparent tw:px-3.5 tw:py-1.5 tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant tw:not-aria-selected:hover:bg-surface tw:not-aria-selected:hover:text-on-surface tw:aria-selected:bg-primary tw:aria-selected:text-primary-foreground{{ $selected ? ' active' : '' }}"
            >
                @if(! empty($tab['icon']))
                    <x-ui.icon :name="$tab['icon']" size="sm" />
                @endif
                <span>{{ $tab['label'] }}</span>
                @isset($tab['count'])
                    <span @class([
                        'tw:inline-flex tw:items-center tw:justify-center tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold ui-tabular-nums',
                        'tw:group-aria-selected:bg-primary-foreground tw:group-aria-selected:text-primary',
                        $tab['count'] > 0 ? ($chipTones[$tab['tone'] ?? ''] ?? 'tw:bg-surface tw:text-on-surface-variant') : 'tw:bg-surface tw:text-on-surface-variant',
                    ])>{{ $tab['count'] }}</span>
                @endisset
            </button>
        @endforeach
    </div>
</div>
