@extends('layouts.auth')

@section('title', __('auth.sso.consent_title'))

@section('content')
<h2 class="tw:m-0 tw:text-ui-xl tw:font-bold tw:text-on-surface">{{ __('auth.sso.consent_title') }}</h2>
<p class="tw:mt-3 tw:text-ui-sm tw:text-on-surface-variant">{{ __('auth.sso.consent_request', ['client' => $client->name]) }}</p>
<form method="POST" action="{{ route('passport.authorizations.approve') }}" class="tw:mt-4">
    @csrf
    <input type="hidden" name="auth_token" value="{{ $authToken }}">
    <x-ui.button type="submit">{{ __('auth.sso.allow') }}</x-ui.button>
</form>
<form method="POST" action="{{ route('passport.authorizations.deny') }}" class="tw:mt-3">
    @csrf
    @method('DELETE')
    <input type="hidden" name="auth_token" value="{{ $authToken }}">
    <x-ui.button type="submit" variant="secondary">{{ __('auth.sso.deny') }}</x-ui.button>
</form>
@endsection
