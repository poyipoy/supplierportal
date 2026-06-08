@extends('layouts.app')
@section('title', 'Information & Announcements - ADASI Portal')
@section('page-title', 'Information & Announcements')
@section('content')
<div class="row justify-content-center"><div class="col-lg-10">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><h5 class="mb-0 fw-bold">Latest Announcements</h5></div>
        <div class="card-body p-0">
            @forelse($announcements as $ann)
                <div class="p-4 border-bottom">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h5 class="mb-0 fw-bold"><a href="{{ route('supplier.announcements.show', $ann->id) }}" class="text-decoration-none text-primary">{{ $ann->title }}</a></h5>
                        <small class="text-muted">{{ $ann->published_at->format('d M Y, H:i') }}</small>
                    </div>
                    <div class="text-muted mb-3" style="font-size:.9rem">{{ Str::limit($ann->content, 200) }}</div>
                    <a href="{{ route('supplier.announcements.show', $ann->id) }}" class="btn btn-sm btn-link p-0 text-decoration-none fw-bold">Read More <i class="bi bi-arrow-right ms-1"></i></a>
                </div>
            @empty
                <x-empty-state icon="bi-info-circle" title="No announcements yet" />
            @endforelse
        </div>
        @if($announcements->hasPages())<div class="card-footer bg-white py-3">{{ $announcements->links() }}</div>@endif
    </div>
</div></div>
@endsection
