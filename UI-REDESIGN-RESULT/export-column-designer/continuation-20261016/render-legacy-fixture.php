<?php

// Reproduce the pre-designer component without replacing any worktree source.
require dirname(__DIR__, 3).'/tests/Support/export-column-designer-fixtures.php';

$evidence = __DIR__;
\Illuminate\Support\Facades\Blade::anonymousComponentPath($evidence.'/frozen/legacy/resources/views/components', 'legacy');
foreach (['en', 'id'] as $locale) {
    app()->setLocale($locale);
    $key = 'purchasing.po';
    $component = \Illuminate\Support\Facades\Blade::render('<x-legacy::export.advanced-modal :export-key="$key" action="http://127.0.0.1:8101/blocked-export" />', ['key' => $key]);
    $component = preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="fixture-only"', $component);
    $loader = view('partials.loader-logo')->render();
    $html = '<!doctype html><html lang="'.$locale.'" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Frozen Legacy Export Fixture</title><link rel="stylesheet" href="/vendor/bootstrap.css"><link rel="stylesheet" href="/assets/legacy/app.css"></head><body>'.$component.$loader.'<script src="/vendor/bootstrap.js"></script><script type="module" src="/assets/legacy/app.js"></script></body></html>';
    file_put_contents($evidence.'/legacy-'.$locale.'.html', $html);
}
echo 'Legacy fixtures rendered from immutable pre-designer source; database remains denied.'.PHP_EOL;
