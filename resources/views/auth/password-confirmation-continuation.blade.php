@extends('layouts.auth')

@section('title', 'Continuing Secure Action - ADASI Supplier Portal')

@section('content')
<div class="tw-text-center" role="status" aria-live="polite">
    <span class="tw-inline-flex tw-h-14 tw-w-14 tw-items-center tw-justify-center tw-rounded-ui-full tw-bg-success-container tw-text-success-container-foreground"><span class="ui-spinner" aria-hidden="true"></span></span>
    <h2 class="tw-m-0 tw-mt-4 tw-text-ui-xl tw-font-semibold">Continuing your protected action</h2>
    <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">Your password was confirmed. Please wait while we continue.</p>

    <form id="password-confirmation-continuation" method="POST" action="{{ $action['url'] }}" class="tw-mt-6">
        @csrf
        @if ($action['method'] !== 'POST')<input type="hidden" name="_method" value="{{ $action['method'] }}">@endif
        @foreach ($action['inputs'] as $input)<input type="hidden" name="{{ $input['name'] }}" value="{{ $input['value'] }}">@endforeach
        <noscript><x-ui.button type="submit" class="tw-w-full">Continue</x-ui.button></noscript>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('password-confirmation-continuation').submit();
});
</script>
@endsection
