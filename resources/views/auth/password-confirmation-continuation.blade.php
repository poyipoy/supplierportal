@extends('layouts.auth')

@section('title', 'Continuing Secure Action - ADASI Supplier Portal')

@section('content')
    <div class="auth-logo text-center mb-4">
        <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="Logo ADASI" style="width:72px; height:auto; margin-bottom:1rem;">
        <h4 class="fw-bold text-dark mb-1">ADASI Supplier Portal</h4>
        <p>Continuing your protected action</p>
    </div>

    <div class="text-center">
        <p class="text-muted small mb-4">Your password was confirmed. Please wait while we continue.</p>

        <form id="password-confirmation-continuation" method="POST" action="{{ $action['url'] }}">
            @csrf
            @if ($action['method'] !== 'POST')
                <input type="hidden" name="_method" value="{{ $action['method'] }}">
            @endif
            @foreach ($action['inputs'] as $input)
                <input type="hidden" name="{{ $input['name'] }}" value="{{ $input['value'] }}">
            @endforeach

            <noscript>
                <button type="submit" class="btn btn-login w-100">Continue</button>
            </noscript>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('password-confirmation-continuation').submit();
        });
    </script>
@endsection
