@extends('layouts.app')
@section('title', $announcement->title . ' — ADASI Portal')
@section('page-title', 'Detail Pengumuman')
@section('content')
<div class="row justify-content-center"><div class="col-lg-9">
    <div class="mb-3"><a href="{{ route('supplier.announcements.index') }}" class="text-decoration-none text-muted small"><i class="bi bi-arrow-left me-1"></i> Kembali</a></div>
    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="card-header bg-primary py-4 px-4 border-0">
            <div class="text-white-50 small mb-1">{{ $announcement->published_at->format('d F Y, H:i') }}</div>
            <h3 class="text-white fw-bold mb-0">{{ $announcement->title }}</h3>
        </div>
        <div class="card-body p-4 p-md-5">
            <div style="line-height:1.8;font-size:1.05rem">{!! nl2br(e($announcement->content)) !!}</div>
        </div>
        <div class="card-footer bg-light p-4 text-center border-0"><div class="text-muted small">Diterbitkan oleh Tim Purchasing ADASI</div></div>
    </div>
</div></div>
@endsection
