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
                categoryStats: @js($initialCategoryStats),
                init() {
                    this.updateDirty();
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
                    });
                    this.updateDirty();
                },
                toggleAllCategories() {
                    const details = Array.from(this.$el.querySelectorAll('details[data-category]'));
                    const anyClosed = details.some(d => !d.open);
                    details.forEach(d => { d.open = anyClosed; });
                    this.allExpanded = anyClosed;
                },
                discard() {
                    const checkboxes = this.$el.querySelectorAll('input[type=checkbox][name^=\"notification_preferences\"]');
                    checkboxes.forEach(cb => {
                        cb.checked = cb.defaultChecked;
                        const labelSpan = cb.parentElement ? cb.parentElement.querySelector('label span[aria-hidden]') : null;
                        if (labelSpan) {
                            labelSpan.textContent = cb.checked ? 'On' : 'Off';
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

            @if($categoryCount > 1)
                <div class="tw-flex tw-justify-end tw-items-center">
                    <x-ui.button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="toggleAllCategories()"
                    >
                        <span x-text="allExpanded ? 'Collapse all' : 'Expand all'">Expand all</span>
                    </x-ui.button>
                </div>
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
                    </summary>
                    <div class="tw-grid tw-gap-5 tw-p-5">
                        @foreach($categoryEvents as $key => $event)
                            @php
                                $field = 'notification_preferences.'.$key;
                                $controlId = 'notification-'.$key;
                                $isChecked = (bool) old($field, $effectivePreferences[$key]);
                            @endphp
                            <fieldset class="tw-grid tw-min-w-0 tw-gap-2 tw-py-3 tw-border-b tw-border-outline-variant last:tw-border-b-0">
                                <legend class="tw-text-ui-sm tw-font-semibold">{{ $event['label'] }}</legend>
                                <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3">
                                    <div class="tw-min-w-0 tw-flex-1">
                                        <p id="notification-{{ $key }}-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ $event['description'] }}</p>
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
                                            onchange="this.parentElement.querySelector('label span[aria-hidden]').textContent = this.checked ? 'On' : 'Off'"
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
