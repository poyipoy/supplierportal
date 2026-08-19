@extends('layouts.app')

@section('title', 'Profile & Security - ADASI Supplier Portal')
@section('page-title', 'Profile & Security')

@section('content')
    <div class="container-fluid px-0">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2 mb-4">
            <div>
                <h4 class="mb-1 fw-bold">Profile &amp; Security</h4>
                <p class="text-muted mb-0">Manage your account information, password, and sign-in security.</p>
            </div>
            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
                <i class="bi bi-person-check me-1"></i>{{ ucfirst($user->role) }} account
            </span>
        </div>

        <div class="row g-4">
            <div class="col-12 col-xl-7">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-bottom py-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center tw-h-[34px] tw-w-[34px]">
                                <i class="bi bi-person"></i>
                            </span>
                            <div>
                                <h6 class="mb-0 fw-semibold">Personal Information</h6>
                                <small class="text-muted">Name and email address for this account.</small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        @include('profile.partials.update-profile-information-form')
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom py-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="rounded-circle bg-warning bg-opacity-10 text-warning d-inline-flex align-items-center justify-content-center tw-h-[34px] tw-w-[34px]">
                                <i class="bi bi-key"></i>
                            </span>
                            <div>
                                <h6 class="mb-0 fw-semibold">Change Password</h6>
                                <small class="text-muted">Use a strong password with at least 12 characters.</small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        @include('profile.partials.update-password-form')
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-5">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-bottom py-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="rounded-circle bg-success bg-opacity-10 text-success d-inline-flex align-items-center justify-content-center tw-h-[34px] tw-w-[34px]">
                                <i class="bi bi-shield-lock"></i>
                            </span>
                            <div>
                                <h6 class="mb-0 fw-semibold">Two-Factor Authentication</h6>
                                <small class="text-muted">Optional additional protection for your sign-in.</small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        @include('profile.partials.two-factor-authentication-form')
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom py-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="rounded-circle bg-secondary bg-opacity-10 text-secondary d-inline-flex align-items-center justify-content-center tw-h-[34px] tw-w-[34px]">
                                <i class="bi bi-laptop"></i>
                            </span>
                            <div>
                                <h6 class="mb-0 fw-semibold">Other Devices</h6>
                                <small class="text-muted">End access from other active sessions.</small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        @include('profile.partials.logout-other-devices-form')
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card border-danger-subtle shadow-sm">
                    <div class="card-header bg-danger-subtle border-danger-subtle py-3">
                        <div class="d-flex align-items-center gap-2 text-danger-emphasis">
                            <i class="bi bi-exclamation-triangle"></i>
                            <h6 class="mb-0 fw-semibold">Danger Zone</h6>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        @include('profile.partials.delete-user-form')
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
