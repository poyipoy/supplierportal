<?php

// Run before dependency installation; inspect names without reading private data.
$process = proc_open(['git', 'ls-files', '-z'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
if (! is_resource($process)) {
    fwrite(STDERR, "Could not inspect tracked paths.\n");
    exit(2);
}
fclose($pipes[0]);
$paths = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0 || $paths === false) {
    fwrite(STDERR, 'Could not inspect tracked paths. '.$error."\n");
    exit(2);
}

$blocked = array_values(array_filter(explode("\0", $paths), static function (string $path): bool {
    // The directory's ignore scaffold contains no application data.
    return preg_match('/\.zip$/i', $path) === 1
        || (str_starts_with($path, 'storage/app/private/') && $path !== 'storage/app/private/.gitignore');
}));
if ($blocked !== []) {
    fwrite(STDERR, "Tracked archives or private application files must be removed by the repository owner:\n");
    foreach ($blocked as $path) {
        fwrite(STDERR, '  '.json_encode($path, JSON_UNESCAPED_SLASHES)."\n");
    }
    exit(1);
}

fwrite(STDOUT, "Tracked artifact check passed.\n");
