@extends('layouts.app')

@section('title', __('notifications.preferences.title'))
@section('page-title', __('common.notification.title'))

@section('content')
<x-account.shell active="notifications">
<div class="tw-grid tw-grid-cols-1 tw-gap-5">
    <x-ui.page-header
        :title="__('common.notification.title')"
        :description="__('notifications.preferences.description')"
    />

    @if($errors->any())
        <x-ui.alert tone="error" :title="__('notifications.preferences.review')">
            <ul class="tw-m-0 tw-list-disc tw-pl-5">
                @foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </x-ui.alert>
    @endif

    @if($events === [])
        <section class="tw-border tw-border-outline tw-bg-surface tw-p-5" aria-labelledby="notification-options-title">
            <h2 id="notification-options-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('notifications.preferences.settings') }}</h2>
            <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">{{ __('notifications.preferences.no_settings') }}</p>
        </section>
    @else
        @php
            $groupedCategories = collect($events)->groupBy('category', preserveKeys: true);
            $totalEventsCount = count($events);
            $showTools = $totalEventsCount > 8;
            $userRole = auth()->user()?->role;

            $hasImportScope = false;
            $hasLocalScope = false;
            foreach ($events as $event) {
                $scopes = $event['supplier_scopes'] ?? [];
                if (in_array('import', $scopes, true)) $hasImportScope = true;
                if (in_array('local', $scopes, true)) $hasLocalScope = true;
            }
            $showScopeTabs = (bool) (auth()->user()?->isSupplier() && $hasImportScope && $hasLocalScope);
            $startScope = $showScopeTabs
                ? ((\App\Support\PortalContext::current() === 'local') ? 'local' : 'import')
                : 'all';

            // One entry per event, keyed like the form fields. The browser only ever
            // reads this; every submitted value still comes from the inputs below.
            $state = [];
            $meta = [];
            $categoryKeys = [];
            $categoryIds = [];
            $initialVisible = [];
            $categoryIndex = 0;
            foreach ($groupedCategories as $category => $categoryEvents) {
                $categoryId = 'cat-'.$categoryIndex++;
                $categoryIds[$category] = $categoryId;
                foreach ($categoryEvents as $key => $event) {
                    $scope = ! empty($event['supplier_scopes'])
                        ? (in_array('import', $event['supplier_scopes'], true) ? 'import' : 'local')
                        : 'general';
                    $isActionRequired = (($event['priority'] ?? null) === 'action_required')
                        && (! isset($event['priority_roles']) || in_array($userRole, $event['priority_roles'], true));

                    $state[$key] = [
                        'inbox' => (bool) old('notification_preferences.'.$key, $effectivePreferences[$key]),
                        'popup' => (string) old('notification_delivery.'.$key, $deliveryPreferences[$key] ?? 'normal') !== 'silent',
                    ];
                    $meta[$key] = [
                        'category' => $categoryId,
                        'scope' => $scope,
                        'action' => $isActionRequired,
                        'search' => mb_strtolower(__($event['label']).' '.__($event['description'])),
                    ];
                    $categoryKeys[$categoryId][] = $key;
                    $initialVisible[$key] = $startScope === 'all' || $scope === $startScope || $scope === 'general';
                }
            }

            $onCount = collect($state)->where('inbox', true)->count();
            $inboxOnlyCount = collect($state)->filter(fn (array $row): bool => $row['inbox'] && ! $row['popup'])->count();
            $formConfig = [
                'scopeTab' => $startScope,
                'state' => $state,
                'meta' => $meta,
                'categories' => $categoryKeys,
                'copy' => [
                    'summary' => __('notifications.preferences.summary'),
                    'categorySummary' => __('notifications.preferences.category_summary'),
                    'unsaved' => __('notifications.preferences.unsaved'),
                    'shown' => __('notifications.preferences.shown'),
                ],
            ];

            $filterOptions = [
                'all' => __('notifications.preferences.all'),
                'off' => __('notifications.preferences.off'),
                'inbox' => __('notifications.preferences.inbox_only'),
            ];
            $filterCounts = ['all' => 0, 'off' => 0, 'inbox' => 0];
            foreach ($state as $key => $row) {
                if (! $initialVisible[$key]) {
                    continue;
                }
                $filterCounts['all']++;
                if (! $row['inbox']) {
                    $filterCounts['off']++;
                } elseif (! $row['popup']) {
                    $filterCounts['inbox']++;
                }
            }
            $channels = [
                'inbox' => __('notifications.preferences.channel_inbox'),
                'popup' => __('notifications.preferences.channel_popup'),
            ];
            $rowGrid = 'md:tw-grid-cols-[minmax(0,1fr)_6.5rem_6.5rem]';
        @endphp
        <form
            method="POST"
            action="{{ route('profile.notifications.update') }}"
            class="tw-grid tw-grid-cols-1 tw-gap-4"
            x-data="notificationPreferencesForm(@js($formConfig))"
        >
            @csrf
            @method('PATCH')

            <dl class="tw-m-0 tw-grid tw-gap-x-6 tw-gap-y-1 tw-text-ui-xs tw-text-on-surface-variant sm:tw-grid-cols-2">
                <div>
                    <dt class="tw-inline tw-font-semibold tw-text-on-surface">{{ $channels['inbox'] }}</dt>
                    <dd class="tw-m-0 tw-inline">{{ __('notifications.preferences.legend_inbox') }}</dd>
                </div>
                <div>
                    <dt class="tw-inline tw-font-semibold tw-text-on-surface">{{ $channels['popup'] }}</dt>
                    <dd class="tw-m-0 tw-inline">{{ __('notifications.preferences.legend_popup') }}</dd>
                </div>
            </dl>

            @if($showScopeTabs)
                <div class="tw-grid tw-gap-2">
                    <x-ui.tabs :label="__('common.scope_filter')">
                        @foreach(['import' => 'common.review.scope_import', 'local' => 'common.review.scope_local', 'general' => 'common.review.scope_general'] as $scopeValue => $scopeLabel)
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="scopeTab === '{{ $scopeValue }}'"
                                aria-controls="notification-preferences-panel"
                                :tabindex="scopeTab === '{{ $scopeValue }}' ? 0 : -1"
                                class="ui-tab ui-focus-ring tw-flex tw-items-center tw-gap-2 tw-border-b-2 tw-px-3.5 tw-py-2.5 tw-text-ui-sm tw-font-semibold ui-motion"
                                :class="scopeTab === '{{ $scopeValue }}' ? 'tw-border-primary tw-text-primary' : 'tw-border-transparent tw-text-on-surface-variant hover:tw-border-outline hover:tw-text-on-surface'"
                                @click="setScope('{{ $scopeValue }}')"
                            >
                                {{ __($scopeLabel) }}
                            </button>
                        @endforeach
                    </x-ui.tabs>
                    <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">
                        {{ __('common.review.scope_help') }}
                    </p>
                </div>
            @endif

            @if($showTools)
                {{-- Own container instead of the x-ui.toolbar slots: those give the filter region flex-basis 0 and min-width 0,
                     so on a narrow page it shrinks and squeezes the segmented control instead of wrapping to the next row.
                     Every control here is shrink-0 and wraps as one unit. --}}
                <div class="ui-toolbar tw-mb-0 tw-gap-y-2">
                        <div class="tw-relative tw-w-full sm:tw-w-64 sm:tw-shrink-0">
                            <label for="notification-search" class="tw-sr-only">{{ __('notifications.preferences.search_label') }}</label>
                            <input
                                id="notification-search"
                                type="search"
                                autocomplete="off"
                                class="ui-input tw-w-full tw-ps-9 tw-pe-3 tw-py-1.5 tw-text-ui-sm tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-text-on-surface"
                                placeholder="{{ __('notifications.preferences.search') }}"
                                x-model="query"
                                @input="applyFilters()"
                            >
                            <div class="tw-pointer-events-none tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-ps-2.5 tw-text-on-surface-variant">
                                <x-ui.icon name="search" size="sm" />
                            </div>
                        </div>
                            <div class="tw-grid tw-w-full tw-grid-cols-3 sm:tw-inline-flex sm:tw-w-auto sm:tw-shrink-0" role="group" aria-label="{{ __('notifications.preferences.filter') }}">
                                @foreach($filterOptions as $filterValue => $filterLabel)
                                    <button
                                        type="button"
                                        class="ui-focus-ring ui-motion tw-relative tw-inline-flex tw-min-h-[var(--ui-control-height-sm)] tw-shrink-0 tw-items-center tw-justify-center tw-gap-1.5 tw-whitespace-nowrap max-sm:tw-whitespace-normal max-sm:tw-px-2 max-sm:tw-py-1 max-sm:tw-text-center tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-xs tw-font-medium tw-text-on-surface hover:tw-bg-surface-container first:tw-rounded-l-ui-sm last:tw-rounded-r-ui-sm [&:not(:first-child)]:tw--ml-px aria-pressed:tw-z-10 aria-pressed:tw-border-primary aria-pressed:tw-bg-primary aria-pressed:tw-text-on-primary"
                                        aria-pressed="{{ $loop->first ? 'true' : 'false' }}"
                                        :aria-pressed="filter === '{{ $filterValue }}' ? 'true' : 'false'"
                                        @click="setFilter('{{ $filterValue }}')"
                                    >
                                        {{ $filterLabel }}
                                        <span class="tw-font-normal tw-tabular-nums" x-text="filterCount('{{ $filterValue }}')">{{ $filterCounts[$filterValue] }}</span>
                                    </button>
                                @endforeach
                            </div>

                            <details
                                class="tw-relative tw-shrink-0"
                                x-ref="quickSetup"
                                @keydown.escape="$refs.quickSetup.open = false"
                                @click.outside="$refs.quickSetup.open = false"
                            >
                                <summary class="ui-focus-ring ui-motion tw-inline-flex tw-min-h-[var(--ui-control-height-sm)] tw-cursor-pointer tw-select-none tw-list-none tw-items-center tw-gap-1.5 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-2.5 tw-text-ui-xs tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container [&::-webkit-details-marker]:tw-hidden">
                                    <x-ui.icon name="sliders-horizontal" size="xs" />
                                    {{ __('notifications.preferences.quick_setup') }}
                                    <x-ui.icon name="chevron-down" size="xs" />
                                </summary>
                                <div class="tw-absolute tw-start-0 tw-top-full tw-z-30 tw-mt-1 tw-w-[min(22rem,calc(100vw-2rem))] tw-border tw-border-outline tw-bg-[var(--ui-menu-bg)] tw-p-1">
                                    @foreach(['everything' => ['everything', 'everything_help'], 'action_needed' => ['action_only', 'action_only_help'], 'quiet' => ['quiet', 'quiet_help']] as $preset => [$presetLabel, $presetHelp])
                                        <button
                                            type="button"
                                            class="ui-focus-ring ui-motion tw-flex tw-w-full tw-min-h-11 tw-items-start tw-gap-2 tw-border-0 tw-bg-transparent tw-px-3 tw-py-2 tw-text-start hover:tw-bg-surface-container"
                                            aria-current="false"
                                            :aria-current="presetActive('{{ $preset }}') ? 'true' : 'false'"
                                            @click="applyPreset('{{ $preset }}'); $refs.quickSetup.open = false"
                                        >
                                            <span class="tw-min-w-0 tw-flex-1">
                                                <span class="tw-block tw-text-ui-sm tw-font-semibold tw-text-on-surface">{{ __('notifications.preferences.'.$presetLabel) }}</span>
                                                <span class="tw-mt-0.5 tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ __('notifications.preferences.'.$presetHelp) }}</span>
                                            </span>
                                            <span class="tw-mt-0.5 tw-shrink-0 tw-text-primary" aria-hidden="true" x-show="presetActive('{{ $preset }}')" style="display: none;">
                                                <x-ui.icon name="check" size="sm" />
                                            </span>
                                        </button>
                                    @endforeach
                                </div>
                            </details>

                        <p class="tw-m-0 tw-ms-auto tw-text-ui-xs tw-tabular-nums tw-text-on-surface-variant" x-text="summaryText()">{{ __('notifications.preferences.summary', ['enabled' => $onCount, 'total' => $totalEventsCount, 'silent' => $inboxOnlyCount, 'off' => $totalEventsCount - $onCount]) }}</p>
                </div>
                <p class="tw-sr-only" role="status" aria-live="polite" x-text="shownMessage"></p>
            @endif

            <div
                id="notification-preferences-panel"
                @if($showScopeTabs) role="tabpanel" aria-label="{{ __('notifications.preferences.panel') }}" @endif
                class="tw-grid tw-grid-cols-1 tw-gap-4"
            >
            @foreach($groupedCategories as $category => $categoryEvents)
                @php
                    $categoryId = $categoryIds[$category];
                    $categoryLabel = __($category);
                    $categoryOn = collect($categoryKeys[$categoryId])->filter(fn (string $key): bool => $state[$key]['inbox'])->count();
                    $categoryShown = collect($categoryKeys[$categoryId])->contains(fn (string $key): bool => $initialVisible[$key]);
                    $allInbox = $categoryOn === count($categoryKeys[$categoryId]);
                @endphp
                <section
                    aria-labelledby="notification-category-{{ $loop->index }}"
                    data-category="{{ $categoryLabel }}"
                    class="tw-overflow-hidden tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface"
                    x-show="categoryVisible('{{ $categoryId }}')"
                    @unless($categoryShown) style="display: none;" @endunless
                >
                    <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-x-4 tw-gap-y-1 tw-bg-surface-container tw-px-4 tw-py-2.5 md:tw-grid {{ $rowGrid }}">
                        <div class="tw-flex tw-min-w-0 tw-items-baseline tw-gap-2">
                            <h2 id="notification-category-{{ $loop->index }}" class="tw-m-0 tw-text-ui-sm tw-font-semibold tw-text-on-surface">{{ $categoryLabel }}</h2>
                            <span class="tw-text-ui-xs tw-tabular-nums tw-text-on-surface-variant" x-text="categoryCountText('{{ $categoryId }}')">{{ __('notifications.preferences.category_summary', ['on' => $categoryOn, 'total' => count($categoryKeys[$categoryId])]) }}</span>
                        </div>
                        @foreach($channels as $channel => $channelLabel)
                            @if($showTools)
                                <label class="tw-inline-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-1.5 tw-text-ui-xs tw-font-semibold tw-text-on-surface md:tw-min-h-0 md:tw-flex-col-reverse md:tw-justify-center md:tw-gap-1">
                                    <input
                                        type="checkbox"
                                        class="ui-focus-ring tw-h-4 tw-w-4 tw-shrink-0 tw-cursor-pointer tw-accent-primary"
                                        data-ignore-dirty="true"
                                        aria-label="{{ __('notifications.preferences.bulk_'.$channel, ['category' => $categoryLabel]) }}"
                                        @if($channel === 'inbox') @checked($allInbox) @endif
                                        :checked="bulkState('{{ $categoryId }}', '{{ $channel }}') === 'all'"
                                        :disabled="bulkDisabled('{{ $categoryId }}', '{{ $channel }}')"
                                        x-effect="$el.indeterminate = bulkState('{{ $categoryId }}', '{{ $channel }}') === 'some'"
                                        @change="setBulk('{{ $categoryId }}', '{{ $channel }}', $el.checked)"
                                    >
                                    <span aria-hidden="true">{{ $channelLabel }}</span>
                                </label>
                            @else
                                <span class="tw-hidden tw-text-center tw-text-ui-xs tw-font-semibold tw-text-on-surface md:tw-block" aria-hidden="true">{{ $channelLabel }}</span>
                            @endif
                        @endforeach
                    </div>

                    @foreach($categoryEvents as $key => $event)
                        @php
                            $field = 'notification_preferences.'.$key;
                            $controlId = 'notification-'.$key;
                            $inbox = $state[$key]['inbox'];
                            $popup = $state[$key]['popup'];
                            $isActionRequired = $meta[$key]['action'];
                            $describedBy = 'notification-'.$key.'-help'
                                .($isActionRequired ? ' notification-'.$key.'-warning' : '')
                                .($errors->has($field) ? ' '.$controlId.'-error' : '');
                        @endphp
                        <div
                            role="group"
                            aria-labelledby="notification-{{ $key }}-label"
                            data-event-key="{{ $key }}"
                            data-scope="{{ $meta[$key]['scope'] }}"
                            data-priority="{{ $isActionRequired ? 'action_required' : 'info' }}"
                            class="tw-grid tw-gap-x-4 tw-gap-y-1 tw-border-t tw-border-outline-variant tw-px-4 tw-py-3 md:tw-items-center {{ $rowGrid }}"
                            x-show="visible['{{ $key }}']"
                            @unless($initialVisible[$key]) style="display: none;" @endunless
                        >
                            <div class="tw-min-w-0">
                                <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-x-2 tw-gap-y-0.5">
                                    <span id="notification-{{ $key }}-label" class="tw-text-ui-sm tw-font-semibold tw-text-on-surface">{{ __($event['label']) }}</span>
                                    @if($isActionRequired)
                                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs tw-font-medium tw-text-on-surface-variant">
                                            <x-ui.icon name="triangle-alert" size="xs" class="tw-text-warning" />
                                            {{ __('notifications.preferences.action') }}
                                        </span>
                                    @endif
                                </div>
                                <p id="notification-{{ $key }}-help" class="tw-m-0 tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">{{ __($event['description']) }}</p>
                                @if($isActionRequired)
                                    <p
                                        id="notification-{{ $key }}-warning"
                                        role="alert"
                                        class="action-required-note tw-m-0 tw-mt-1.5 tw-inline-flex tw-items-start tw-gap-1 tw-text-ui-xs tw-text-warning"
                                        x-show="!state['{{ $key }}'].inbox"
                                        @if($inbox) style="display: none;" @endif
                                    >
                                        <x-ui.icon name="triangle-alert" size="xs" class="tw-mt-px tw-shrink-0" />
                                        <span>{{ __('notifications.preferences.warning') }}</span>
                                    </p>
                                @endif
                                @error($field)<p id="{{ $controlId }}-error" class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="tw-flex tw-gap-6 md:tw-contents">
                                <div class="tw-flex md:tw-justify-center">
                                    <input type="hidden" name="notification_preferences[{{ $key }}]" value="0">
                                    <label for="{{ $controlId }}" class="tw-inline-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-text-ui-xs tw-font-medium tw-text-on-surface md:tw-min-w-11 md:tw-justify-center">
                                        <input
                                            id="{{ $controlId }}"
                                            type="checkbox"
                                            name="notification_preferences[{{ $key }}]"
                                            value="1"
                                            class="ui-focus-ring tw-h-5 tw-w-5 tw-shrink-0 tw-cursor-pointer tw-accent-primary"
                                            @checked($inbox)
                                            :checked="state['{{ $key }}'].inbox"
                                            @change="setInbox('{{ $key }}', $el.checked)"
                                            aria-describedby="{{ $describedBy }}"
                                            aria-invalid="{{ $errors->has($field) ? 'true' : 'false' }}"
                                        >
                                        <span class="md:tw-sr-only">{{ $channels['inbox'] }}</span>
                                    </label>
                                </div>
                                <div class="tw-flex md:tw-justify-center">
                                    <input type="hidden" name="notification_delivery[{{ $key }}]" value="silent">
                                    <label
                                        for="{{ $controlId }}-popup"
                                        class="tw-inline-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-text-ui-xs tw-font-medium tw-text-on-surface md:tw-min-w-11 md:tw-justify-center"
                                        :class="state['{{ $key }}'].inbox ? '' : 'tw-cursor-not-allowed tw-opacity-50'"
                                    >
                                        <input
                                            id="{{ $controlId }}-popup"
                                            type="checkbox"
                                            name="notification_delivery[{{ $key }}]"
                                            value="normal"
                                            class="ui-focus-ring tw-h-5 tw-w-5 tw-shrink-0 tw-cursor-pointer tw-accent-primary disabled:tw-cursor-not-allowed"
                                            @checked($inbox && $popup)
                                            @disabled(! $inbox)
                                            :checked="state['{{ $key }}'].inbox && state['{{ $key }}'].popup"
                                            :disabled="!state['{{ $key }}'].inbox"
                                            @change="setPopup('{{ $key }}', $el.checked)"
                                            aria-describedby="notification-{{ $key }}-help"
                                        >
                                        <span class="md:tw-sr-only">{{ $channels['popup'] }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </section>
            @endforeach
            </div>

            <div x-cloak x-show="visibleCount === 0" class="tw-border tw-border-outline tw-bg-surface tw-rounded-ui-sm tw-p-6">
                <x-ui.empty-state
                    icon="search-x"
                    :title="__('notifications.preferences.empty')"
                    :description="__('notifications.preferences.empty_help')"
                >
                    <x-ui.button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="tw-mt-4"
                        @click="clearFilters()"
                    >
                        {{ __('notifications.preferences.clear_filters') }}
                    </x-ui.button>
                </x-ui.empty-state>
            </div>

            <x-ui.action-bar class="tw-mt-1">
                <x-slot:left>
                    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-x-3 tw-gap-y-1 tw-text-ui-xs">
                        <div role="status" aria-live="polite">
                            <span x-show="dirtyCount > 0" x-cloak class="tw-font-medium tw-text-on-surface" x-text="unsavedText()">{{ __('notifications.preferences.unsaved', ['count' => 0]) }}</span>
                            <span x-show="dirtyCount === 0" class="tw-text-on-surface-variant">{{ __('notifications.preferences.no_changes') }}</span>
                        </div>
                        <x-ui.button
                            type="button"
                            variant="ghost"
                            size="sm"
                            x-on:click="$dispatch('open-ui-dialog', 'reset-notification-preferences')"
                        >
                            {{ __('notifications.preferences.reset') }}
                        </x-ui.button>
                    </div>
                </x-slot:left>
                <x-slot:right>
                    <x-ui.button
                        type="button"
                        variant="ghost"
                        @click="discard()"
                        x-bind:disabled="dirtyCount === 0"
                        disabled
                    >
                        {{ __('notifications.preferences.discard') }}
                    </x-ui.button>
                    <x-ui.button
                        type="submit"
                        x-bind:disabled="dirtyCount === 0"
                    >
                        {{ __('common.actions.save') }}
                    </x-ui.button>
                </x-slot:right>
            </x-ui.action-bar>
        </form>
    @endif

    <x-ui.dialog
        name="reset-notification-preferences"
        :title="__('notifications.preferences.reset_title')"
    >
        <form method="POST" action="{{ route('profile.notifications.reset') }}" id="reset-preferences-form">
            @csrf
            @method('DELETE')
            <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant">
                {{ __('notifications.preferences.reset_help') }}
            </p>
        </form>

        <x-slot:actions>
            <x-ui.button
                type="button"
                variant="outline"
                x-on:click="$dispatch('close-ui-dialog', 'reset-notification-preferences')"
            >
                {{ __('common.actions.cancel') }}
            </x-ui.button>
            <x-ui.button
                type="submit"
                form="reset-preferences-form"
                variant="danger"
            >
                {{ __('notifications.preferences.reset') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.dialog>
</div>
</x-account.shell>
@endsection
