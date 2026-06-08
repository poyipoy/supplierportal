<?php

$viewsDir = __DIR__ . '/resources/views';
$appDir = __DIR__ . '/app';

$replacements = [
    "Purchase Order Terbaru" => "Latest Purchase Orders",
    "5 PR Terbaru" => "Latest 5 PRs",
    ">Baru<" => ">New<",
    "Formulir User Baru" => "New User Form",
    "Aktivitas Terbaru (Sistem)" => "Latest Activities (System)",
    "Exchange Rate History Terbaru" => "Latest Exchange Rate History",
    "Terdaftar Sejak" => "Registered Since",
    "Kosongkan jika not ingin mengubah password." => "Leave blank if you do not want to change the password.",
    "SUPPLIER TERDAFTAR" => "REGISTERED SUPPLIERS",
    "Status Quotation" => "Quotation Status",
    "Status Terakhir" => "Latest Status",
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

echo "Starting SAFE translation bulk script phase 3...\n";
$viewsChanged = processDirectory($viewsDir, $replacements);
echo "Views changed: $viewsChanged files.\n";

$appChanged = processDirectory($appDir, $replacements);
echo "App (Controllers/Exports/Models) changed: $appChanged files.\n";

echo "Done.\n";
