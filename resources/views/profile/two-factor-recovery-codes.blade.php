@extends('layouts.auth')

@section('title', 'Two-Factor Recovery Codes - ADASI Supplier Portal')

@section('content')
    <div class="text-center mb-3">
        <i class="bi bi-shield-lock text-success" style="font-size: 2.5rem;"></i>
        <h4 class="fw-bold text-dark mt-2">Save your recovery codes</h4>
        <p class="small text-muted">Each code can be used once. They will not be shown again after leaving this page.</p>
    </div>

    <div class="border rounded bg-light p-3 font-monospace" id="recoveryCodes">
        @foreach ($codes as $code)
            <div class="py-1 user-select-all">{{ $code }}</div>
        @endforeach
    </div>

    <button type="button" class="btn btn-outline-primary w-100 mt-3" id="copyRecoveryCodes">
        <i class="bi bi-clipboard me-1"></i>Copy codes
    </button>
    <a href="{{ route('profile.edit') }}" class="btn btn-login w-100 mt-2">I have saved these codes</a>
@endsection

@section('scripts')
<script>
document.getElementById('copyRecoveryCodes').addEventListener('click', async function () {
    const codes = @json($codes);
    await navigator.clipboard.writeText(codes.join('\n'));
    this.innerHTML = '<i class="bi bi-check2 me-1"></i>Copied';
});
</script>
@endsection
