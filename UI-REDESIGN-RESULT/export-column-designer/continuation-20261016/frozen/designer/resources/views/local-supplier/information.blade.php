@extends('layouts.app')
@section('title', __('local_procurement.closure.information_title'))
@section('page-title', __('local_procurement.closure.information_title'))
@section('content')
<x-ui.page-header :title="__('navigation.adasi_information')" :description="__('local_procurement.review.info_help')" />
@forelse($announcements as $announcement)
    <x-ui.card :title="$announcement->title">
        <p class="tw-m-0 tw-text-ui-sm">{{ $announcement->content }}</p>
    </x-ui.card>
@empty
    <x-ui.card>
        <p class="tw-m-0 tw-text-on-surface-variant">{{ __('local_procurement.review.info_empty') }}</p>
    </x-ui.card>
@endforelse
{{ $announcements->links() }}
@endsection
