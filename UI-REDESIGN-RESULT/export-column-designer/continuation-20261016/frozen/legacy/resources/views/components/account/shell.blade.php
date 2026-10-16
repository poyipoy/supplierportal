@props([
    'active' => null,
])

@php
    $accountNavItems = [
        'profile' => ['route' => 'profile.edit', 'label' => __('navigation.profile'), 'icon' => 'user-cog'],
        'security' => ['route' => 'profile.security', 'label' => __('navigation.security'), 'icon' => 'shield-check'],
        'notifications' => ['route' => 'profile.notifications', 'label' => __('common.notification.title'), 'icon' => 'bell'],
        'customization' => ['route' => 'profile.customization', 'label' => __('navigation.customization'), 'icon' => 'sliders-horizontal'],
    ];
@endphp

<div class="ui-account-shell tw-grid tw-grid-cols-1 tw-items-start tw-gap-5 shell:tw-grid-cols-[14rem_minmax(0,1fr)] shell:tw-gap-6">
    {{-- Desktop rail --}}
    <nav
        aria-label="{{ __('navigation.account_nav.label') }}"
        class="ui-account-rail tw-hidden tw-border tw-border-outline tw-bg-surface-container shell:tw-sticky shell:tw-top-5 shell:tw-block"
    >
        <div class="tw-border-b tw-border-outline-variant tw-bg-surface-low tw-px-4 tw-py-3">
            <h2 class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">{{ __('navigation.account_nav.group') }}</h2>
        </div>
        <ul class="tw-m-0 tw-list-none tw-divide-y tw-divide-outline-variant tw-p-0">
            @foreach($accountNavItems as $key => $item)
                <li>
                    <a
                        href="{{ route($item['route']) }}"
                        class="tw-flex tw-min-h-10 tw-items-center tw-gap-2.5 tw-border-l-2 tw-px-4 tw-py-2.5 tw-text-ui-sm tw-no-underline tw-transition-colors {{ $active === $key ? 'tw-border-l-primary tw-bg-primary/5 tw-font-semibold tw-text-primary' : 'tw-border-l-transparent tw-text-on-surface hover:tw-bg-surface-low' }}"
                        @if($active === $key) aria-current="page" @endif
                    >
                        <x-ui.icon :name="$item['icon']" size="sm" class="tw-shrink-0" />
                        <span class="tw-truncate">{{ $item['label'] }}</span>
                    </a>
                    @if($key === 'customization' && $active === 'customization' && isset($sections))
                        <ul class="ui-account-section-nav tw-m-0 tw-list-none tw-bg-surface tw-p-0" data-section-nav>
                            {{ $sections }}
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    </nav>

    {{-- Mobile / tablet navigation --}}
    <div class="shell:tw-hidden tw-grid tw-gap-2">
        <nav aria-label="{{ __('navigation.account_nav.label') }}" class="tw-overflow-x-auto tw-border-b tw-border-outline-variant">
            <div class="tw-flex tw-min-w-max tw-items-center tw-gap-1">
                @foreach($accountNavItems as $key => $item)
                    <a
                        href="{{ route($item['route']) }}"
                        class="tw-flex tw-shrink-0 tw-items-center tw-gap-1.5 tw-border-b-2 tw-px-3 tw-py-2.5 tw-text-ui-sm tw-font-semibold tw-no-underline tw-transition-colors {{ $active === $key ? 'tw-border-primary tw-text-primary' : 'tw-border-transparent tw-text-on-surface-variant hover:tw-border-outline hover:tw-text-on-surface' }}"
                        @if($active === $key) aria-current="page" @endif
                    >
                        <x-ui.icon :name="$item['icon']" size="xs" />
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </nav>
        @isset($mobileSections)
            <nav aria-label="{{ __('customization.sections.nav_label') }}" class="tw-overflow-x-auto tw-border-b tw-border-outline-variant">
                <div class="tw-flex tw-min-w-max tw-items-center tw-gap-1" data-section-nav-mobile>
                    {{ $mobileSections }}
                </div>
            </nav>
        @endisset
    </div>

    <div class="ui-account-content tw-min-w-0">
        {{ $slot }}
    </div>
</div>
