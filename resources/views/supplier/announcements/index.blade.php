@extends('layouts.app')
@section('title', 'Information & Announcements - ADASI Portal')
@section('page-title', 'Information & Announcements')
@section('content')
<div class="tw-mx-auto tw-grid tw-w-full tw-max-w-5xl tw-gap-6">
    <x-ui.page-header title="Information & Announcements" description="Official updates published by the ADASI Purchasing team." eyebrow="Supplier Portal" />
    <x-ui.card title="Latest Announcements" padding="none">
            @forelse($announcements as $ann)
                <div class="p-4 border-bottom">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h5 class="mb-0 fw-bold"><a href="{{ route('supplier.announcements.show', $ann->id) }}" class="text-decoration-none text-primary">{{ $ann->title }}</a></h5>
                        <small class="text-muted">{{ $ann->published_at->format('d M Y, H:i') }}</small>
                    </div>
                    <div class="text-muted mb-3 tw-text-ui-sm">{{ Str::limit($ann->content, 200) }}</div>
                    <x-ui.button :href="route('supplier.announcements.show', $ann->id)" variant="ghost" size="sm">Read More<x-slot:trailing><i class="bi bi-arrow-right"></i></x-slot:trailing></x-ui.button>
                </div>
            @empty
                <x-empty-state icon="bi-info-circle" title="No announcements yet" />
            @endforelse
        @if($announcements->hasPages())<x-slot:footer>{{ $announcements->links() }}</x-slot:footer>@endif
    </x-ui.card>
</div>
@endsection
