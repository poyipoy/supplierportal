<?php

namespace App\Support\Materials;

final class MaterialDimensionRules
{
    /**
     * Determine whether inner and outer diameters satisfy the Hollow geometric invariant (inner < outer).
     *
     * Returns true if either value is empty/null (incomplete pairs handled separately).
     * Returns false if non-numeric or if inner >= outer.
     */
    public static function hasValidHollowDiameterPair(
        mixed $inner,
        mixed $outer,
    ): bool {
        if ($inner === null || $inner === '' || $outer === null || $outer === '') {
            return true;
        }

        if (! is_numeric($inner) || ! is_numeric($outer)) {
            return false;
        }

        return (float) $inner < (float) $outer;
    }
}
