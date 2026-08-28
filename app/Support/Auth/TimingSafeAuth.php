<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\Hash;

/**
 * Closes the login timing side-channel.
 *
 * Illuminate\Auth\EloquentUserProvider only calls Hash::check() once a user
 * row has already been found by retrieveByCredentials(). That means:
 *   - unknown email / inactive account -> no Hash::check() runs -> fast
 *   - known email + active account + wrong password -> Hash::check() runs -> slow
 *
 * Even though LoginRequest returns the exact same generic error message for
 * both cases, an attacker can still distinguish "account exists and is
 * active" from "it doesn't" by measuring response time. Calling equalize()
 * on the fast-path burns a comparable amount of CPU time on a dummy hash so
 * the two paths take a similar amount of time.
 */
final class TimingSafeAuth
{
    /**
     * A bcrypt hash of a random value with no corresponding real password.
     * Regenerate this (Hash::make(Str::random(32))) if the app's default
     * hashing driver or cost factor changes, so the dummy check keeps
     * costing roughly the same as a real one.
     */
    private const DUMMY_HASH = '$2y$12$vwO/tJX5dR1fAfGhOH1Qhuj0VT3MJxiQ/wx2MQnoEPtsfR9/niGUK';

    public static function equalize(): void
    {
        Hash::check('timing-equalization-placeholder', self::DUMMY_HASH);
    }
}
