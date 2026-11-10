@extends('layouts.app')
@section('title', __('maintenance.page.browser_title'))
@section('page-title', __('maintenance.page.create_title'))

@section('content')
<div class="tw:grid tw:gap-6">
    <x-ui.page-header :title="__('maintenance.page.create_title')" :description="__('maintenance.page.create_description')" :eyebrow="__('maintenance.page.eyebrow')">
        <x-slot:actions>
            <x-ui.button :href="route('admin.maintenance.index')" variant="ghost" size="sm"><x-ui.icon name="arrow-left" size="sm" /> {{ __('maintenance.page.back') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('admin.maintenance._form', [
        'action' => route('admin.maintenance.store'),
        'method' => 'POST',
        'cancelUrl' => route('admin.maintenance.index'),
    ])
</div>
@endsection
