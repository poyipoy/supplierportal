@extends('layouts.app')

@section('title', $announcement->title . ' - ADASI Portal')
@section('page-title', __('supplier.copy.announcement_details'))

@section('content')
<div class="tw-mx-auto tw-grid tw-w-full tw-max-w-4xl tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.announcements') => route('supplier.announcements.index'),
        $announcement->title => null,
    ]" />

    <x-ui.page-header
        :title="$announcement->title"
        :eyebrow="__('supplier.copy.adasi_announcement')"
        :description="__('supplier.page.published', ['date' => $regionalFormatter->timestamp($announcement->published_at, 'datetime_comma')])"
    >
        <x-slot:actions>
            <x-ui.button :href="route('supplier.announcements.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>{{ __('supplier.copy.back_to_announcements') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card>
        <article class="p-2 tw-text-on-surface tw-text-ui-sm leading-relaxed tw-whitespace-pre-line">
            {{ $announcement->content }}
        </article>
        <x-slot:footer>
            <div class="tw-text-on-surface-variant tw-text-ui-xs text-center">
                {{ __('supplier.copy.official_announcement_distributed_to_authorized_suppliers_by_pt_astra_daido_steel_indonesia') }}
            </div>
        </x-slot:footer>
    </x-ui.card>
</div>
@endsection
