@extends('layouts.app')

@section('title', 'Information & Announcements - ADASI Portal')
@section('page-title', 'Announcements')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('supplier.dashboard'),
        'Announcements' => null,
    ]" />

    <x-ui.page-header
        title="Official Announcements"
        eyebrow="Supplier Communications"
        description="Official updates, schedule notices, and procurement guidelines published by the ADASI Purchasing team."
    />

    {{-- Announcements Feed Card --}}
    <x-ui.card title="Recent Announcements" padding="none">
        <div class="list-group list-group-flush">
            @forelse($announcements as $ann)
                <div class="list-group-item p-4 border-bottom">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                        <h6 class="mb-0 fw-bold tw-text-ui-base">
                            <a href="{{ route('supplier.announcements.show', $ann->id) }}" class="tw-text-on-surface text-decoration-none hover:tw-text-primary">
                                {{ $ann->title }}
                            </a>
                        </h6>
                        <div class="tw-text-on-surface-variant tw-text-ui-xs d-flex align-items-center gap-1 flex-shrink-0 ui-tabular-nums">
                            <x-ui.icon name="clock" size="sm" />
                            <span>{{ $ann->published_at->format('d M Y, H:i') }}</span>
                        </div>
                    </div>
                    <div class="tw-text-on-surface-variant tw-text-ui-xs mb-3 leading-relaxed">
                        {{ Str::limit($ann->content, 220) }}
                    </div>
                        <x-ui.button :href="route('supplier.announcements.show', $ann->id)" variant="ghost" size="sm">
                        <span>Read Full Announcement</span>
                            <x-ui.icon name="arrow-right" size="sm" />
                    </x-ui.button>
                </div>
            @empty
                <div class="p-5 text-center tw-text-outline tw-text-ui-sm">
                    No announcements published yet.
                </div>
            @endforelse
        </div>

        @if($announcements->hasPages())
            <x-slot:footer>
                <div class="tw-flex tw-justify-center">
                    {{ $announcements->links('pagination::bootstrap-5') }}
                </div>
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
@endsection
