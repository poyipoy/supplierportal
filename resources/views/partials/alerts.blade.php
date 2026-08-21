@if(session('success'))
    <div hidden data-adasi-flash data-type="success" data-title="Operation completed" data-message="{{ session('success') }}" data-duration="4000"></div>
@endif

@if(session('error'))
    <div hidden data-adasi-flash data-type="error" data-title="Action could not be completed" data-message="{{ session('error') }}" data-duration="8000"></div>
@endif

@if(session('warning'))
    <div hidden data-adasi-flash data-type="warning" data-title="Attention required" data-message="{{ session('warning') }}" data-duration="6000"></div>
@endif

@if(session('info'))
    <div hidden data-adasi-flash data-type="info" data-title="Update" data-message="{{ session('info') }}" data-duration="5000"></div>
@endif

@if(session('status'))
    @php
        $statusMessage = match (session('status')) {
            'verification-link-sent' => 'A new verification link has been sent to your email address.',
            'profile-updated' => 'Your profile information was updated.',
            'password-updated' => 'Your password was updated.',
            'two-factor-already-enabled' => 'Two-factor authentication is already enabled.',
            'two-factor-disabled' => 'Two-factor authentication was disabled.',
            'other-devices-logged-out' => 'Other active sessions were signed out.',
            default => session('status'),
        };
    @endphp
    <div hidden data-adasi-flash data-type="info" data-title="Account update" data-message="{{ $statusMessage }}" data-duration="5000"></div>
@endif

{{-- Validation errors --}}
@if($errors->any())
    <x-ui.alert tone="error" title="Review the highlighted fields" class="tw-mb-4">
        <ul class="tw-mb-0 tw-ps-4">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-ui.alert>
@endif
