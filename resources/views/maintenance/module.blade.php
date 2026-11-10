@extends('layouts.app')
@section('title', __('maintenance.notice.page_title'))
@section('page-title', __('maintenance.notice.heading'))

@section('content')
<div class="tw:mx-auto tw:grid tw:w-full tw:max-w-3xl tw:gap-4" data-maintenance="module">
    <h1 class="tw:sr-only">{{ __('maintenance.notice.heading') }}</h1>
    @include('maintenance._notices', ['notices' => $notices])
</div>
@endsection
