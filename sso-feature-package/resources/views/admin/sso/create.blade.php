@extends('layouts.app')
@section('title', 'Add SSO Connection - ADASI Portal')
@section('page-title', 'Add SSO Connection')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Add SSO Connection" description="Configure a new SAML or OIDC connection for a supplier's company identity provider." eyebrow="Admin Security" />

    @include('admin.sso._form')
</div>
@endsection
