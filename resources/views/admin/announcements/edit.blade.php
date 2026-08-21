@extends('layouts.app')
@section('title', 'Edit Announcement - ADASI Portal')
@section('page-title', 'Edit Announcement')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-24">
    <x-ui.breadcrumb :items="['Announcements' => route('admin.announcements.index'), $announcement->title => null]" />

    <x-ui.page-header
        title="Edit Announcement"
        description="Update the notice content and publication state."
        eyebrow="Admin Content"
    >
        <x-slot:meta>
            <x-ui.status-chip :tone="$announcement->published_at ? 'success' : 'neutral'">
                {{ $announcement->published_at ? 'Published' : 'Draft' }}
            </x-ui.status-chip>
        </x-slot:meta>
        <x-slot:actions>
            <x-ui.button :href="route('admin.announcements.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" /> Back to Announcements
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form action="{{ route('admin.announcements.update', $announcement->id) }}" method="POST" id="announcementEditForm">
        @csrf
        @method('PUT')

        <x-ui.form-section
            title="Announcement Content and Publication"
            description="Revisions to published announcements will take effect immediately upon saving."
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
                        value="{{ old('title', $announcement->title) }}"
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
                        required
                    >{{ old('content', $announcement->content) }}</textarea>
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
                            {{ old('is_published', $announcement->published_at) ? 'checked' : '' }}
                        >
                        <label class="form-check-label tw-text-ui-sm tw-font-medium tw-text-on-surface" for="is_published">
                            Published (Visible on user dashboards)
                        </label>
                    </div>
                </div>
            </div>
        </x-ui.form-section>

        {{-- Sticky Action Bar --}}
        <x-ui.action-bar>
            <x-slot:left>
                <span class="tw-text-ui-xs tw-text-on-surface-variant">
                    Created by {{ $announcement->creator->name ?? 'Admin' }} on {{ $announcement->created_at->format('d M Y, H:i') }}
                </span>
            </x-slot:left>
            <x-slot:right>
                <x-ui.button :href="route('admin.announcements.index')" variant="ghost">
                    Cancel
                </x-ui.button>
                <x-ui.button type="submit">
                    <x-ui.icon name="check" size="sm" />
                    Update Announcement
                </x-ui.button>
            </x-slot:right>
        </x-ui.action-bar>
    </form>
</div>
@endsection
