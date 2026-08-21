@extends('layouts.app')
@section('title', 'Create Announcement - ADASI Portal')
@section('page-title', 'Create Announcement')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-24">
    <x-ui.page-header
        title="Create Announcement"
        description="Prepare a portal-wide notice and choose its initial publication state."
        eyebrow="Admin Content"
    >
        <x-slot:actions>
            <x-ui.button :href="route('admin.announcements.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" /> Back to Announcements
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form action="{{ route('admin.announcements.store') }}" method="POST" id="announcementCreateForm">
        @csrf

        <x-ui.form-section
            title="Announcement Content"
            description="Keep the title specific and the body operationally useful."
        >
            <div class="tw-grid tw-gap-4">
                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="annTitle">
                        Notice Title <span class="text-danger">*</span>
                    </label>
                    <input
                        type="text"
                        name="title"
                        id="annTitle"
                        class="form-control @error('title') is-invalid @enderror"
                        value="{{ old('title') }}"
                        placeholder="e.g. Scheduled System Maintenance Notice"
                        required
                    >
                    @error('title')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="annContent">
                        Announcement Body / Description <span class="text-danger">*</span>
                    </label>
                    <textarea
                        name="content"
                        id="annContent"
                        class="form-control @error('content') is-invalid @enderror"
                        rows="8"
                        placeholder="Enter full details of the notice..."
                        required
                    >{{ old('content') }}</textarea>
                    @error('content')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="tw-pt-2">
                    <div class="form-check form-switch">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="is_published"
                            id="is_published"
                            value="1"
                            {{ old('is_published', true) ? 'checked' : '' }}
                        >
                        <label class="form-check-label tw-text-ui-sm tw-font-medium tw-text-on-surface" for="is_published">
                            Publish notice immediately (Broadcast to all active users)
                        </label>
                    </div>
                </div>
            </div>
        </x-ui.form-section>

        {{-- Sticky Action Bar --}}
        <x-ui.action-bar>
            <x-slot:right>
                <x-ui.button :href="route('admin.announcements.index')" variant="ghost">
                    Cancel
                </x-ui.button>
                <x-ui.button type="submit">
                    <x-ui.icon name="check" size="sm" />
                    Save Announcement
                </x-ui.button>
            </x-slot:right>
        </x-ui.action-bar>
    </form>
</div>
@endsection
