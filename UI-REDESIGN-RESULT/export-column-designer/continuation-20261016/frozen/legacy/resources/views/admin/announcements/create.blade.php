@extends('layouts.app')
@section('title', __('admin.copy.create_announcement_adasi_portal'))
@section('page-title', __('admin.copy.create_announcement'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-24">
    <x-ui.page-header
        :title="__('admin.copy.create_announcement')"
        :description="__('admin.copy.prepare_a_portal_wide_notice_and_choose_its_initial_publication_state')"
        :eyebrow="__('admin.copy.admin_content')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('admin.announcements.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" /> {{ __('admin.copy.back_to_announcements') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form action="{{ route('admin.announcements.store') }}" method="POST" id="announcementCreateForm">
        @csrf

        <x-ui.form-section
            :title="__('admin.copy.announcement_content')"
            :description="__('admin.copy.keep_the_title_specific_and_the_body_operationally_useful')"
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
                        value="{{ old('title') }}"
                        placeholder="{{ __('admin.copy.e_g_scheduled_system_maintenance_notice') }}"
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
                        placeholder="{{ __('admin.copy.enter_full_details_of_the_notice') }}"
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
                            {{ __('admin.copy.publish_notice_immediately_broadcast_to_all_active_users') }}
                        </label>
                    </div>
                </div>
            </div>
        </x-ui.form-section>

        {{-- Sticky Action Bar --}}
        <x-ui.action-bar>
            <x-slot:right>
                <x-ui.button :href="route('admin.announcements.index')" variant="ghost">
                    {{ __('admin.copy.cancel') }}
                </x-ui.button>
                <x-ui.button type="submit">
                    <x-ui.icon name="check" size="sm" />
                    {{ __('admin.copy.save_announcement') }}
                </x-ui.button>
            </x-slot:right>
        </x-ui.action-bar>
    </form>
</div>
@endsection
