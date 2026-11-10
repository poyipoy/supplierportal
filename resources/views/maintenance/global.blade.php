@extends('layouts.auth')

@section('title', __('maintenance.notice.page_title'))
@section('meta')<meta name="robots" content="noindex, nofollow">@endsection
{{-- Marks the brand panel heading as a paragraph so this page owns the only h1. --}}
@section('page-heading', __('maintenance.notice.heading'))

@section('content')
<div class="tw:text-left" data-maintenance="global">
    <h1 class="tw:sr-only">{{ __('maintenance.notice.heading') }}</h1>
    @include('maintenance._notices', ['notices' => $notices])

    <form method="POST" action="{{ route('logout') }}" class="tw:mt-5">
        @csrf
        <x-ui.button type="submit" variant="outline" class="tw:w-full tw:justify-center">
            <x-ui.icon name="log-out" size="sm" /> {{ __('maintenance.notice.sign_out') }}
        </x-ui.button>
    </form>
</div>
@endsection
