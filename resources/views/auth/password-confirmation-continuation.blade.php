@extends('layouts.auth')

@section('title', 'Continuing Secure Action - ADASI Supplier Portal')

@section('content')
<div class="tw-text-center" role="status" aria-live="polite">
    <span class="ui-spinner" aria-hidden="true"></span>
    <p class="tw-m-0 tw-mt-4 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Protected action</p>
    <h2 class="tw-m-0 tw-mt-1.5 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">Continuing your request</h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">Your password was confirmed. Please wait while we continue.</p>

    <form id="password-confirmation-continuation" method="POST" action="{{ $action['url'] }}" class="tw-mt-6">
        @csrf
        @if ($action['method'] !== 'POST')<input type="hidden" name="_method" value="{{ $action['method'] }}">@endif
        @foreach ($action['inputs'] as $input)<input type="hidden" name="{{ $input['name'] }}" value="{{ $input['value'] }}">@endforeach
        <noscript><button type="submit" class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95">Continue</button></noscript>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('password-confirmation-continuation').submit();
});
</script>
@endsection
