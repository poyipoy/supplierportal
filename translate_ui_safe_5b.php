<?php

$viewsDir = __DIR__ . '/resources/views/supplier/price-history';

$replacements = [
    "Data Pendukung" => "Supporting Data",
    "Grafik Price" => "Price Chart",
    "Pilih Material" => "Select Material",
    "Sembunyikan" => "Hide",
    "Menampilkan" => "Showing",
];

function processDirectory($dir, $replacements) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $count = 0;
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['php'])) {
            $content = file_get_contents($file->getPathname());
            $newContent = strtr($content, $replacements);
            if ($content !== $newContent) {
                file_put_contents($file->getPathname(), $newContent);
                $count++;
            }
        }
    }
    return $count;
}

echo "Starting SAFE translation bulk script phase 5b (Supplier Price History Module)...\n";
$viewsChanged = processDirectory($viewsDir, $replacements);
echo "Views changed: $viewsChanged files.\n";

echo "Done.\n";
