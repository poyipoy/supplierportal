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
        <form
            method="POST"
            action="{{ route('profile.notifications.update') }}"
            class="tw-grid tw-gap-5"
            x-data="{
                dirtyCount: 0,
                isSubmitting: false,
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

            @foreach(collect($events)->groupBy('category', preserveKeys: true) as $category => $categoryEvents)
                <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="notification-category-{{ $loop->index }}">
                    <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
                        <h2 id="notification-category-{{ $loop->index }}" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ $category }}</h2>
                    </header>
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
                </section>
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

    <form method="POST" action="{{ route('profile.notifications.reset') }}">
        @csrf
        @method('DELETE')
        <x-ui.button type="submit" variant="outlined">Reset to defaults</x-ui.button>
    </form>
</div>
@endsection
