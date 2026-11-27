@extends('layouts.app')

@section('title', ($initialTitle ?? 'ADASI Portal').' - ADASI Portal')
@section('page-title', $initialTitle ?? 'Dashboard')

@php
    $isWorkspaceShell = true;
    $isShortcutsInitial = ($initialMode ?? 'frame') === 'shortcuts' || empty($initialEmbedUrl);
@endphp

@section('content')
    @include('partials.workspace-shortcuts-panel', [
        'isShortcutsInitial' => $isShortcutsInitial,
        'initialEmbedUrl' => $initialEmbedUrl ?? null,
        'initialTitle' => $initialTitle ?? 'ADASI Portal',
    ])
@endsection
