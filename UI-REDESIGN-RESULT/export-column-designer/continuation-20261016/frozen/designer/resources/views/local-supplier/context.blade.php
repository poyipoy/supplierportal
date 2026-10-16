@extends('layouts.app')
@section('title', __('navigation.context.title'))
@section('page-title', __('navigation.context.title'))
@section('content')
<x-ui.page-header :title="__('navigation.context.title')" :description="__('navigation.context.help')" />
<div class="row g-3">
    @forelse($scopes as $scope)
        @php
            $isImport = $scope === 'import';
            $label = $isImport ? __('navigation.material_procurement') : __('navigation.local_supplier');
            $description = $isImport ? __('navigation.portal_descriptions.import') : __('navigation.portal_descriptions.local');
            $icon = $isImport ? 'boxes' : 'receipt';
            $isActive = isset($currentContext) && $currentContext === $scope;
        @endphp
        <div class="col-md-6 col-lg-5">
            <x-ui.card class="h-100 {{ $isActive ? 'tw-border-primary tw-ring-1 tw-ring-primary' : '' }}">
                <div class="d-flex flex-column h-100 justify-content-between tw-gap-4">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div class="d-flex align-items-center tw-gap-2">
                                <span class="tw-inline-flex tw-items-center tw-justify-center tw-w-9 tw-h-9 tw-rounded-lg tw-bg-primary-container tw-text-on-primary-container">
                                    <x-ui.icon :name="$icon" size="sm" />
                                </span>
                                <h3 class="tw-text-ui-base tw-font-semibold tw-text-on-surface m-0">{{ $label }}</h3>
                            </div>
                            @if($isActive)
                                <span class="badge tw-bg-primary-container tw-text-on-primary-container d-inline-flex align-items-center tw-gap-1 px-2 py-1">
                                    <x-ui.icon name="check" size="xs" /> {{ __('local_invoice.labels.active') }}
                                </span>
                            @endif
                        </div>
                        <p class="tw-text-ui-sm tw-text-on-surface-variant m-0">{{ $description }}</p>
                    </div>
                    <form method="POST" action="{{ route('supplier-context.store') }}" class="m-0">
                        @csrf
                        <input type="hidden" name="context" value="{{ $scope }}">
                        <x-ui.button type="submit" variant="{{ $isActive ? 'secondary' : 'primary' }}" class="w-100">
                            {{ __($isActive ? 'navigation.context.stay' : 'navigation.context.enter', ['portal' => $label]) }}
                        </x-ui.button>
                    </form>
                </div>
            </x-ui.card>
        </div>
    @empty
        <div class="col-12">
            <x-ui.card>
                <p class="tw-text-on-surface-variant m-0">{{ __('navigation.context.unconfigured') }}</p>
            </x-ui.card>
        </div>
    @endforelse
</div>
@endsection
