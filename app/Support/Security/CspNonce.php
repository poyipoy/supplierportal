<?php

namespace App\Support\Security;

class CspNonce
{
    private ?string $nonce = null;

    /**
     * Get or generate the cryptographic CSP nonce for the current request.
     */
    public function get(): string
    {
        if ($this->nonce === null) {
            $this->nonce = base64_encode(random_bytes(16));
        }

        return $this->nonce;
    }

    /**
     * Reset the nonce state (e.g. for testing or fresh request cycle).
     */
    public function reset(): void
    {
        $this->nonce = null;
    }

    /**
     * Static helper for quick resolution from service container.
     */
    public static function nonce(): string
    {
        return app(self::class)->get();
    }
}
