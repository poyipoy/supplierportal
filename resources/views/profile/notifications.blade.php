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
            $totalSilentCount = 0;
            $initialSwitches = [];
            $initialDelivery = [];
            foreach ($events as $k => $e) {
                $isOn = (bool) old('notification_preferences.'.$k, $effectivePreferences[$k]);
                $del = (string) old('notification_delivery.'.$k, $deliveryPreferences[$k] ?? 'normal');
                if ($del !== 'silent') {
                    $del = 'normal';
                }
                $initialSwitches[$k] = $isOn;
                $initialDelivery[$k] = $del;
                if ($isOn) {
                    $totalEnabledCount++;
                    if ($del === 'silent') {
                        $totalSilentCount++;
                    }
                }
            }
            $offCount = $totalEventsCount - $totalEnabledCount;

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
            x-data="notificationPreferencesForm({
                scopeTab: @js($showScopeTabs ? $defaultScope : 'all'),
                totalEnabled: {{ $totalEnabledCount }},
                totalSilent: {{ $totalSilentCount }},
                totalEvents: {{ $totalEventsCount }},
                categoryStats: @js($initialCategoryStats),
                initialSwitches: @js($initialSwitches),
                initialDelivery: @js($initialDelivery),
            })"
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
                            :aria-selected="scopeTab === 'import'"
                            aria-controls="notification-preferences-panel"
                            :tabindex="scopeTab === 'import' ? 0 : -1"
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
                            aria-controls="notification-preferences-panel"
                            :tabindex="scopeTab === 'local' ? 0 : -1"
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
                            aria-controls="notification-preferences-panel"
                            :tabindex="scopeTab === 'general' ? 0 : -1"
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
                                <span x-text="summaryChipText()">{{ $totalEnabledCount }} of {{ $totalEventsCount }} enabled · {{ $totalSilentCount }} silent · {{ $offCount }} off</span>
                            </x-ui.status-chip>
                        </div>
                        <div x-show="totalEnabled < totalEvents" @if($totalEnabledCount === $totalEventsCount) style="display: none;" @endif>
                            <x-ui.status-chip tone="warning">
                                <span x-text="summaryChipText()">{{ $totalEnabledCount }} of {{ $totalEventsCount }} enabled · {{ $totalSilentCount }} silent · {{ $offCount }} off</span>
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
                                    :aria-pressed="activeFilter === 'all' ? 'true' : 'false'"
                                    @click="activeFilter = 'all'; applyFilters()"
                                >
                                    All
                                </button>
                                <button
                                    type="button"
                                    class="tw-px-3 tw-py-1 tw-text-ui-xs tw-font-medium tw-rounded-ui-xs ui-motion"
                                    :class="activeFilter === 'enabled' ? 'tw-bg-primary tw-text-on-primary tw-shadow-sm' : 'tw-text-on-surface-variant hover:tw-text-on-surface'"
                                    :aria-pressed="activeFilter === 'enabled' ? 'true' : 'false'"
                                    @click="activeFilter = 'enabled'; applyFilters()"
                                >
                                    Enabled
                                </button>
                                <button
                                    type="button"
                                    class="tw-px-3 tw-py-1 tw-text-ui-xs tw-font-medium tw-rounded-ui-xs ui-motion"
                                    :class="activeFilter === 'muted' ? 'tw-bg-primary tw-text-on-primary tw-shadow-sm' : 'tw-text-on-surface-variant hover:tw-text-on-surface'"
                                    :aria-pressed="activeFilter === 'muted' ? 'true' : 'false'"
                                    @click="activeFilter = 'muted'; applyFilters()"
                                >
                                    Muted
                                </button>
                                <button
                                    type="button"
                                    class="tw-px-3 tw-py-1 tw-text-ui-xs tw-font-medium tw-rounded-ui-xs ui-motion"
                                    :class="activeFilter === 'silent' ? 'tw-bg-primary tw-text-on-primary tw-shadow-sm' : 'tw-text-on-surface-variant hover:tw-text-on-surface'"
                                    :aria-pressed="activeFilter === 'silent' ? 'true' : 'false'"
                                    @click="activeFilter = 'silent'; applyFilters()"
                                >
                                    Silent
                                </button>
                            </div>

                            <div class="tw-inline-flex tw-items-center tw-gap-1.5 tw-text-ui-xs tw-text-on-surface-variant">
                                <span class="tw-font-medium">Presets:</span>
                                <button type="button" class="ui-button ui-button--ghost ui-focus-ring tw-text-ui-xs tw-text-primary hover:tw-underline tw-px-1.5 tw-py-0.5 tw-rounded" @click="applyPreset('everything')">Everything</button>
                                <span class="tw-text-outline" aria-hidden="true">·</span>
                                <button type="button" class="ui-button ui-button--ghost ui-focus-ring tw-text-ui-xs tw-text-primary hover:tw-underline tw-px-1.5 tw-py-0.5 tw-rounded" @click="applyPreset('action_needed')">Action needed only</button>
                                <span class="tw-text-outline" aria-hidden="true">·</span>
                                <button type="button" class="ui-button ui-button--ghost ui-focus-ring tw-text-ui-xs tw-text-primary hover:tw-underline tw-px-1.5 tw-py-0.5 tw-rounded" @click="applyPreset('quiet')">Quiet mode</button>
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

            <div id="notification-preferences-panel" role="tabpanel" aria-label="Notification preferences" class="tw-grid tw-gap-5">
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
                            <span class="tw-text-ui-xs tw-text-on-surface-variant" x-text="categorySummaryText($el)">
                                {{ $initialCategoryStats[$category]['on'] }} of {{ $initialCategoryStats[$category]['total'] }} on
                            </span>
                        </div>
                        @if($totalEventsCount > 8)
                            <div class="tw-flex tw-items-center tw-gap-2" @click.stop>
                                <button
                                    type="button"
                                    class="ui-button ui-button--ghost ui-focus-ring tw-text-ui-xs tw-text-primary hover:tw-underline tw-px-2 tw-py-1 tw-rounded"
                                    @click="turnCategoryAll($el, true)"
                                >
                                    Turn all on
                                </button>
                                <span class="tw-text-outline" aria-hidden="true">·</span>
                                <button
                                    type="button"
                                    class="ui-button ui-button--ghost ui-focus-ring tw-text-ui-xs tw-text-on-surface-variant hover:tw-underline tw-px-2 tw-py-1 tw-rounded"
                                    @click="turnCategoryAll($el, false)"
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
                                            <p id="notification-{{ $key }}-warning" role="alert" class="action-required-note tw-m-0 tw-mt-1.5 tw-text-ui-xs tw-text-warning tw-inline-flex tw-items-center tw-gap-1" @if($isChecked) style="display: none;" @endif>
                                                <x-ui.icon name="triangle-alert" size="xs" />
                                                <span>This notification requires your action. Muting it may cause you to miss pending tasks.</span>
                                            </p>
                                        @endif
                                        <div class="tw-mt-2 delivery-mode-container" x-show="switches['{{ $key }}']" @if(!$isChecked) style="display: none;" @endif>
                                            <select
                                                name="notification_delivery[{{ $key }}]"
                                                x-model="delivery['{{ $key }}']"
                                                @change="onDeliveryChange('{{ $key }}', $el)"
                                                aria-label="{{ $event['label'] }} delivery mode"
                                                class="ui-input tw-text-ui-xs tw-rounded-ui-xs tw-border tw-border-outline tw-bg-surface tw-text-on-surface tw-py-1 tw-ps-2 tw-pe-6"
                                            >
                                                <option value="normal" @selected(($deliveryPreferences[$key] ?? 'normal') === 'normal')>Normal — popup + inbox</option>
                                                <option value="silent" @selected(($deliveryPreferences[$key] ?? 'normal') === 'silent')>Silent — inbox only</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="tw-flex tw-items-center tw-gap-2.5 tw-shrink-0 tw-min-h-11">
                                        <input type="hidden" name="notification_preferences[{{ $key }}]" value="0">
                                        <x-ui.switch
                                            id="{{ $controlId }}"
                                            name="notification_preferences[{{ $key }}]"
                                            value="1"
                                            :checked="$isChecked"
                                            x-model="switches['{{ $key }}']"
                                            aria-describedby="notification-{{ $key }}-help @if($isActionRequired) notification-{{ $key }}-warning @endif @error($field) {{ $controlId }}-error @enderror"
                                            aria-invalid="{{ $errors->has($field) ? 'true' : 'false' }}"
                                            onchange="this.parentElement.querySelector('label span[aria-hidden]').textContent = this.checked ? 'On' : 'Off'; const note = this.closest('fieldset').querySelector('.action-required-note'); if (note) { note.style.display = this.checked ? 'none' : 'inline-flex'; }"
                                            @change="onSwitchChange('{{ $key }}', $el)"
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
            </div>

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
                    <div class="tw-flex tw-items-center tw-gap-2 tw-text-ui-xs" role="status" aria-live="polite">
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
        <form method="POST" action="{{ route('profile.notifications.reset') }}" id="reset-preferences-form" @submit="isSubmitting = true">
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

@push('scripts')
<script>
function notificationPreferencesForm(config) {
    return {
        dirtyCount: 0,
        isSubmitting: false,
        allExpanded: false,
        searchQuery: '',
        activeFilter: 'all',
        scopeTab: config.scopeTab,
        totalEnabled: config.totalEnabled,
        totalSilent: config.totalSilent,
        totalEvents: config.totalEvents,
        visibleEventCount: config.totalEvents,
        categoryStats: config.categoryStats,
        switches: Object.assign({}, config.initialSwitches),
        delivery: Object.assign({}, config.initialDelivery),
        initialSwitches: Object.assign({}, config.initialSwitches),
        initialDelivery: Object.assign({}, config.initialDelivery),
        getRoot() {
            return this.$root || this.$el || document.querySelector('form[action$="/profile/notifications"]');
        },
        init() {
            const root = this.getRoot();
            const details = Array.from(root.querySelectorAll('details[data-category]'));
            this.allExpanded = details.length > 0 && details.every(d => d.open);
            this.updateDirty();
            if (this.scopeTab !== 'all') {
                this.applyFilters();
            }
            const resetForm = document.getElementById('reset-preferences-form');
            if (resetForm) {
                resetForm.addEventListener('submit', () => {
                    this.isSubmitting = true;
                });
            }
            window.addEventListener('beforeunload', (e) => {
                if (this.dirtyCount > 0 && !this.isSubmitting) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        },
        summaryChipText() {
            const off = this.totalEvents - this.totalEnabled;
            return `${this.totalEnabled} of ${this.totalEvents} enabled · ${this.totalSilent} silent · ${off} off`;
        },
        onSwitchChange(key, el) {
            this.switches[key] = el.checked;
            const labelSpan = el.parentElement ? el.parentElement.querySelector('label span[aria-hidden]') : null;
            if (labelSpan) {
                labelSpan.textContent = el.checked ? 'On' : 'Off';
            }
            const note = el.closest('fieldset').querySelector('.action-required-note');
            if (note) {
                note.style.display = el.checked ? 'none' : 'inline-flex';
            }
            this.updateDirty();
        },
        onDeliveryChange(key, el) {
            this.delivery[key] = el.value;
            this.updateDirty();
        },
        updateDirty() {
            let count = 0;
            for (const key in this.initialSwitches) {
                if (Boolean(this.switches[key]) !== Boolean(this.initialSwitches[key])) {
                    count++;
                }
                if ((this.delivery[key] || 'normal') !== (this.initialDelivery[key] || 'normal')) {
                    count++;
                }
            }
            this.dirtyCount = count;
            this.updateCategoryStats();
            this.updateOverallStats();
            this.applyFilters();
        },
        updateOverallStats() {
            let enabled = 0;
            let silent = 0;
            for (const key in this.switches) {
                if (this.switches[key]) {
                    enabled++;
                    if (this.delivery[key] === 'silent') {
                        silent++;
                    }
                }
            }
            this.totalEnabled = enabled;
            this.totalSilent = silent;
        },
        updateCategoryStats() {
            const root = this.getRoot();
            root.querySelectorAll('details[data-category]').forEach(detail => {
                const cat = detail.getAttribute('data-category');
                const fieldsets = detail.querySelectorAll('fieldset[data-event-key]');
                let on = 0;
                fieldsets.forEach(fieldset => {
                    const key = fieldset.getAttribute('data-event-key');
                    if (this.switches[key]) on++;
                });
                if (this.categoryStats[cat]) {
                    this.categoryStats[cat].on = on;
                }
            });
        },
        categorySummaryText(el) {
            const detail = el ? el.closest('details[data-category]') : null;
            const cat = detail ? detail.getAttribute('data-category') : null;
            if (cat && this.categoryStats[cat]) {
                return this.categoryStats[cat].on + ' of ' + this.categoryStats[cat].total + ' on';
            }
            return '';
        },
        turnCategoryAll(target, turnOn) {
            const root = this.getRoot();
            const detail = (typeof target === 'string')
                ? root.querySelector('details[data-category="' + CSS.escape(target) + '"]')
                : (target ? target.closest('details') : null);
            if (!detail) return;
            const fieldsets = detail.querySelectorAll('fieldset[data-event-key]');
            fieldsets.forEach(fieldset => {
                const key = fieldset.getAttribute('data-event-key');
                const cb = fieldset.querySelector('input[type=checkbox][name^="notification_preferences"]');
                if (cb && key) {
                    cb.checked = turnOn;
                    this.switches[key] = turnOn;
                    const labelSpan = cb.parentElement ? cb.parentElement.querySelector('label span[aria-hidden]') : null;
                    if (labelSpan) {
                        labelSpan.textContent = turnOn ? 'On' : 'Off';
                    }
                    const note = fieldset.querySelector('.action-required-note');
                    if (note) {
                        note.style.display = turnOn ? 'none' : 'inline-flex';
                    }
                }
            });
            this.updateDirty();
        },
        toggleAllCategories() {
            const root = this.getRoot();
            const details = Array.from(root.querySelectorAll('details[data-category]'));
            const anyClosed = details.some(d => !d.open);
            details.forEach(d => { d.open = anyClosed; });
            this.allExpanded = anyClosed;
        },
        applyFilters() {
            const root = this.getRoot();
            const query = this.searchQuery.trim().toLowerCase();
            const rows = root.querySelectorAll('fieldset[data-event-key]');
            let count = 0;

            rows.forEach(row => {
                const key = row.getAttribute('data-event-key');
                const label = (row.getAttribute('data-event-label') || '').toLowerCase();
                const desc = (row.getAttribute('data-event-desc') || '').toLowerCase();
                const scope = row.getAttribute('data-scope') || 'general';
                const isEnabled = Boolean(this.switches[key]);
                const isSilent = (this.delivery[key] === 'silent');

                const matchesSearch = !query || label.includes(query) || desc.includes(query);
                let matchesFilter = true;
                if (this.activeFilter === 'enabled') {
                    matchesFilter = isEnabled;
                } else if (this.activeFilter === 'muted') {
                    matchesFilter = !isEnabled;
                } else if (this.activeFilter === 'silent') {
                    matchesFilter = isEnabled && isSilent;
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

            root.querySelectorAll('details[data-category]').forEach(detail => {
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
            const root = this.getRoot();
            const rows = root.querySelectorAll('fieldset[data-event-key]');
            rows.forEach(row => {
                const key = row.getAttribute('data-event-key');
                const cb = row.querySelector('input[type=checkbox][name^="notification_preferences"]');
                const select = row.querySelector('select[name^="notification_delivery"]');
                if (!cb || !key) return;
                const isActionRequired = row.getAttribute('data-priority') === 'action_required';

                if (preset === 'quiet') {
                    cb.checked = true;
                    this.switches[key] = true;
                    const mode = isActionRequired ? 'normal' : 'silent';
                    this.delivery[key] = mode;
                    if (select) select.value = mode;
                } else {
                    const shouldCheck = (preset === 'everything') ? true : isActionRequired;
                    cb.checked = shouldCheck;
                    this.switches[key] = shouldCheck;
                }

                const labelSpan = cb.parentElement ? cb.parentElement.querySelector('label span[aria-hidden]') : null;
                if (labelSpan) {
                    labelSpan.textContent = cb.checked ? 'On' : 'Off';
                }
                const note = row.querySelector('.action-required-note');
                if (note) {
                    note.style.display = cb.checked ? 'none' : 'inline-flex';
                }
            });
            this.updateDirty();
        },
        discard() {
            const root = this.getRoot();
            for (const key in this.initialSwitches) {
                const checked = Boolean(this.initialSwitches[key]);
                this.switches[key] = checked;
                const cb = root.querySelector(`input[type=checkbox][name="notification_preferences[${key}]"]`);
                if (cb) {
                    cb.checked = checked;
                    const labelSpan = cb.parentElement ? cb.parentElement.querySelector('label span[aria-hidden]') : null;
                    if (labelSpan) {
                        labelSpan.textContent = checked ? 'On' : 'Off';
                    }
                    const note = cb.closest('fieldset').querySelector('.action-required-note');
                    if (note) {
                        note.style.display = checked ? 'none' : 'inline-flex';
                    }
                }
            }
            for (const key in this.initialDelivery) {
                const mode = this.initialDelivery[key] || 'normal';
                this.delivery[key] = mode;
                const select = root.querySelector(`select[name="notification_delivery[${key}]"]`);
                if (select) {
                    select.value = mode;
                }
            }
            this.updateDirty();
        }
    };
}
</script>
@endpush
@endsection
