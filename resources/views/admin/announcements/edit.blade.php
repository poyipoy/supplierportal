@extends('layouts.app')
@section('title', 'Edit Announcement - ADASI Portal')
@section('page-title', 'Edit Announcement')
@section('content')
<div class="row justify-content-center"><div class="col-lg-8"><div class="card border-0 shadow-sm"><div class="card-body">
    <form action="{{ route('admin.announcements.update', $announcement->id) }}" method="POST">@csrf @method('PUT')
        <div class="mb-3"><label for="title" class="form-label fw-bold">Judul</label><input type="text" name="title" id="title" class="form-control" value="{{ old('title', $announcement->title) }}" required></div>
        <div class="mb-3"><label for="content" class="form-label fw-bold">Konten</label><textarea name="content" id="content" rows="10" class="form-control" required>{{ old('content', $announcement->content) }}</textarea></div>
        <div class="mb-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_published" id="is_published" value="1" {{ old('is_published', $announcement->published_at) ? 'checked' : '' }}><label class="form-check-label" for="is_published">Published</label></div></div>
        <div class="d-flex justify-content-between"><a href="{{ route('admin.announcements.index') }}" class="btn btn-light">Cancel</a><button type="submit" class="btn btn-primary px-4">Update</button></div>
    </form>
</div></div></div></div>
@endsection
