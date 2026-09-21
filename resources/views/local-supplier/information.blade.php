@extends('layouts.app')
@section('title', 'Informasi ADASI')
@section('page-title', 'Informasi ADASI')
@section('content')
<x-ui.page-header title="Informasi ADASI" description="Pengumuman dan pemberitahuan resmi untuk rekanan/supplier." />
@forelse($announcements as $announcement)
    <x-ui.card :title="$announcement->title">
        <p class="tw-m-0 tw-text-ui-sm">{{ $announcement->content }}</p>
    </x-ui.card>
@empty
    <x-ui.card>
        <p class="tw-m-0 tw-text-on-surface-variant">Tidak ada pengumuman yang tersedia saat ini.</p>
    </x-ui.card>
@endforelse
{{ $announcements->links() }}
@endsection
