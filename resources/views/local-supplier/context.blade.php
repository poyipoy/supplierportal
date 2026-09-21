@extends('layouts.app')
@section('title', 'Pilih Portal Supplier')
@section('page-title', 'Pilih Portal Supplier')
@section('content')
<x-ui.page-header title="Pilih Portal Supplier" description="Pilih konteks pengadaan untuk sesi kerja Anda." />
<x-ui.card>
    @forelse($scopes as $scope)
        <form method="POST" action="{{ route('supplier-context.store') }}" class="d-inline-block me-2">@csrf
            <input type="hidden" name="context" value="{{ $scope }}">
            <x-ui.button type="submit">{{ $scope === 'import' ? 'Pengadaan Impor' : 'Invoice Lokal' }}</x-ui.button>
        </form>
    @empty
        <p class="tw-text-on-surface-variant">Akses supplier belum dikonfigurasi. Silakan hubungi administrator.</p>
    @endforelse
</x-ui.card>
@endsection
