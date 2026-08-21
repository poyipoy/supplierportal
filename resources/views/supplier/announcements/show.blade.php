@extends('layouts.app')

@section('title', $announcement->title . ' - ADASI Portal')
@section('page-title', 'Announcement Details')

@section('content')
<div class="tw-mx-auto tw-grid tw-w-full tw-max-w-4xl tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('supplier.dashboard'),
        'Announcements' => route('supplier.announcements.index'),
        $announcement->title => null,
    ]" />

    <x-ui.page-header
        :title="$announcement->title"
        eyebrow="ADASI Announcement"
        :description="'Published by ADASI Purchasing on ' . $announcement->published_at->format('d F Y, H:i')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('supplier.announcements.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>Back to Announcements</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card>
        <article class="p-2 tw-text-on-surface tw-text-ui-sm leading-relaxed tw-whitespace-pre-line">
            {{ $announcement->content }}
        </article>
        <x-slot:footer>
            <div class="tw-text-on-surface-variant tw-text-ui-xs text-center">
                Official announcement distributed to authorized suppliers by PT. Astra Daido Steel Indonesia.
            </div>
        </x-slot:footer>
    </x-ui.card>
</div>
@endsection
