@extends('layouts.auth')

@section('title', __('auth.sso.error_title'))

@section('content')
<h2 class="tw:m-0 tw:text-ui-xl tw:font-bold tw:text-on-surface">{{ __('auth.sso.error_title') }}</h2>
<p role="alert" class="tw:mt-3 tw:text-ui-sm tw:text-on-surface-variant">{{ $message }}</p>
<a class="ui-focus-ring tw:text-ui-sm tw:font-semibold tw:text-primary" href="{{ route('login') }}">{{ __('auth.sso.back_to_login') }}</a>
@endsection
