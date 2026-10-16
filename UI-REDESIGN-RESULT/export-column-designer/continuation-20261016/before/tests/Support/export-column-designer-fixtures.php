<?php

use App\Support\Export\ExportDefinitions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;

// Metadata-only browser fixtures using the actual Blade component and catalog.
// Generate: php tests/Support/export-column-designer-fixtures.php
// Serve: php -S 127.0.0.1:8101 tests/Support/export-column-designer-fixtures.php
// No database connection, application authentication, or write endpoint exists.
$project = dirname(__DIR__, 2);
$output = $project.'/UI-REDESIGN-RESULT/export-column-designer/fixtures';
if (PHP_SAPI === 'cli-server') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        exit;
    }
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (in_array($path, ['/source/advanced-export.js', '/source/export-column-designer.js'], true)) {
        header('Content-Type: text/javascript');
        readfile($project.'/resources/js/'.basename($path));
        exit;
    }
    if ($path === '/export-presets') {
        header('Content-Type: application/json');
        echo '{"data":[]}';
        exit;
    }
    if (str_starts_with($path, '/exports/definitions/')) {
        $key = substr($path, strlen('/exports/definitions/'));
        $locale = ($_GET['locale'] ?? 'en') === 'id' ? 'id' : 'en';
        if (! preg_match('/^[a-z]+\.[a-z-]+$/D', $key) || ! is_file($output.'/'.$key.'-'.$locale.'.json')) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: application/json');
        readfile($output.'/'.$key.'-'.$locale.'.json');
        exit;
    }
    if (str_starts_with($path, '/build/')) {
        $file = realpath($project.'/public'.$path);
        $base = realpath($project.'/public/build');
        if (! $file || ! str_starts_with($file, $base.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: '.(str_ends_with($file, '.css') ? 'text/css' : 'text/javascript'));
        readfile($file);
        exit;
    }
    if ($path === '/') {
        $key = $_GET['key'] ?? 'purchasing.po';
        if (isset($_GET['dual'])) {
            $key = 'purchasing.dual';
        }
        $locale = ($_GET['locale'] ?? 'en') === 'id' ? 'id' : 'en';
        if (! preg_match('/^[a-z]+\.[a-z-]+$/D', $key) || ! is_file($output.'/'.$key.'-'.$locale.'.html')) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: text/html; charset=UTF-8');
        $html = file_get_contents($output.'/'.$key.'-'.$locale.'.html');
        if (isset($_GET['api'])) {
            $html = preg_replace('#<script type="module" src="[^"]+"></script>#', '<script type="module">import {createAdvancedExport} from "/source/advanced-export.js"; window.__fixtureController = createAdvancedExport(document.querySelector("[data-advanced-export]"));</script>', $html);
        }
        echo $html;
        exit;
    }
    http_response_code(404);
    exit;
}

require $project.'/vendor/autoload.php';
foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => 'adasi_portal_test', 'DB_URL' => '', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'BROADCAST_CONNECTION' => 'null'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $project.'/bootstrap/app.php';
if ($app->configurationIsCached()) {
    throw new RuntimeException('Refusing cached config.');
}
$app->afterBootstrapping(RegisterProviders::class, function ($app): void {
    $app['db']->extend('fixture-denied', function (array $config) {
        $db = new MySqlConnection(fn () => throw new RuntimeException('FIXTURE_PDO_DENIED'), 'fixture_test', '', $config);
        $db->beforeExecuting(fn () => throw new RuntimeException('FIXTURE_SQL_DENIED'));

        return $db;
    });
    foreach (array_keys($app['config']->get('database.connections')) as $name) {
        $app['config']->set('database.connections.'.$name.'.driver', 'fixture-denied');
        $app['config']->set('database.connections.'.$name.'.url', null);
        $app['db']->purge($name);
    }
});
$app->make(Kernel::class)->bootstrap();
URL::forceRootUrl('http://127.0.0.1:8101');
$manifest = json_decode(file_get_contents($project.'/public/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
if (! is_dir($output)) {
    mkdir($output, 0777, true);
}
$keys = ['purchasing.po', 'supplier.po', 'purchasing.quotations', 'supplier.quotations', 'purchasing.pr', 'purchasing.shipments', 'qc.inspections', 'finance.local-invoices', 'accounting.local-invoices'];
$components = [];
foreach (['en', 'id'] as $locale) {
    app()->setLocale($locale);
    foreach ($keys as $key) {
        $definition = ExportDefinitions::get($key);
        // The metadata response is built from the same authoritative catalog;
        // this fixture does not stand in for HTTP authorization verification.
        $fields = $definition->filterSchema();
        foreach ($fields as &$field) {
            $field['label'] = __('exports.advanced.filters.'.$field['name']);
        }
        unset($field);
        $metadata = ['export_key' => $key, 'formats' => ['xlsx', 'csv'], 'supports_columns' => true, 'supports_presets' => true, 'columns' => array_map(fn ($column) => ['key' => $column->key, 'label' => __('exports.headings.'.$column->headingKey).($column->headingSuffix ? ($column->headingSuffix)() : ''), 'default' => $column->default, 'required' => $column->required], ExportDefinitions::allowedColumns($definition)), 'filters' => $fields];
        file_put_contents($output.'/'.$key.'-'.$locale.'.json', json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $component = Blade::render('<x-export.advanced-modal :export-key="$key" action="http://127.0.0.1:8101/blocked-export" />', ['key' => $key]);
        $component = preg_replace('/name="_token" value="[^"]+"/', 'name="_token" value="fixture-only"', $component);
        // Keep both the actual localized Blade copy and metadata response. Locale
        // routing is a fixture adapter, not a portal or preference change.
        $component = str_replace('/exports/definitions/'.$key.'"', '/exports/definitions/'.$key.'?locale='.$locale.'"', $component);
        $components[$locale][$key] = $component;
        $loader = view('partials.loader-logo')->render();
        $css = '/build/'.$manifest['resources/css/app.css']['file'];
        $js = '/build/'.$manifest['resources/js/app.js']['file'];
        $html = '<!doctype html><html lang="'.$locale.'" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture-only"><title>Column Designer Fixture</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="'.$css.'"></head><body>'.$component.$loader.'<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script type="module" src="'.$js.'"></script></body></html>';
        file_put_contents($output.'/'.$key.'-'.$locale.'.html', $html);
    }
    $poHtml = file_get_contents($output.'/purchasing.po-'.$locale.'.html');
    file_put_contents($output.'/purchasing.dual-'.$locale.'.html', str_replace($components[$locale]['purchasing.po'], $components[$locale]['purchasing.po'].$components[$locale]['purchasing.quotations'], $poHtml));
}
echo 'Generated 18 localized export fixtures and two dual-root fixtures; database access denied.'.PHP_EOL;
