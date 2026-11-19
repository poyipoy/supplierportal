@extends('layouts.auth')

@section('plain-layout', true)
@section('title', __('auth.sso.error_title'))

@section('content')
<h1 class="tw:m-0 tw:text-ui-xl tw:font-bold tw:text-on-surface">{{ __('auth.sso.error_title') }}</h1>
<p role="alert" class="tw:mt-3 tw:text-ui-sm tw:text-on-surface-variant">{{ $message }}</p>
<a class="ui-focus-ring tw:mt-4 tw:inline-flex tw:min-h-[28px] tw:items-center tw:text-ui-sm tw:font-semibold tw:text-primary" href="{{ route('login') }}">{{ __('auth.sso.back_to_login') }}</a>
@endsection
