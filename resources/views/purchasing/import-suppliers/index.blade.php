@extends('layouts.app')

@section('title', __('supplier_contact.directory.title'))
@section('page-title', __('supplier_contact.directory.title'))

@section('content')
<div id="importSupplierQueueContainer" class="tw:grid tw:gap-5 tw:pb-16" data-server-tabs-container>
    <x-ui.page-header
        :title="__('supplier_contact.directory.title')"
        :description="__('supplier_contact.directory.description')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('purchasing.import-suppliers.create')" variant="primary" size="sm">
                <x-ui.icon name="plus" size="sm" />
                <span>{{ __('supplier_contact.register.cta') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <nav aria-label="{{ __('supplier_contact.directory.filter_label') }}" class="tw:flex tw:items-center tw:gap-2 tw:overflow-x-auto tw:pb-1" data-server-tabs-nav>
        @include('purchasing.import-suppliers._queue_nav')
    </nav>

    <div id="importSupplierQueueContent" data-server-tabs-content>
        @include('purchasing.import-suppliers._queue_content')
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        // Counts change when suppliers submit or another reviewer decides, so cached fragments stay short-lived.
        const options = { cacheTtlMs: 30000 };
        const start = () => AdasiServerTabs.init('#importSupplierQueueContainer', options);
        if (typeof AdasiServerTabs !== 'undefined') {
            start();
        } else {
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof AdasiServerTabs !== 'undefined') {
                    start();
                }
            });
        }
    })();
</script>
@endpush
