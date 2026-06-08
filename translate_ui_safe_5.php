<?php

$viewsDir = __DIR__ . '/resources/views/purchasing/comparison';

$replacements = [
    ">Antar Supplier<" => ">Inter-Supplier<",
    " Antar Supplier" => " Inter-Supplier",
    "Total Data Dibandingkan" => "Total Compared Data",
    "Kompetitif / Aman" => "Competitive / Safe",
    "Material Termurah" => "Cheapest Materials",
    ">Bandingkan<" => ">Compare<",
    "Grafik Price Comparison" => "Price Comparison Chart",
    "Total Material IDR" => "Total Price IDR",
    "Pilih Material" => "Select Material",
    "Pilih Supplier" => "Select Supplier",
    "Sembunyikan" => "Hide",
    "Menampilkan" => "Showing",
    "Data Pendukung" => "Supporting Data",
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

echo "Starting SAFE translation bulk script phase 5 (Comparison Module)...\n";
$viewsChanged = processDirectory($viewsDir, $replacements);
echo "Views changed: $viewsChanged files.\n";

echo "Done.\n";
