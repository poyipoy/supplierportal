@extends('layouts.app')
@section('title', $announcement->title . ' - ADASI Portal')
@section('page-title', 'Announcement Details')
@section('content')
<div class="tw-mx-auto tw-grid tw-w-full tw-max-w-4xl tw-gap-6">
    <x-ui.page-header :title="$announcement->title" :description="$announcement->published_at->format('d F Y, H:i')" eyebrow="ADASI Announcement">
        <x-slot:actions><x-ui.button :href="route('supplier.announcements.index')" variant="ghost" size="sm"><x-slot:leading><i class="bi bi-arrow-left"></i></x-slot:leading>Back</x-ui.button></x-slot:actions>
    </x-ui.page-header>
    <x-ui.card>
        <article class="tw-whitespace-pre-line tw-text-ui-base tw-leading-8 tw-text-on-surface">{{ $announcement->content }}</article>
        <x-slot:footer><div class="tw-text-center tw-text-ui-sm tw-text-on-surface-variant">Published by the ADASI Purchasing team</div></x-slot:footer>
    </x-ui.card>
</div>
@endsection
