@props([
    'action' => null,
    'method' => 'GET',
    'mode' => 'submit',
    'variant' => 'default',
    'sticky' => false,
    'serverTabs' => false,
    'id' => null,
    'ariaLabel' => null,
    'advancedId' => null,
    'advancedCount' => 0,
    'advancedOpen' => false,
    'advancedLabel' => null,
    'advancedTitle' => null,
    'advancedDescription' => null,
    'hasActiveFilters' => false,
    'resetUrl' => null,
    'resetId' => null,
    'resetLabel' => null,
    'resetDisabled' => false,
    'resetAttrs' => [],
    'submitLabel' => null,
    'submitDisabled' => false,
    'submitAttrs' => [],
    'showResetInLive' => true,
])

@php
    $isLive = $mode === 'live';
    $isEmbedded = $variant === 'embedded';
    $resolvedAriaLabel = $ariaLabel ?: __('common.filters.controls');
    $resolvedAdvancedId = $advancedId ?: (($id ?: 'filter-bar').'-advanced');
    $resolvedAdvancedCount = max(0, (int) $advancedCount);
    $isAdvancedOpen = (bool) $advancedOpen;
    $resolvedAdvancedLabel = $advancedLabel ?: __('common.filters.more');
    $resolvedSubmitLabel = $submitLabel ?: __('common.filters.apply');
    $resolvedResetLabel = $resetLabel ?: __('common.filters.reset');
    $resetAttrBag = new \Illuminate\View\ComponentAttributeBag(is_array($resetAttrs) ? $resetAttrs : []);
    $submitAttrBag = new \Illuminate\View\ComponentAttributeBag(is_array($submitAttrs) ? $submitAttrs : []);
@endphp

@if($isLive)
<div
    @if($id) id="{{ $id }}" @endif
    role="search"
    aria-label="{{ $resolvedAriaLabel }}"
    data-adasi-filter-bar
    data-filter-bar-mode="live"
    {{ $attributes->class(['ui-filter-bar', 'ui-filter-bar--embedded' => $isEmbedded, 'ui-filter-bar--sticky' => $sticky && ! $isEmbedded]) }}
>
@else
<form
    method="{{ strtoupper($method) }}"
    @if($action) action="{{ $action }}" @endif
    @if($id) id="{{ $id }}" @endif
    role="search"
    aria-label="{{ $resolvedAriaLabel }}"
    @if($serverTabs) data-server-tabs-form @endif
    data-adasi-filter-bar
    data-filter-bar-mode="submit"
    {{ $attributes->class(['ui-filter-bar', 'ui-filter-bar--embedded' => $isEmbedded, 'ui-filter-bar--sticky' => $sticky && ! $isEmbedded]) }}
>
@endif
    @isset($hidden)
        {{ $hidden }}
    @endisset

    <div class="ui-filter-bar__surface">
        <div class="ui-filter-bar__primary">
            @isset($search)
                <div class="ui-filter-bar__search">
                    {{ $search }}
                </div>
            @endisset

            @if(isset($filters) || trim((string) $slot) !== '' || isset($advanced))
                <div class="ui-filter-bar__controls">
                    @isset($filters)
                        {{ $filters }}
                    @endisset
                    {{ $slot }}
                    @isset($advanced)
                        <x-ui.button
                            variant="outline"
                            size="sm"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#{{ $resolvedAdvancedId }}"
                            aria-expanded="{{ $isAdvancedOpen ? 'true' : 'false' }}"
                            aria-controls="{{ $resolvedAdvancedId }}"
                            data-filter-bar-advanced-toggle
                            class="ui-filter-bar__advanced-toggle"
                        >
                            <x-ui.icon name="sliders-horizontal" size="sm" />
                            <span>{{ $resolvedAdvancedLabel }}</span>
                            <span
                                data-filter-bar-advanced-count
                                class="ui-filter-bar__badge ui-tabular-nums {{ $resolvedAdvancedCount > 0 ? '' : 'tw:hidden' }}"
                            >{{ $resolvedAdvancedCount }}</span>
                        </x-ui.button>
                    @endisset
                </div>
            @endif

            <div class="ui-filter-bar__actions">
                @if(! $isLive)
                    <x-ui.button
                        type="submit"
                        variant="primary"
                        size="sm"
                        :disabled="$submitDisabled"
                        :attributes="$submitAttrBag"
                    >
                        <x-ui.icon name="filter" size="sm" />
                        <span>{{ $resolvedSubmitLabel }}</span>
                    </x-ui.button>

                    @if($hasActiveFilters && $resetUrl)
                        <x-ui.button
                            :href="$resetUrl"
                            :id="$resetId"
                            variant="ghost"
                            size="sm"
                            :disabled="$resetDisabled"
                            :data-server-tabs-reset="$serverTabs ? '' : null"
                            data-filter-bar-reset
                            class="ui-filter-bar__reset"
                            :attributes="$resetAttrBag"
                        >
                            <x-ui.icon name="rotate-ccw" size="sm" />
                            <span>{{ $resolvedResetLabel }}</span>
                        </x-ui.button>
                    @endif
                @elseif($showResetInLive)
                    <x-ui.button
                        type="button"
                        :id="$resetId"
                        variant="ghost"
                        size="sm"
                        :disabled="$resetDisabled"
                        data-filter-bar-reset
                        class="ui-filter-bar__reset {{ $hasActiveFilters ? 'ui-filter-bar__reset--active' : '' }}"
                        :attributes="$resetAttrBag"
                    >
                        <x-ui.icon name="rotate-ccw" size="sm" />
                        <span>{{ $resolvedResetLabel }}</span>
                    </x-ui.button>
                @endif

                @isset($actions)
                    {{ $actions }}
                @endisset
            </div>
        </div>

        @isset($advanced)
            <div
                class="collapse ui-filter-bar__tray {{ $isAdvancedOpen ? 'show' : '' }}"
                id="{{ $resolvedAdvancedId }}"
                data-filter-bar-tray
            >
                <div class="ui-filter-bar__tray-inner">
                    @if($advancedTitle)
                        <div class="ui-filter-bar__tray-header">
                            <div>
                                <h4 class="tw:m-0 tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">{{ $advancedTitle }}</h4>
                                @if($advancedDescription)
                                    <p class="tw:m-0 tw:mt-0.5 tw:text-ui-xs tw:text-on-surface-variant">{{ $advancedDescription }}</p>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="ui-filter-bar__tray-grid">
                        {{ $advanced }}
                    </div>

                    @isset($advancedFooter)
                        <div class="ui-filter-bar__tray-footer">
                            {{ $advancedFooter }}
                        </div>
                    @endisset
                </div>
            </div>
        @endisset
    </div>

    @isset($chips)
        <div class="ui-filter-bar__chips">
            {{ $chips }}
        </div>
    @endisset
@if($isLive)
</div>
@else
</form>
@endif
