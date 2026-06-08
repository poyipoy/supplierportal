<?php

$viewsDir = __DIR__ . '/resources/views';
$appDir = __DIR__ . '/app';

$replacements = [
    ">Ketebalan<" => ">Thickness<",
    ">Tebal<" => ">Thickness<",
    ">Lebar<" => ">Width<",
    ">Panjang<" => ">Length<",
    ">Berat<" => ">Weight<",
    ">Diameter Dalam<" => ">Inner Diameter<",
    ">Diameter Luar<" => ">Outer Diameter<",
    ">Diminta<" => ">Requested<",
    ">Aktual<" => ">Actual<",
    "Tebal<br>Diminta" => "Thickness<br>Req.",
    "Tebal<br>Aktual" => "Thickness<br>Actual",
    "Lebar<br>Diminta" => "Width<br>Req.",
    "Lebar<br>Aktual" => "Width<br>Actual",
    "Panjang<br>Diminta" => "Length<br>Req.",
    "Panjang<br>Aktual" => "Length<br>Actual",
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

echo "Starting SAFE translation bulk script phase 4...\n";
$viewsChanged = processDirectory($viewsDir, $replacements);
echo "Views changed: $viewsChanged files.\n";

$appChanged = processDirectory($appDir, $replacements);
echo "App (Controllers/Exports/Models) changed: $appChanged files.\n";

echo "Done.\n";
