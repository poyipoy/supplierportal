@if(session('success'))
    <div hidden data-adasi-flash data-type="success" data-title="Success" data-message="{{ session('success') }}" data-duration="4000"></div>
@endif

@if(session('error'))
    <div hidden data-adasi-flash data-type="error" data-title="Error" data-message="{{ session('error') }}"></div>
@endif

@if(session('warning'))
    <div hidden data-adasi-flash data-type="warning" data-title="Warning" data-message="{{ session('warning') }}" data-duration="5500"></div>
@endif

@if(session('info'))
    <div hidden data-adasi-flash data-type="info" data-title="Information" data-message="{{ session('info') }}" data-duration="4500"></div>
@endif

@if(session('status'))
    @php
        $statusMessage = session('status') === 'verification-link-sent'
            ? 'A new verification link has been sent to the email address you provided during registration.'
            : session('status');
    @endphp
    <div hidden data-adasi-flash data-type="info" data-title="Information" data-message="{{ $statusMessage }}" data-duration="5000"></div>
@endif

{{-- Validation errors --}}
@if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-x-circle-fill"></i>
            <strong>An error occurred:</strong>
        </div>
        <ul class="mb-0 ps-3">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif
