@extends('layouts.app')
@section('title', __('local_procurement.registration.list_title'))
@section('page-title', __('local_procurement.registration.list_heading'))

@section('content')
<div id="supplierRegistrationContainer" class="tw-grid tw-gap-6" data-server-tabs-container>
    <x-ui.page-header
        :title="__('navigation.quick.registrations')"
        :description="__('local_procurement.registration.list_description')"
        :eyebrow="__('local_procurement.registration.management')"
    />

    @if (session('success'))
        <div class="tw-rounded-ui-sm tw-bg-success/15 tw-p-3.5 tw-text-on-surface tw-flex tw-items-center tw-gap-2.5 tw-text-ui-sm tw-font-medium" role="alert">
            <x-ui.icon name="check-circle" size="sm" class="tw-text-success tw-shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="tw-rounded-ui-sm tw-bg-error-container tw-p-3.5 tw-text-on-error-container tw-flex tw-items-center tw-gap-2.5 tw-text-ui-sm tw-font-medium" role="alert">
            <x-ui.icon name="alert-circle" size="sm" class="tw-shrink-0" />
            <span>{{ session('error') }}</span>
        </div>
    @endif

    {{-- STATUS FILTER TABS: isi dirender server (_status_nav) dan ikut dikirim di setiap fragmen server tabs. --}}
    <div class="tw-flex tw-items-center tw-gap-2 tw-overflow-x-auto tw-pb-1" data-server-tabs-nav>
        @include('supplier-registrations._status_nav')
    </div>

    <div id="supplierRegistrationContent" class="tw-grid tw-gap-6" data-server-tabs-content>
        @include('supplier-registrations._attempts_content')
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        // Hitungan status bisa berubah oleh reviewer lain, jadi fragmen disimpan singkat saja.
        const options = { cacheTtlMs: 30000 };
        if (typeof AdasiServerTabs !== 'undefined') {
            AdasiServerTabs.init('#supplierRegistrationContainer', options);
        } else {
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof AdasiServerTabs !== 'undefined') {
                    AdasiServerTabs.init('#supplierRegistrationContainer', options);
                }
            });
        }
    })();
</script>
@endpush
