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

<div class="ui-account-shell tw:grid tw:grid-cols-1 tw:items-start tw:gap-5 tw:shell:grid-cols-[14rem_minmax(0,1fr)] tw:shell:gap-6">
    {{-- Desktop rail --}}
    <nav
        aria-label="{{ __('navigation.account_nav.label') }}"
        class="ui-account-rail tw:hidden tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:shadow-ui-surface tw:shell:sticky tw:shell:block"
        style="top: calc(var(--topbar-height, 56px) + 1.25rem);"
    >
        <div class="tw:border-b tw:border-outline-variant tw:bg-surface tw:px-4 tw:py-3">
            <h2 class="tw:m-0 tw:text-ui-sm tw:font-semibold tw:text-on-surface">{{ __('navigation.account_nav.group') }}</h2>
        </div>
        <ul class="tw:m-0 tw:grid tw:list-none tw:gap-0.5 tw:p-2">
            @foreach($accountNavItems as $key => $item)
                <li>
                    <a
                        href="{{ route($item['route']) }}"
                        class="ui-focus-ring tw:flex tw:min-h-10 tw:items-center tw:gap-2 tw:rounded-ui-sm tw:px-2 tw:py-2 tw:text-ui-sm tw:no-underline tw:transition-colors {{ $active === $key ? 'tw:bg-primary-container tw:font-semibold tw:text-primary-container-foreground' : 'tw:text-on-surface tw:hover:bg-surface-container' }}"
                        @if($active === $key) aria-current="page" @endif
                    >
                        <x-ui.icon :name="$item['icon']" size="sm" class="tw:shrink-0" />
                        <span class="tw:truncate">{{ $item['label'] }}</span>
                    </a>
                    @if($key === 'customization' && $active === 'customization' && isset($sections))
                        <ul class="ui-account-section-nav tw:m-0 tw:mt-1 tw:grid tw:list-none tw:gap-0.5 tw:rounded-ui-sm tw:bg-surface-low tw:p-1" data-section-nav>
                            {{ $sections }}
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    </nav>

    {{-- Mobile / tablet navigation --}}
    <div class="tw:shell:hidden tw:grid tw:gap-1.5">
        <nav aria-label="{{ __('navigation.account_nav.label') }}" class="tw:overflow-x-auto tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:shadow-ui-surface">
            <div class="tw:flex tw:min-w-max tw:items-center tw:gap-1 tw:px-2">
                @foreach($accountNavItems as $key => $item)
                    <a
                        href="{{ route($item['route']) }}"
                        class="ui-focus-ring tw:flex tw:min-h-11 tw:shrink-0 tw:items-center tw:gap-1.5 tw:border-b-2 tw:px-3 tw:py-2.5 tw:text-ui-sm tw:font-semibold tw:no-underline tw:transition-colors {{ $active === $key ? 'tw:border-primary tw:text-primary' : 'tw:border-transparent tw:text-on-surface-variant tw:hover:border-outline tw:hover:text-on-surface' }}"
                        @if($active === $key) aria-current="page" @endif
                    >
                        <x-ui.icon :name="$item['icon']" size="xs" />
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </nav>
        @isset($mobileSections)
            <nav aria-label="{{ __('customization.sections.nav_label') }}" class="tw:overflow-x-auto tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-surface-low">
                <div class="tw:flex tw:min-w-max tw:items-center tw:gap-1 tw:px-2" data-section-nav-mobile>
                    {{ $mobileSections }}
                </div>
            </nav>
        @endisset
    </div>

    <div class="ui-account-content tw:min-w-0">
        {{ $slot }}
    </div>
</div>
