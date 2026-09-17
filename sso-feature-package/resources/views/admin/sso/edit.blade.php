@extends('layouts.app')
@section('title', 'Edit SSO Connection - ADASI Portal')
@section('page-title', 'Edit SSO Connection')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header :title="$connection->name" description="Domain: {{ $connection->domain }}" eyebrow="Admin Security" />

    @include('admin.sso._form')
</div>
@endsection
