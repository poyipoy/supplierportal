@extends('layouts.auth')

@section('title', 'Two-Factor Recovery Codes - ADASI Supplier Portal')

@section('content')
<header class="tw-text-center">
    <x-ui.icon name="shield-check" size="lg" class="tw-text-success" />
    <h2 class="tw-m-0 tw-mt-4 tw-text-ui-2xl tw-font-semibold tw-tracking-tight">Save your recovery codes</h2>
    <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">Each code can be used once. They will not be shown again after leaving this page.</p>
</header>

<div class="tw-mt-5 tw-grid tw-grid-cols-2 tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container tw-p-4 tw-font-mono tw-text-ui-sm" id="recoveryCodes">
    @foreach ($codes as $code)<div class="tw-select-all tw-rounded-ui-xs tw-bg-surface tw-p-2">{{ $code }}</div>@endforeach
</div>

<div class="tw-mt-5 tw-grid tw-gap-3">
    <x-ui.button type="button" variant="secondary" class="tw-w-full" id="copyRecoveryCodes"><x-ui.icon name="clipboard" /> Copy Codes</x-ui.button>
    <x-ui.button :href="route('profile.edit')" class="tw-w-full">I Have Saved These Codes</x-ui.button>
</div>
@endsection

@section('scripts')
<script>
document.getElementById('copyRecoveryCodes').addEventListener('click', async function () {
    const codes = @json($codes);
    await navigator.clipboard.writeText(codes.join('\n'));
    this.querySelector('span:last-child').textContent = 'Copied';
});
</script>
@endsection
