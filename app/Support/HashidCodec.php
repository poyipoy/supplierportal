<?php

namespace App\Support;

use Vinkla\Hashids\Facades\Hashids;

final class HashidCodec
{
    public static function encode(int $id): string
    {
        return Hashids::connection(self::activeConnection())->encode($id);
    }

    public static function decode(string $hash): ?int
    {
        if ($hash === '' || ctype_digit($hash)) {
            return null;
        }

        foreach (array_unique([self::activeConnection(), 'main', 'long']) as $name) {
            $connection = Hashids::connection($name);
            $decoded = $connection->decode($hash);
            $id = count($decoded) === 1 ? $decoded[0] : null;
            if (is_int($id) && $id > 0 && $connection->encode($id) === $hash) {
                return $id;
            }
        }

        return null;
    }

    public static function isCanonical(string $hash): bool
    {
        $id = self::decode($hash);

        return $id !== null && self::encode($id) === $hash;
    }

    private static function activeConnection(): string
    {
        return config('portal_urls.long_hashids.enabled', false) ? 'long' : 'main';
    }
}
