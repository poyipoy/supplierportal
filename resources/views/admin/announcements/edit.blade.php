@extends('layouts.app')
@section('title', 'Edit Announcement - ADASI Portal')
@section('page-title', 'Edit Announcement')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Edit Announcement" description="Update the notice content or its publication state." eyebrow="Admin" />
    <x-ui.card title="Announcement Details" class="tw-w-full tw-max-w-4xl">
        <form action="{{ route('admin.announcements.update', $announcement->id) }}" method="POST" class="tw-grid tw-gap-4">
            @csrf @method('PUT')
            <x-ui.input name="title" label="Title" :value="$announcement->title" required />
            <x-ui.textarea name="content" label="Content" :value="$announcement->content" rows="10" required />
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_published" id="is_published" value="1" {{ old('is_published', $announcement->published_at) ? 'checked' : '' }}>
                <label class="form-check-label" for="is_published">Published</label>
            </div>
            <div class="tw-flex tw-flex-wrap tw-justify-between tw-gap-3">
                <x-ui.button :href="route('admin.announcements.index')" variant="ghost">Cancel</x-ui.button>
                <x-ui.button type="submit"><x-slot:leading><i class="bi bi-save"></i></x-slot:leading>Update Announcement</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
@endsection
