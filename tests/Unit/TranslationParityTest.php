<?php

namespace Tests\Unit;

use Illuminate\Support\Arr;
use Tests\TestCase;

class TranslationParityTest extends TestCase
{
    public function test_static_application_translation_references_exist_in_both_locales(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        foreach (['app', 'resources/views', 'resources/js', 'public/assets/js', 'config'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'js'], true)) {
                    continue;
                }
                $references = userFacingCopyTranslationReferences($file->getPathname(), file_get_contents($file->getPathname()));
                foreach ($references as $reference) {
                    $key = $reference['key'];
                    $location = $file->getPathname().':'.$reference['line'].':'.$key;
                    $this->assertTrue(app('translator')->has($key, 'en', false), $location);
                    $this->assertTrue(app('translator')->has($key, 'id', false), $location);
                }
            }
        }
    }

    public function test_reference_extraction_checks_js_choice_and_ignores_comments(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = implode("\n", [
            "// window.AdasiI18n.choice('js.ignored', count);",
            "window.AdasiI18n.choice('js.required_choice', count);",
            "window.AdasiI18n.t('js.required_text');",
            "__('common.dynamic_prefix_'.\$status);",
            "__('common.complete_key').' appended text';",
        ]);
        $references = userFacingCopyTranslationReferences('resources/js/fixture.js', $source);
        $this->assertSame(['js.required_choice', 'js.required_text', 'common.complete_key'], array_column($references, 'key'));
        $this->assertSame([2, 3, 5], array_column($references, 'line'));
    }

    public function test_application_locale_domains_keys_and_placeholders_have_parity(): void
    {
        $english = glob(lang_path('en/*.php'));
        $indonesian = glob(lang_path('id/*.php'));
        $this->assertEqualsCanonicalizing(array_map('basename', $english), array_map('basename', $indonesian));
        foreach ($english as $path) {
            $en = Arr::dot(require $path);
            $id = Arr::dot(require lang_path('id/'.basename($path)));
            $this->assertEqualsCanonicalizing(array_keys($en), array_keys($id), basename($path));
            foreach ($en as $key => $value) {
                $this->assertIsString($value, $key);
                $this->assertIsString($id[$key], $key);
                preg_match_all('/(?<![A-Za-z]):[A-Za-z_][A-Za-z0-9_]*/', $value, $enNames);
                preg_match_all('/(?<![A-Za-z]):[A-Za-z_][A-Za-z0-9_]*/', $id[$key], $idNames);
                $this->assertEqualsCanonicalizing(array_unique($enNames[0]), array_unique($idNames[0]), basename($path).':'.$key);
            }
        }
    }
}
