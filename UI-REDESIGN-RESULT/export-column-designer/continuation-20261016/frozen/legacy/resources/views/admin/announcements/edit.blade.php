@extends('layouts.app')
@section('title', __('admin.copy.edit_announcement_adasi_portal'))
@section('page-title', __('admin.copy.edit_announcement'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-24">
    <x-ui.breadcrumb :items="[__('purchasing.breadcrumbs.announcements') => route('admin.announcements.index'), $announcement->title => null]" />

    <x-ui.page-header
        :title="__('admin.copy.edit_announcement')"
        :description="__('admin.copy.update_the_notice_content_and_publication_state')"
        :eyebrow="__('admin.copy.admin_content')"
    >
        <x-slot:meta>
            <x-ui.status-chip :tone="$announcement->published_at ? 'success' : 'neutral'">
                {{ $announcement->published_at ? __('admin.copy.published') : __('admin.copy.draft') }}
            </x-ui.status-chip>
        </x-slot:meta>
        <x-slot:actions>
            <x-ui.button :href="route('admin.announcements.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" /> {{ __('admin.copy.back_to_announcements') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form action="{{ route('admin.announcements.update', $announcement->id) }}" method="POST" id="announcementEditForm">
        @csrf
        @method('PUT')

        <x-ui.form-section
            :title="__('admin.copy.announcement_content_and_publication')"
            :description="__('admin.copy.revisions_to_published_announcements_will_take_effect_immediately_upon_saving')"
        >
            <div class="tw-grid tw-gap-4">
                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="annTitle">
                        {{ __('admin.copy.notice_title') }} <span class="text-danger">*</span>
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
                        {{ __('admin.copy.announcement_body_description') }} <span class="text-danger">*</span>
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
                            {{ __('admin.copy.published_visible_on_user_dashboards') }}
                        </label>
                    </div>
                </div>
            </div>
        </x-ui.form-section>

        {{-- Sticky Action Bar --}}
        <x-ui.action-bar>
            <x-slot:left>
                <span class="tw-text-ui-xs tw-text-on-surface-variant">
                    {{ __('common.final_review.created_by_date', ['name' => $announcement->creator->name ?? __('admin.copy.admin'), 'date' => $regionalFormatter->timestamp($announcement->created_at, 'datetime_comma')]) }}
                </span>
            </x-slot:left>
            <x-slot:right>
                <x-ui.button :href="route('admin.announcements.index')" variant="ghost">
                    {{ __('admin.copy.cancel') }}
                </x-ui.button>
                <x-ui.button type="submit">
                    <x-ui.icon name="check" size="sm" />
                    {{ __('admin.copy.update_announcement') }}
                </x-ui.button>
            </x-slot:right>
        </x-ui.action-bar>
    </form>
</div>
@endsection
