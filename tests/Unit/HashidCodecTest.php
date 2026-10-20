<?php

namespace Tests\Unit;

use App\Support\HashidCodec;
use Tests\TestCase;
use Vinkla\Hashids\Facades\Hashids;

class HashidCodecTest extends TestCase
{
    public function test_both_formats_decode_and_canonical_encoding_tracks_the_flag(): void
    {
        foreach ([1, 17, 123456789, PHP_INT_MAX] as $id) {
            $short = Hashids::connection('main')->encode($id);
            $long = Hashids::connection('long')->encode($id);
            $this->assertGreaterThanOrEqual(24, strlen($long));

            foreach ([false, true] as $enabled) {
                config(['portal_urls.long_hashids.enabled' => $enabled]);
                $this->assertSame($id, HashidCodec::decode($short));
                $this->assertSame($id, HashidCodec::decode($long));
                $this->assertSame($enabled ? $long : $short, HashidCodec::encode($id));
                $this->assertSame(! $enabled, HashidCodec::isCanonical($short));
                $this->assertSame($enabled, HashidCodec::isCanonical($long));
            }
        }
    }

    public function test_invalid_numeric_multiple_or_non_round_trip_inputs_are_rejected(): void
    {
        $hash = Hashids::connection('main')->encode(17);
        foreach ([false, true] as $enabled) {
            config(['portal_urls.long_hashids.enabled' => $enabled]);
            foreach (['', '17', '00017', 'invalid-hash', ' '.$hash, $hash.' ', strtoupper($hash),
                Hashids::connection('main')->encode(0), Hashids::connection('long')->encode(1, 2)] as $invalid) {
                $this->assertNull(HashidCodec::decode($invalid), $invalid);
                $this->assertFalse(HashidCodec::isCanonical($invalid), $invalid);
            }
        }
    }
}
