<?php

declare(strict_types=1);

exec('git ls-files -z', $unused, $code);
if ($code !== 0) {
    fwrite(STDERR, "Unable to read git index.\n");
    exit(2);
}

$raw = shell_exec('git ls-files -z');
$files = array_filter(explode("\0", (string) $raw), static fn (string $file): bool => $file !== '');

$blocked = array_values(array_filter($files, static function (string $file): bool {
    if (preg_match('/(^|\/)\.env(\.|$)/', $file) === 1) {
        return ! in_array($file, ['.env.example', '.env.testing.example'], true);
    }

    if (str_ends_with(strtolower($file), '.zip')) {
        return true;
    }

    if (str_ends_with(strtolower($file), '.sql') && ! str_starts_with($file, 'database/sql/')) {
        return true;
    }

    if (in_array($file, ['api/index.php', 'temp_view.html', 'PNR261178.pdf', 'TARIKAN TRANSFER.xlsx', 'docs/naufal raw material.xlsx'], true)) {
        return true;
    }

    if (! str_contains($file, '/') && preg_match('/\.(xlsx|pdf)$/i', $file) === 1) {
        return true;
    }

    return str_starts_with($file, 'Revise Portal/')
        || str_starts_with($file, 'scratch/')
        || str_starts_with($file, '.agents/')
        || str_starts_with($file, '.claude/')
        || str_contains($file, 'chrome-profile');
}));

if ($blocked !== []) {
    fwrite(STDERR, "Blocked tracked files detected:\n".implode("\n", $blocked)."\n");
    exit(1);
}

fwrite(STDOUT, "Tracked artifact check passed.\n");
