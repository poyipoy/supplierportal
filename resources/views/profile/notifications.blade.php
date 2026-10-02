@extends('layouts.app')

@section('title', 'Notifications - ADASI Supplier Portal')
@section('page-title', 'Notifications')

@section('content')
<div class="tw-grid tw-gap-5">
    <x-ui.page-header
        title="Notifications"
        description="Choose which in-app notifications you want to receive."
        eyebrow="Account"
        style="--md-on-surface-variant: var(--md-on-surface);"
    />

    @if($errors->any())
        <x-ui.alert tone="error" title="Review your notification preferences">
            <ul class="tw-m-0 tw-list-disc tw-pl-5">
                @foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </x-ui.alert>
    @endif

    @if($events === [])
        <section class="tw-border tw-border-outline tw-bg-surface tw-p-5" aria-labelledby="notification-options-title">
            <h2 id="notification-options-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Notification settings</h2>
            <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">There are no notification settings available for your account.</p>
        </section>
    @else
        @php
            $groupedCategories = collect($events)->groupBy('category', preserveKeys: true);
            $totalEventsCount = count($events);
            $categoryCount = $groupedCategories->count();
            $allOpenInitially = ($totalEventsCount <= 12 || $categoryCount <= 2);

            $totalEnabledCount = 0;
            foreach ($events as $k => $e) {
                if ((bool) old('notification_preferences.'.$k, $effectivePreferences[$k])) {
                    $totalEnabledCount++;
                }
            }

            $isSupplier = auth()->user()?->isSupplier();
            $userRole = auth()->user()?->role;
            $hasImportScope = false;
            $hasLocalScope = false;
            foreach ($events as $e) {
                $scopes = $e['supplier_scopes'] ?? [];
                if (in_array('import', $scopes, true)) $hasImportScope = true;
                if (in_array('local', $scopes, true)) $hasLocalScope = true;
            }
            $showScopeTabs = (bool) ($isSupplier && $hasImportScope && $hasLocalScope);
            $defaultScope = (\App\Support\PortalContext::current() === 'local') ? 'local' : 'import';

            $initialCategoryStats = [];
            foreach ($groupedCategories as $catName => $catEvts) {
                $onCount = 0;
                foreach ($catEvts as $k => $e) {
                    if ((bool) old('notification_preferences.'.$k, $effectivePreferences[$k])) {
                        $onCount++;
                    }
                }
                $initialCategoryStats[$catName] = [
                    'on' => $onCount,
                    'total' => count($catEvts),
                ];
            }
        @endphp
        <form
            method="POST"
            action="{{ route('profile.notifications.update') }}"
            class="tw-grid tw-gap-5"
            x-data="{
                dirtyCount: 0,
                isSubmitting: false,
                allExpanded: false,
                searchQuery: '',
                activeFilter: 'all',
                scopeTab: @js($showScopeTabs ? $defaultScope : 'all'),
                totalEnabled: {{ $totalEnabledCount }},
                totalEvents: {{ $totalEventsCount }},
                visibleEventCount: {{ $totalEventsCount }},
                categoryStats: @js($initialCategoryStats),
                init() {
                    this.updateDirty();
                    if (this.scopeTab !== 'all') {
                        this.applyFilters();
                    }
                    window.addEventListener('beforeunload', (e) => {
                        if (this.dirtyCount > 0 && !this.isSubmitting) {
                            e.preventDefault();
                            e.returnValue = '';
                        }
                    });
                },
                updateDirty() {
                    const checkboxes = this.$el.querySelectorAll('input[type=checkbox][name^=\"notification_preferences\"]');
                    let count = 0;
                    checkboxes.forEach(cb => {
                        if (cb.checked !== cb.defaultChecked) count++;
                    });
                    this.dirtyCount = count;
                    this.updateCategoryStats();
                    this.updateOverallStats();
                    this.applyFilters();
                },
                updateOverallStats() {
                    const cbs = this.$el.querySelectorAll('input[type=checkbox][name^=\"notification_preferences\"]');
                    let enabled = 0;
                    cbs.forEach(cb => { if (cb.checked) enabled++; });
                    this.totalEnabled = enabled;
                },
                updateCategoryStats() {
                    this.$el.querySelectorAll('details[data-category]').forEach(detail => {
                        const cat = detail.getAttribute('data-category');
                        const cbs = detail.querySelectorAll('input[type=checkbox][name^=\"notification_preferences\"]');
                        let on = 0;
                        cbs.forEach(cb => { if (cb.checked) on++; });
                        if (this.categoryStats[cat]) {
                            this.categoryStats[cat].on = on;
                        }
                    });
                },
                turnCategoryAll(catName, turnOn) {
                    const detail = this.$el.querySelector('details[data-category=\"' + catName + '\"]');
                    if (!detail) return;
                    const cbs = detail.querySelectorAll('input[type=checkbox][name^=\"notification_preferences\"]');
                    cbs.forEach(cb => {
                        cb.checked = turnOn;
                        const labelSpan = cb.parentElement ? cb.parentElement.querySelector('label span[aria-hidden]') : null;
                        if (labelSpan) {
                            labelSpan.textContent = turnOn ? 'On' : 'Off';
                        }
                        const note = cb.closest('fieldset').querySelector('.action-required-note');
                        if (note) {
                            note.style.display = turnOn ? 'none' : 'inline-flex';
                        }
                    });
                    this.updateDirty();
                },
                toggleAllCategories() {
                    const details = Array.from(this.$el.querySelectorAll('details[data-category]'));
                    const anyClosed = details.some(d => !d.open);
                    details.forEach(d => { d.open = anyClosed; });
                    this.allExpanded = anyClosed;
                },
                applyFilters() {
                    const query = this.searchQuery.trim().toLowerCase();
                    const rows = this.$el.querySelectorAll('fieldset[data-event-key]');
                    let count = 0;

                    rows.forEach(row => {
                        const label = (row.getAttribute('data-event-label') || '').toLowerCase();
                        const desc = (row.getAttribute('data-event-desc') || '').toLowerCase();
                        const scope = row.getAttribute('data-scope') || 'general';
                        const cb = row.querySelector('input[type=checkbox][name^=\"notification_preferences\"]');
                        const isEnabled = cb ? cb.checked : true;

                        const matchesSearch = !query || label.includes(query) || desc.includes(query);
                        let matchesFilter = true;
                        if (this.activeFilter === 'enabled') {
                            matchesFilter = isEnabled;
                        } else if (this.activeFilter === 'muted') {
                            matchesFilter = !isEnabled;
                        }

                        let matchesScope = true;
                        if (this.scopeTab && this.scopeTab !== 'all') {
                            matchesScope = (scope === this.scopeTab || scope === 'general');
                        }

                        const isVisible = matchesSearch && matchesFilter && matchesScope;
                        if (isVisible) {
                            row.removeAttribute('hidden');
                            count++;
                        } else {
                            row.setAttribute('hidden', '');
                        }
                    });

                    this.visibleEventCount = count;

                    this.$el.querySelectorAll('details[data-category]').forEach(detail => {
                        const visibleRows = detail.querySelectorAll('fieldset[data-event-key]:not([hidden])');
                        if (visibleRows.length === 0) {
                            detail.setAttribute('hidden', '');
                        } else {
                            detail.removeAttribute('hidden');
                            if (query || this.activeFilter !== 'all') {
                                detail.open = true;
                            }
                        }
                    });
                },
                clearFilters() {
                    this.searchQuery = '';
                    this.activeFilter = 'all';
                    this.applyFilters();
                },
                applyPreset(preset) {
                    const rows = this.$el.querySelectorAll('fieldset[data-event-key]');
                    rows.forEach(row => {
                        const cb = row.querySelector('input[type=checkbox][name^=\"notification_preferences\"]');
                        if (!cb) return;
                        const isActionRequired = row.getAttribute('data-priority') === 'action_required';
                        const shouldCheck = (preset === 'everything') ? true : isActionRequired;
                        cb.checked = shouldCheck;
                        const labelSpan = cb.parentElement ? cb.parentElement.querySelector('label span[aria-hidden]') : null;
                        if (labelSpan) {
                            labelSpan.textContent = shouldCheck ? 'On' : 'Off';
                        }
                        const note = row.querySelector('.action-required-note');
                        if (note) {
                            note.style.display = shouldCheck ? 'none' : 'inline-flex';
                        }
                    });
                    this.updateDirty();
                },
                discard() {
                    const checkboxes = this.$el.querySelectorAll('input[type=checkbox][name^=\"notification_preferences\"]');
                    checkboxes.forEach(cb => {
                        cb.checked = cb.defaultChecked;
                        const labelSpan = cb.parentElement ? cb.parentElement.querySelector('label span[aria-hidden]') : null;
                        if (labelSpan) {
                            labelSpan.textContent = cb.checked ? 'On' : 'Off';
                        }
                        const note = cb.closest('fieldset').querySelector('.action-required-note');
                        if (note) {
                            note.style.display = cb.checked ? 'none' : 'inline-flex';
                        }
                    });
                    this.updateDirty();
                }
            }"
            @change="updateDirty()"
            @submit="isSubmitting = true"
        >
            @csrf
            @method('PATCH')

            @if($showScopeTabs)
                <div class="tw-mb-4 tw-grid tw-gap-2">
                    <x-ui.tabs label="Supplier portal scope filter">
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="scopeTab === 'all'"
                            class="ui-tab ui-focus-ring tw-flex tw-items-center tw-gap-2 tw-border-b-2 tw-px-3.5 tw-py-2.5 tw-text-ui-sm tw-font-semibold ui-motion"
                            :class="scopeTab === 'all' ? 'tw-border-primary tw-text-primary' : 'tw-border-transparent tw-text-on-surface-variant hover:tw-border-outline hover:tw-text-on-surface'"
                            @click="scopeTab = 'all'; applyFilters()"
                        >
                            All Portals
                        </button>
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="scopeTab === 'import'"
                            class="ui-tab ui-focus-ring tw-flex tw-items-center tw-gap-2 tw-border-b-2 tw-px-3.5 tw-py-2.5 tw-text-ui-sm tw-font-semibold ui-motion"
                            :class="scopeTab === 'import' ? 'tw-border-primary tw-text-primary' : 'tw-border-transparent tw-text-on-surface-variant hover:tw-border-outline hover:tw-text-on-surface'"
                            @click="scopeTab = 'import'; applyFilters()"
                        >
                            Import
                        </button>
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="scopeTab === 'local'"
                            class="ui-tab ui-focus-ring tw-flex tw-items-center tw-gap-2 tw-border-b-2 tw-px-3.5 tw-py-2.5 tw-text-ui-sm tw-font-semibold ui-motion"
                            :class="scopeTab === 'local' ? 'tw-border-primary tw-text-primary' : 'tw-border-transparent tw-text-on-surface-variant hover:tw-border-outline hover:tw-text-on-surface'"
                            @click="scopeTab = 'local'; applyFilters()"
                        >
                            Local
                        </button>
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="scopeTab === 'general'"
                            class="ui-tab ui-focus-ring tw-flex tw-items-center tw-gap-2 tw-border-b-2 tw-px-3.5 tw-py-2.5 tw-text-ui-sm tw-font-semibold ui-motion"
                            :class="scopeTab === 'general' ? 'tw-border-primary tw-text-primary' : 'tw-border-transparent tw-text-on-surface-variant hover:tw-border-outline hover:tw-text-on-surface'"
                            @click="scopeTab = 'general'; applyFilters()"
                        >
                            General
                        </button>
                    </x-ui.tabs>
                    <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">
                        Notification preferences are account-wide and apply to both Import and Local portals.
                    </p>
                </div>
            @endif

            @if($totalEventsCount > 8)
                <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-mb-1">
                    <div class="tw-flex tw-items-center tw-gap-2">
                        <span class="tw-text-ui-sm tw-font-semibold tw-text-on-surface">Preferences</span>
                        <div x-show="totalEvents === totalEnabled" @if($totalEnabledCount < $totalEventsCount) style="display: none;" @endif>
                            <x-ui.status-chip tone="success">
                                <span x-text="totalEnabled + ' of ' + totalEvents + ' enabled'">{{ $totalEnabledCount }} of {{ $totalEventsCount }} enabled</span>
                            </x-ui.status-chip>
                        </div>
                        <div x-show="totalEnabled < totalEvents" @if($totalEnabledCount === $totalEventsCount) style="display: none;" @endif>
                            <x-ui.status-chip tone="warning">
                                <span x-text="totalEnabled + ' of ' + totalEvents + ' enabled'">{{ $totalEnabledCount }} of {{ $totalEventsCount }} enabled</span>
                            </x-ui.status-chip>
                        </div>
                    </div>
                </div>

                <x-ui.toolbar class="tw-mb-2">
                    <x-slot:search>
                        <div class="tw-relative tw-w-full">
                            <input
                                type="search"
                                class="ui-input tw-w-full tw-ps-9 tw-pe-3 tw-py-1.5 tw-text-ui-sm tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-text-on-surface"
                                placeholder="Search notifications..."
                                x-model="searchQuery"
                                @input="applyFilters()"
                            >
                            <div class="tw-pointer-events-none tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-ps-2.5 tw-text-on-surface-variant">
                                <x-ui.icon name="search" size="sm" />
                            </div>
                        </div>
                    </x-slot:search>

                    <x-slot:filters>
                        <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-3">
                            <div class="tw-inline-flex tw-rounded-ui-sm tw-border tw-border-outline tw-p-0.5 tw-bg-surface" role="group" aria-label="Filter notifications">
                                <button
                                    type="button"
                                    class="tw-px-3 tw-py-1 tw-text-ui-xs tw-font-medium tw-rounded-ui-xs ui-motion"
                                    :class="activeFilter === 'all' ? 'tw-bg-primary tw-text-on-primary tw-shadow-sm' : 'tw-text-on-surface-variant hover:tw-text-on-surface'"
                                    @click="activeFilter = 'all'; applyFilters()"
                                >
                                    All
                                </button>
                                <button
                                    type="button"
                                    class="tw-px-3 tw-py-1 tw-text-ui-xs tw-font-medium tw-rounded-ui-xs ui-motion"
                                    :class="activeFilter === 'enabled' ? 'tw-bg-primary tw-text-on-primary tw-shadow-sm' : 'tw-text-on-surface-variant hover:tw-text-on-surface'"
                                    @click="activeFilter = 'enabled'; applyFilters()"
                                >
                                    Enabled
                                </button>
                                <button
                                    type="button"
                                    class="tw-px-3 tw-py-1 tw-text-ui-xs tw-font-medium tw-rounded-ui-xs ui-motion"
                                    :class="activeFilter === 'muted' ? 'tw-bg-primary tw-text-on-primary tw-shadow-sm' : 'tw-text-on-surface-variant hover:tw-text-on-surface'"
                                    @click="activeFilter = 'muted'; applyFilters()"
                                >
                                    Muted
                                </button>
                            </div>

                            <div class="tw-inline-flex tw-items-center tw-gap-1.5 tw-text-ui-xs tw-text-on-surface-variant">
                                <span class="tw-font-medium">Presets:</span>
                                <button type="button" class="ui-button ui-button--ghost ui-focus-ring tw-text-ui-xs tw-text-primary hover:tw-underline tw-px-1.5 tw-py-0.5 tw-rounded" @click="applyPreset('everything')">Everything</button>
                                <span class="tw-text-outline" aria-hidden="true">·</span>
                                <button type="button" class="ui-button ui-button--ghost ui-focus-ring tw-text-ui-xs tw-text-primary hover:tw-underline tw-px-1.5 tw-py-0.5 tw-rounded" @click="applyPreset('action_needed')">Action needed only</button>
                            </div>
                        </div>
                    </x-slot:filters>

                    <x-slot:actions>
                        @if($categoryCount > 1)
                            <x-ui.button
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="toggleAllCategories()"
                            >
                                <span x-text="allExpanded ? 'Collapse all' : 'Expand all'">Expand all</span>
                            </x-ui.button>
                        @endif
                    </x-slot:actions>
                </x-ui.toolbar>
            @endif

            @foreach($groupedCategories as $category => $categoryEvents)
                @php
                    $hasMuted = false;
                    $hasError = false;
                    foreach ($categoryEvents as $k => $e) {
                        if (! (bool) old('notification_preferences.'.$k, $effectivePreferences[$k])) {
                            $hasMuted = true;
                        }
                        if ($errors->has('notification_preferences.'.$k)) {
                            $hasError = true;
                        }
                    }
                    $isOpen = $allOpenInitially || $loop->first || $hasMuted || $hasError;
                @endphp
                <details
                    class="tw-border tw-border-outline tw-bg-surface tw-group tw-rounded-ui-sm tw-overflow-hidden"
                    aria-labelledby="notification-category-{{ $loop->index }}"
                    data-category="{{ $category }}"
                    @if($isOpen) open @endif
                >
                    <summary class="ui-focus-ring tw-flex tw-cursor-pointer tw-items-center tw-justify-between tw-gap-3 tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4 tw-select-none list-none [&::-webkit-details-marker]:tw-hidden">
                        <div class="tw-flex tw-items-center tw-gap-3">
                            <svg class="tw-h-4 tw-w-4 tw-text-on-surface-variant ui-motion tw-transition-transform tw-duration-150 group-open:tw-rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="9 18 15 12 9 6"></polyline>
                            </svg>
                            <h2 id="notification-category-{{ $loop->index }}" class="tw-m-0 tw-text-ui-sm tw-font-semibold tw-text-on-surface">{{ $category }}</h2>
                            <span class="tw-text-ui-xs tw-text-on-surface-variant" x-text="categoryStats['{{ $category }}'] ? (categoryStats['{{ $category }}'].on + ' of ' + categoryStats['{{ $category }}'].total + ' on') : '{{ $initialCategoryStats[$category]['on'] }} of {{ $initialCategoryStats[$category]['total'] }} on'">
                                {{ $initialCategoryStats[$category]['on'] }} of {{ $initialCategoryStats[$category]['total'] }} on
                            </span>
                        </div>
                        @if($totalEventsCount > 8)
                            <div class="tw-flex tw-items-center tw-gap-2" @click.stop>
                                <button
                                    type="button"
                                    class="ui-button ui-button--ghost ui-focus-ring tw-text-ui-xs tw-text-primary hover:tw-underline tw-px-2 tw-py-1 tw-rounded"
                                    @click="turnCategoryAll('{{ $category }}', true)"
                                >
                                    Turn all on
                                </button>
                                <span class="tw-text-outline" aria-hidden="true">·</span>
                                <button
                                    type="button"
                                    class="ui-button ui-button--ghost ui-focus-ring tw-text-ui-xs tw-text-on-surface-variant hover:tw-underline tw-px-2 tw-py-1 tw-rounded"
                                    @click="turnCategoryAll('{{ $category }}', false)"
                                >
                                    Turn all off
                                </button>
                            </div>
                        @endif
                    </summary>
                    <div class="tw-grid tw-gap-5 tw-p-5">
                        @foreach($categoryEvents as $key => $event)
                            @php
                                $field = 'notification_preferences.'.$key;
                                $controlId = 'notification-'.$key;
                                $isChecked = (bool) old($field, $effectivePreferences[$key]);
                                $isActionRequired = (($event['priority'] ?? null) === 'action_required')
                                    && (!isset($event['priority_roles']) || in_array($userRole, $event['priority_roles'], true));
                            @endphp
                            <fieldset
                                class="tw-grid tw-min-w-0 tw-gap-2 tw-py-3 tw-border-b tw-border-outline-variant last:tw-border-b-0"
                                data-event-key="{{ $key }}"
                                data-event-label="{{ $event['label'] }}"
                                data-event-desc="{{ $event['description'] }}"
                                data-scope="{{ !empty($event['supplier_scopes']) ? (in_array('import', $event['supplier_scopes']) ? 'import' : 'local') : 'general' }}"
                                data-priority="{{ $isActionRequired ? 'action_required' : 'info' }}"
                            >
                                <legend class="tw-text-ui-sm tw-font-semibold">{{ $event['label'] }}</legend>
                                <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3">
                                    <div class="tw-min-w-0 tw-flex-1">
                                        @if($isActionRequired)
                                            <div class="tw-mb-1 tw-inline-flex tw-items-center">
                                                <x-ui.status-chip tone="warning">Action needed</x-ui.status-chip>
                                            </div>
                                        @endif
                                        <p id="notification-{{ $key }}-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ $event['description'] }}</p>
                                        @if($isActionRequired)
                                            <p class="action-required-note tw-m-0 tw-mt-1.5 tw-text-ui-xs tw-text-warning tw-inline-flex tw-items-center tw-gap-1" @if($isChecked) style="display: none;" @endif>
                                                <x-ui.icon name="triangle-alert" size="xs" />
                                                <span>This notification requires your action. Muting it may cause you to miss pending tasks.</span>
                                            </p>
                                        @endif
                                    </div>
                                    <div class="tw-flex tw-items-center tw-gap-2.5 tw-shrink-0 tw-min-h-11">
                                        <input type="hidden" name="notification_preferences[{{ $key }}]" value="0">
                                        <x-ui.switch
                                            id="{{ $controlId }}"
                                            name="notification_preferences[{{ $key }}]"
                                            value="1"
                                            :checked="$isChecked"
                                            aria-describedby="notification-{{ $key }}-help @error($field) {{ $controlId }}-error @enderror"
                                            aria-invalid="{{ $errors->has($field) ? 'true' : 'false' }}"
                                            onchange="this.parentElement.querySelector('label span[aria-hidden]').textContent = this.checked ? 'On' : 'Off'; const note = this.closest('fieldset').querySelector('.action-required-note'); if (note) { note.style.display = this.checked ? 'none' : 'inline-flex'; }"
                                        />
                                        <label for="{{ $controlId }}" class="tw-cursor-pointer tw-select-none tw-text-ui-xs tw-font-medium tw-text-on-surface-variant tw-min-w-[1.75rem]">
                                            <span class="tw-sr-only">{{ $event['label'] }}</span>
                                            <span aria-hidden="true">{{ $isChecked ? 'On' : 'Off' }}</span>
                                        </label>
                                    </div>
                                </div>
                                @error($field)<p id="{{ $controlId }}-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                            </fieldset>
                        @endforeach
                    </div>
                </details>
            @endforeach

            <div x-cloak x-show="visibleEventCount === 0" class="tw-border tw-border-outline tw-bg-surface tw-rounded-ui-sm tw-p-6">
                <x-ui.empty-state
                    icon="search-x"
                    title="No notification preferences found"
                    description="No notification settings match your current search and filter criteria."
                >
                    <x-ui.button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="tw-mt-4"
                        @click="clearFilters()"
                    >
                        Clear filters
                    </x-ui.button>
                </x-ui.empty-state>
            </div>

            <x-ui.action-bar class="tw-mt-2">
                <x-slot:left>
                    <div class="tw-flex tw-items-center tw-gap-2 tw-text-ui-xs">
                        <span x-show="dirtyCount > 0" x-cloak class="tw-inline-flex tw-items-center tw-gap-1.5 tw-font-medium tw-text-warning">
                            <span class="tw-h-2 tw-w-2 tw-rounded-full tw-bg-warning" aria-hidden="true"></span>
                            <span x-text="dirtyCount + (dirtyCount === 1 ? ' unsaved change' : ' unsaved changes')">1 unsaved change</span>
                        </span>
                        <span x-show="dirtyCount === 0" class="tw-text-on-surface-variant">No unsaved changes</span>
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
                        Discard
                    </x-ui.button>
                    <x-ui.button
                        type="submit"
                        x-bind:disabled="dirtyCount === 0"
                    >
                        Save Changes
                    </x-ui.button>
                </x-slot:right>
            </x-ui.action-bar>
        </form>
    @endif

    <div class="tw-flex tw-items-center tw-justify-start">
        <x-ui.button
            type="button"
            variant="outline"
            x-on:click="$dispatch('open-ui-dialog', 'reset-notification-preferences')"
        >
            Reset to defaults
        </x-ui.button>
    </div>

    <x-ui.dialog
        name="reset-notification-preferences"
        title="Reset notification preferences"
    >
        <form method="POST" action="{{ route('profile.notifications.reset') }}" id="reset-preferences-form">
            @csrf
            @method('DELETE')
            <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant">
                All notification settings will return to default, and any unsaved changes on this page will be discarded.
            </p>
        </form>

        <x-slot:actions>
            <x-ui.button
                type="button"
                variant="outline"
                x-on:click="$dispatch('close-ui-dialog', 'reset-notification-preferences')"
            >
                Cancel
            </x-ui.button>
            <x-ui.button
                type="submit"
                form="reset-preferences-form"
                variant="danger"
            >
                Reset to defaults
            </x-ui.button>
        </x-slot:actions>
    </x-ui.dialog>
</div>
@endsection
