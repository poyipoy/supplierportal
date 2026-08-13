<?php

namespace App\Services\Materials;

final class MaterialCodeNormalizer
{
    public function normalize(?string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';

        return mb_strtoupper($value, 'UTF-8');
    }
}
