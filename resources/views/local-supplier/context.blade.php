@extends('layouts.app')
@section('title', __('navigation.context.title'))
@section('page-title', __('navigation.context.title'))
@section('content')
<x-ui.page-header :title="__('navigation.context.title')" :description="__('navigation.context.help')" />
<div class="tw:grid tw:grid-cols-12 tw:gap-4">
    @forelse($scopes as $scope)
        @php
            $isImport = $scope === 'import';
            $label = $isImport ? __('navigation.material_procurement') : __('navigation.local_supplier');
            $description = $isImport ? __('navigation.portal_descriptions.import') : __('navigation.portal_descriptions.local');
            $icon = $isImport ? 'boxes' : 'receipt';
            $isActive = isset($currentContext) && $currentContext === $scope;
        @endphp
        <div class="tw:min-w-0 tw:col-span-12 tw:md:col-span-6 tw:lg:col-span-5">
            <x-ui.card class="tw:h-full {{ $isActive ? 'tw:border-primary tw:ring-1 tw:ring-primary' : '' }}">
                <div class="tw:flex tw:flex-col tw:h-full tw:justify-between tw:gap-4">
                    <div>
                        <div class="tw:flex tw:items-center tw:justify-between tw:mb-2">
                            <div class="tw:flex tw:items-center tw:gap-2">
                                <span class="tw:inline-flex tw:items-center tw:justify-center tw:w-9 tw:h-9 tw:rounded-lg tw:bg-primary-container tw:text-on-primary-container">
                                    <x-ui.icon :name="$icon" size="sm" />
                                </span>
                                <h3 class="tw:text-ui-base tw:font-semibold tw:text-on-surface tw:m-0">{{ $label }}</h3>
                            </div>
                            @if($isActive)
                                <span class="tw:inline-flex tw:items-center tw:gap-1 tw:rounded-full tw:text-ui-xs tw:font-semibold tw:bg-primary-container tw:text-on-primary-container tw:px-2 tw:py-1">
                                    <x-ui.icon name="check" size="xs" /> {{ __('local_invoice.labels.active') }}
                                </span>
                            @endif
                        </div>
                        <p class="tw:text-ui-sm tw:text-on-surface-variant tw:m-0">{{ $description }}</p>
                    </div>
                    <form method="POST" action="{{ route('supplier-context.store') }}" class="tw:m-0">
                        @csrf
                        <input type="hidden" name="context" value="{{ $scope }}">
                        <x-ui.button type="submit" variant="{{ $isActive ? 'secondary' : 'primary' }}" class="tw:w-full">
                            {{ __($isActive ? 'navigation.context.stay' : 'navigation.context.enter', ['portal' => $label]) }}
                        </x-ui.button>
                    </form>
                </div>
            </x-ui.card>
        </div>
    @empty
        <div class="tw:min-w-0 tw:col-span-12">
            <x-ui.card>
                <p class="tw:text-on-surface-variant tw:m-0">{{ __('navigation.context.unconfigured') }}</p>
            </x-ui.card>
        </div>
    @endforelse
</div>
@endsection
