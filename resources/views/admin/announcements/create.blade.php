@extends('layouts.app')
@section('title', 'Create Announcement - ADASI Portal')
@section('page-title', 'Create Announcement Baru')
@section('content')
<div class="row justify-content-center"><div class="col-lg-8"><div class="card border-0 shadow-sm"><div class="card-body">
    <form action="{{ route('admin.announcements.store') }}" method="POST">@csrf
        <div class="mb-3"><label for="title" class="form-label fw-bold">Title</label><input type="text" name="title" id="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title') }}" required>@error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="mb-3"><label for="content" class="form-label fw-bold">Content</label><textarea name="content" id="content" rows="10" class="form-control @error('content') is-invalid @enderror" required>{{ old('content') }}</textarea>@error('content')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="mb-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_published" id="is_published" value="1" {{ old('is_published') ? 'checked' : '' }}><label class="form-check-label" for="is_published">Publish Now</label></div></div>
        <div class="d-flex justify-content-between"><a href="{{ route('admin.announcements.index') }}" class="btn btn-light">Cancel</a><button type="submit" class="btn btn-primary px-4">Save</button></div>
    </form>
</div></div></div></div>
@endsection
