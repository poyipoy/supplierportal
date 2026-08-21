@extends('layouts.app')
@section('title', 'Create Announcement - ADASI Portal')
@section('page-title', 'Create Announcement')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Create Announcement" description="Prepare a portal-wide notice and choose whether it is published immediately." eyebrow="Admin" />
    <x-ui.card title="Announcement Details" class="tw-w-full tw-max-w-4xl">
        <form action="{{ route('admin.announcements.store') }}" method="POST" class="tw-grid tw-gap-4">
            @csrf
            <x-ui.input name="title" label="Title" required />
            <x-ui.textarea name="content" label="Content" rows="10" required />
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_published" id="is_published" value="1" {{ old('is_published') ? 'checked' : '' }}>
                <label class="form-check-label" for="is_published">Publish now</label>
            </div>
            <div class="tw-flex tw-flex-wrap tw-justify-between tw-gap-3">
                <x-ui.button :href="route('admin.announcements.index')" variant="ghost">Cancel</x-ui.button>
                <x-ui.button type="submit"><x-ui.icon name="save" /> Save Announcement</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
@endsection
