@extends('layouts.app')
@section('title', __('supplier_audit.title'))
@section('page-title', __('supplier_audit.title'))

@section('content')
<div id="supplierAuditQueueContainer" class="tw-grid tw-gap-5 tw-pb-16" data-server-tabs-container>
    <x-ui.page-header
        :title="__('supplier_audit.title')"
        :description="__('supplier_audit.purchasing_description')"
        :eyebrow="__('supplier_audit.eyebrow')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('purchasing.supplier-audits.create')" variant="primary" size="sm">
                <x-ui.icon name="plus" size="sm" />
                <span>{{ __('supplier_audit.actions.assign') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- U6: antrean kerja. Isi nav dirender server (lihat _queue_nav) dan ikut dikirim di setiap fragmen server tabs. --}}
    <nav aria-label="{{ __('supplier_audit.queues.label') }}" class="tw-overflow-x-auto" data-server-tabs-nav>
        @include('purchasing.supplier-audits._queue_nav')
    </nav>

    <div id="supplierAuditQueueContent" data-server-tabs-content>
        @include('purchasing.supplier-audits._queue_content')
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        // Hitungan antrean bisa berubah oleh Purchasing/supplier lain, jadi fragmen disimpan singkat saja.
        const options = { cacheTtlMs: 30000 };
        if (typeof AdasiServerTabs !== 'undefined') {
            AdasiServerTabs.init('#supplierAuditQueueContainer', options);
        } else {
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof AdasiServerTabs !== 'undefined') {
                    AdasiServerTabs.init('#supplierAuditQueueContainer', options);
                }
            });
        }
    })();
</script>
@endpush
