<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\LogoutOtherDevicesController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\ProfileTwoFactorController;
use App\Http\Controllers\Auth\RevokeSessionController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->middleware('no-store')->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('no-store')->name('login.store');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->middleware('no-store')->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware(['throttle:auth.password-reset-link', 'no-store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->middleware('no-store')->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware(['throttle:auth.password-reset', 'no-store'])
        ->name('password.store');
});

Route::middleware(['mfa.pending', 'no-store'])->group(function () {
    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'show'])
        ->name('two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:auth.mfa-code')->name('two-factor.challenge.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:auth.credentials'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:auth.email-security')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->middleware('no-store')->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware(['throttle:auth.credentials', 'no-store']);

    Route::put('password', [PasswordController::class, 'update'])
        ->middleware('throttle:auth.credentials')->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    Route::post('profile/logout-other-devices', LogoutOtherDevicesController::class)
        ->middleware(['auth.session', 'throttle:auth.credentials'])
        ->name('profile.logout-other-devices');

    // Revoke one specific session (as opposed to logout-other-devices above,
    // which nukes all of them at once and requires a password). This is a
    // narrower, lower-friction action: it can only ever target a row that
    // already belongs to the current user, scoped inside RevokeSessionController.
    Route::delete('profile/sessions/{sessionId}', RevokeSessionController::class)
        ->middleware(['auth.session', 'throttle:auth.security-action'])
        ->name('profile.sessions.revoke');

    Route::post('profile/two-factor/setup', [ProfileTwoFactorController::class, 'start'])
        ->middleware(['auth.session', 'password.confirm', 'throttle:auth.security-action'])->name('profile.two-factor.start');
    Route::get('profile/two-factor/setup', [ProfileTwoFactorController::class, 'show'])
        ->middleware('no-store')->name('profile.two-factor.setup');
    Route::post('profile/two-factor/confirm', [ProfileTwoFactorController::class, 'confirm'])
        ->middleware(['throttle:auth.mfa-code', 'no-store'])->name('profile.two-factor.confirm');
    Route::post('profile/two-factor/recovery-codes', [ProfileTwoFactorController::class, 'recoveryCodes'])
        ->middleware(['password.confirm', 'throttle:auth.security-action', 'no-store'])->name('profile.two-factor.recovery-codes');
    Route::delete('profile/two-factor', [ProfileTwoFactorController::class, 'destroy'])
        ->middleware(['password.confirm', 'throttle:auth.mfa-code', 'no-store'])->name('profile.two-factor.destroy');
});
