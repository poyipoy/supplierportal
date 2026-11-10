@extends('layouts.app')
@section('title', __('maintenance.page.browser_title'))
@section('page-title', __('maintenance.page.edit_title'))

@section('content')
@php $displayStatus = $record->displayStatus(); @endphp
<div class="tw:grid tw:gap-6">
    <x-ui.page-header :title="__('maintenance.page.edit_title')" :description="__('maintenance.page.edit_description')" :eyebrow="$record->title">
        <x-slot:status>
            <x-ui.status-chip :tone="\App\Support\StatusHelper::maintenanceTone($displayStatus)">{{ \App\Support\StatusHelper::maintenanceLabel($displayStatus) }}</x-ui.status-chip>
        </x-slot:status>
        <x-slot:actions>
            <x-ui.button :href="route('admin.maintenance.show', $record)" variant="ghost" size="sm"><x-ui.icon name="arrow-left" size="sm" /> {{ __('maintenance.page.back_to_record') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('admin.maintenance._form', [
        'action' => route('admin.maintenance.update', $record),
        'method' => 'PUT',
        'cancelUrl' => route('admin.maintenance.show', $record),
    ])
</div>
@endsection
