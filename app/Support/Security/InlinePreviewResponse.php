<?php

namespace App\Support\Security;

use Illuminate\Filesystem\FilesystemAdapter;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class InlinePreviewResponse
{
    /**
     * MIME types safe to render inside a same-origin browser frame without
     * risking active script execution (HTML/SVG remain download-only).
     */
    public const SAFE_INLINE_MIME_TYPES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/jpg',
        'image/gif',
        'image/webp',
    ];

    public static function canRenderInline(?string $mimeType): bool
    {
        return in_array(self::normalizeMime($mimeType), self::SAFE_INLINE_MIME_TYPES, true);
    }

    public static function sanitizeMime(?string $mimeType): string
    {
        $clean = str_replace(["\r", "\n"], '', trim((string) $mimeType));

        return $clean !== '' ? $clean : 'application/octet-stream';
    }

    public static function safeFilename(?string $filename, string $fallback = 'document'): string
    {
        $name = basename(str_replace('\\', '/', $filename ?: $fallback));
        $name = str_replace(["\r", "\n"], '', $name);

        return trim($name) !== '' ? $name : $fallback;
    }

    public static function applySameOriginFramingHeaders(Response $response): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

        return $response;
    }

    public static function diskResponse(
        FilesystemAdapter $disk,
        string $path,
        string $filename,
        ?string $mimeType,
        bool $inline
    ): Response {
        $safeMime = self::sanitizeMime($mimeType);
        $safeName = self::safeFilename($filename);
        $shouldInline = $inline && self::canRenderInline($safeMime);

        $headers = [
            'Content-Type' => $safeMime,
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $response = $disk->response(
            $path,
            $safeName,
            $headers,
            $shouldInline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT
        );

        if ($shouldInline) {
            self::applySameOriginFramingHeaders($response);
        }

        return $response;
    }

    private static function normalizeMime(?string $mimeType): string
    {
        $clean = strtolower(self::sanitizeMime($mimeType));

        return trim(explode(';', $clean, 2)[0]);
    }
}
