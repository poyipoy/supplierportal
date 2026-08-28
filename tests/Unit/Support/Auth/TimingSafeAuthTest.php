<?php

namespace Tests\Unit\Support\Auth;

use App\Support\Auth\TimingSafeAuth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TimingSafeAuthTest extends TestCase
{
    public function test_equalize_performs_a_real_hash_comparison(): void
    {
        Hash::spy();

        TimingSafeAuth::equalize();

        Hash::shouldHaveReceived('check')->once();
    }

    public function test_equalize_uses_a_hash_that_actually_verifies_against_itself(): void
    {
        // Sanity check on the hardcoded constant: it must be a real, valid
        // hash for the default driver, or the "equalize" call would be
        // cheaper than a genuine Hash::check() and defeat the purpose.
        $this->assertTrue(
            Hash::check('timing-equalization-placeholder', $this->dummyHash())
        );
    }

    private function dummyHash(): string
    {
        $reflection = new \ReflectionClass(TimingSafeAuth::class);

        return $reflection->getConstant('DUMMY_HASH');
    }
}
