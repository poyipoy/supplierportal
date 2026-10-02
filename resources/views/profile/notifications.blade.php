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
        <form method="POST" action="{{ route('profile.notifications.update') }}" class="tw-grid tw-gap-5">
            @csrf
            @method('PATCH')

            @foreach(collect($events)->groupBy('category', preserveKeys: true) as $category => $categoryEvents)
                <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="notification-category-{{ $loop->index }}">
                    <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
                        <h2 id="notification-category-{{ $loop->index }}" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ $category }}</h2>
                    </header>
                    <div class="tw-grid tw-gap-5 tw-p-5">
                        @foreach($categoryEvents as $key => $event)
                            <fieldset class="tw-grid tw-min-w-0 tw-gap-2">
                                <legend class="tw-text-ui-sm tw-font-semibold">{{ $event['label'] }}</legend>
                                <p id="notification-{{ $key }}-help" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ $event['description'] }}</p>
                                        @php
                                            $field = 'notification_preferences.'.$key;
                                            $controlId = 'notification-'.$key;
                                        @endphp
                                        <input type="hidden" name="notification_preferences[{{ $key }}]" value="0">
                                        <label for="{{ $controlId }}" class="ui-preference-option ui-focus-ring tw-flex tw-min-h-11 tw-cursor-pointer tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-px-3 tw-text-ui-sm hover:tw-bg-surface-container">
                                            <input
                                                id="{{ $controlId }}"
                                                class="tw-h-4 tw-w-4 tw-accent-primary"
                                                type="checkbox"
                                                name="notification_preferences[{{ $key }}]"
                                                value="1"
                                                @checked((bool) old($field, $effectivePreferences[$key]))
                                                aria-describedby="notification-{{ $key }}-help @error($field) {{ $controlId }}-error @enderror"
                                                aria-invalid="{{ $errors->has($field) ? 'true' : 'false' }}"
                                            >
                                            <span>{{ $event['label'] }}</span>
                                        </label>
                                        @error($field)<p id="{{ $controlId }}-error" class="tw-m-0 tw-text-ui-xs tw-text-error">{{ $message }}</p>@enderror
                            </fieldset>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-3">
                <x-ui.button type="submit">Save Changes</x-ui.button>
            </div>
        </form>
    @endif

    <form method="POST" action="{{ route('profile.notifications.reset') }}">
        @csrf
        @method('DELETE')
        <x-ui.button type="submit" variant="outlined">Reset to defaults</x-ui.button>
    </form>
</div>
@endsection
