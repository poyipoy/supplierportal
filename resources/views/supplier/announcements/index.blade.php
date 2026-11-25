@extends('layouts.app')

@section('title', __('supplier.copy.information_announcements_adasi_portal'))
@section('page-title', __('supplier.copy.announcements'))

@section('content')
<div class="tw:grid tw:gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.announcements') => null,
    ]" />

    <x-ui.page-header
        :title="__('supplier.copy.official_announcements')"
        :eyebrow="__('supplier.copy.supplier_communications')"
        :description="__('supplier.copy.official_updates_schedule_notices_and_procurement_guidelines_published_by_the_adasi_purchasing_team')"
    />

    {{-- Announcements Feed Card --}}
    <x-ui.card :title="__('supplier.copy.recent_announcements')" padding="none">
        <div class="tw:divide-y tw:divide-outline-variant">
            @forelse($announcements as $ann)
                <div class="tw:p-4">
                    <div class="tw:flex tw:justify-between tw:items-start tw:gap-3 tw:mb-2">
                        <h6 class="tw:m-0 tw:font-bold tw:text-ui-base">
                            <a href="{{ route('supplier.announcements.show', $ann->id) }}" class="tw:text-on-surface tw:no-underline tw:hover:text-primary">
                                {{ $ann->title }}
                            </a>
                        </h6>
                        <div class="tw:text-on-surface-variant tw:text-ui-xs tw:flex tw:items-center tw:gap-1 tw:shrink-0 ui-tabular-nums">
                            <x-ui.icon name="clock" size="sm" />
                            <span>{{ $regionalFormatter->timestamp($ann->published_at, 'datetime_comma') }}</span>
                        </div>
                    </div>
                    <div class="tw:text-on-surface-variant tw:text-ui-xs tw:mb-3 tw:leading-relaxed">
                        {{ Str::limit($ann->content, 220) }}
                    </div>
                    <x-ui.button :href="route('supplier.announcements.show', $ann->id)" variant="outline" size="sm">
                        <span>{{ __('supplier.copy.read_full_announcement') }}</span>
                        <x-ui.icon name="arrow-right" size="sm" />
                    </x-ui.button>
                </div>
            @empty
                <div class="tw:p-5 tw:text-center tw:text-on-surface-variant tw:text-ui-sm">
                    {{ __('supplier.copy.no_announcements_published_yet') }}
                </div>
            @endforelse
        </div>

        @if($announcements->hasPages())
            <x-slot:footer>
                <div class="tw:flex tw:justify-center">
                    {{ $announcements->links('pagination::bootstrap-5') }}
                </div>
            </x-slot:footer>
        @endif
    </x-ui.card>
</div>
@endsection
