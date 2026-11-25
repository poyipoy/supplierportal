<?php

namespace Tests\Feature\Architecture;

use App\Support\Ui\RowAction;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class RowActionContractTest extends TestCase
{
    public function test_blade_and_php_adapters_render_identical_contracts_across_locales(): void
    {
        foreach (['en' => ['Details', 'Details for PO-2026-001'], 'id' => ['Detail', 'Detail untuk PO-2026-001']] as $locale => [$expectedLabel, $expectedAriaLabel]) {
            app()->setLocale($locale);

            $bladeHtml = trim(Blade::render(
                '<x-ui.row-action href="/purchasing/orders/123" context="PO-2026-001" data-smart-panel-url="/purchasing/orders/123/panel" target="_blank" class="tw:w-full tw:min-h-11" />'
            ));

            $phpHtml = trim(RowAction::detail('/purchasing/orders/123', 'PO-2026-001', [
                'data-smart-panel-url' => '/purchasing/orders/123/panel',
                'target' => '_blank',
                'class' => 'tw:w-full tw:min-h-11',
            ]));

            $bladeNode = $this->parseSingleRootElement($bladeHtml, 'a');
            $phpNode = $this->parseSingleRootElement($phpHtml, 'a');

            $this->assertSame($this->normalizedClasses($bladeNode), $this->normalizedClasses($phpNode));
            $this->assertSame(
                ['tw:min-h-11', 'tw:w-full', 'ui-data-action', 'ui-data-action--primary', 'ui-focus-ring'],
                $this->normalizedClasses($phpNode)
            );

            foreach (['href', 'aria-label', 'data-smart-panel-url', 'target', 'rel'] as $attr) {
                $this->assertSame($phpNode->getAttribute($attr), $bladeNode->getAttribute($attr), "Mismatch on attribute [$attr] in locale [$locale]");
            }

            $this->assertSame('/purchasing/orders/123', $phpNode->getAttribute('href'));
            $this->assertSame('_blank', $phpNode->getAttribute('target'));
            $this->assertSame('noopener', $phpNode->getAttribute('rel'));
            $this->assertSame($expectedLabel, trim($bladeNode->textContent));
            $this->assertSame($expectedLabel, trim($phpNode->textContent));
            $this->assertSame($expectedAriaLabel, $bladeNode->getAttribute('aria-label'));
            $this->assertTrue(
                str_starts_with(mb_strtolower($expectedAriaLabel), mb_strtolower($expectedLabel)),
                "WCAG 2.5.3 requires aria-label [$expectedAriaLabel] to start with visible label [$expectedLabel]"
            );

            $this->assertSame(0, $bladeNode->getElementsByTagName('svg')->length, 'Default row-action must render 0 <svg> icons');
            $this->assertSame(0, $phpNode->getElementsByTagName('svg')->length, 'RowAction::detail must render 0 <svg> icons');
        }
    }

    public function test_tones_button_fallback_custom_labels_and_explicit_icons(): void
    {
        app()->setLocale('en');

        $neutralBlade = $this->parseSingleRootElement(
            Blade::render('<x-ui.row-action href="/docs/1" tone="neutral" label="Open PDF" context="INV-01" />'),
            'a'
        );
        $neutralPhp = $this->parseSingleRootElement(
            RowAction::link('/docs/1', 'Open PDF', 'neutral', 'INV-01'),
            'a'
        );

        $this->assertSame(['ui-data-action', 'ui-focus-ring'], $this->normalizedClasses($neutralBlade));
        $this->assertSame(['ui-data-action', 'ui-focus-ring'], $this->normalizedClasses($neutralPhp));
        $this->assertStringStartsWith('Open PDF', $neutralBlade->getAttribute('aria-label'));
        $this->assertStringContainsString('INV-01', $neutralBlade->getAttribute('aria-label'));
        $this->assertSame($neutralPhp->getAttribute('aria-label'), $neutralBlade->getAttribute('aria-label'));

        foreach (['primary', 'danger', 'warning', 'success'] as $tone) {
            $classes = explode(' ', RowAction::classesForTone($tone));
            $this->assertContains('ui-data-action--'.$tone, $classes);
            $this->assertContains('ui-focus-ring', $classes);
        }

        $buttonHtml = Blade::render('<x-ui.row-action label="Edit" context="User A" />');
        $buttonNode = $this->parseSingleRootElement($buttonHtml, 'button');
        $this->assertSame('button', $buttonNode->getAttribute('type'));
        $this->assertStringStartsWith('Edit', $buttonNode->getAttribute('aria-label'));

        $withIconHtml = Blade::render('<x-ui.row-action href="/print/1" tone="neutral" icon="printer" label="Print" context="PV-1" />');
        $withIconNode = $this->parseSingleRootElement($withIconHtml, 'a');
        $this->assertGreaterThan(0, $withIconNode->getElementsByTagName('svg')->length);
        $this->assertStringStartsWith('Print', $withIconNode->getAttribute('aria-label'));
    }

    public function test_xss_escaping_and_dangerous_url_scheme_rejection(): void
    {
        app()->setLocale('en');

        $evilContext = '" onmouseover="alert(1)<script>';
        $evilLabel = 'View " <script>alert(2)</script>';

        $phpHtml = RowAction::detail('/items/1', $evilContext, ['data-note' => $evilContext, 'foo" onclick="evil' => '1']);
        $phpLinkHtml = RowAction::link('/items/1', $evilLabel, 'primary', $evilContext);
        $bladeHtml = Blade::render(
            '<x-ui.row-action href="/items/1" :context="$ctx" />',
            ['ctx' => $evilContext]
        );

        foreach ([$phpHtml, $phpLinkHtml, $bladeHtml] as $html) {
            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringNotContainsString('" onmouseover="', $html);
            $this->assertStringNotContainsString('" onclick="', $html);

            $node = $this->parseSingleRootElement($html, 'a');
            $this->assertFalse($node->hasAttribute('onmouseover'));
            $this->assertFalse($node->hasAttribute('onclick'));
            $this->assertSame(0, $node->getElementsByTagName('script')->length);
            $this->assertStringContainsString($evilContext, $node->getAttribute('aria-label'));
        }

        $dangerousUrls = [
            'javascript:alert(1)',
            " \tjava\nscript:alert(1)",
            'JAVASCRIPT:void(0)',
            'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox(1)',
            '//evil.example/phish',
            '',
        ];

        foreach ($dangerousUrls as $badUrl) {
            $phpSafe = $this->parseSingleRootElement(RowAction::detail($badUrl, 'REC-1'), 'a');
            $bladeSafe = $this->parseSingleRootElement(
                Blade::render('<x-ui.row-action :href="$url" context="REC-1" />', ['url' => $badUrl]),
                'a'
            );

            $this->assertSame('#', $phpSafe->getAttribute('href'), "PHP helper failed to neutralize URL [$badUrl]");
            $this->assertSame('#', $bladeSafe->getAttribute('href'), "Blade component failed to neutralize URL [$badUrl]");
        }

        $safeExternal = $this->parseSingleRootElement(
            RowAction::detail('https://portal.example.test/orders/1?tab=lines&view=full', 'ORD-1'),
            'a'
        );
        $this->assertSame('https://portal.example.test/orders/1?tab=lines&view=full', $safeExternal->getAttribute('href'));
    }

    public function test_static_guardrails_hold_across_all_blade_table_and_mobile_card_surfaces(): void
    {
        $viewsRoot = resource_path('views');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsRoot));

        $eyeIconsInTd = [];
        $eyeIconsInMobileCardActions = [];
        $shadowTyposInRowSurfaces = [];
        $legacyOutlinePrimaryInTd = [];
        $rowActionsMissingContext = [];
        $rowActionTagCount = 0;

        foreach ($iterator as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relativePath = ltrim(str_replace(['\\', base_path()], ['/', ''], $file->getPathname()), '/');
            $content = file_get_contents($file->getPathname());
            $this->assertIsString($content);

            if (preg_match_all('/<td\b[^>]*>.*?<\/td>/is', $content, $tdMatches)) {
                foreach ($tdMatches[0] as $td) {
                    if (preg_match('/<x-ui\.icon\b[^>]*\bname\s*=\s*["\']eye["\']/is', $td)) {
                        $eyeIconsInTd[] = $relativePath;
                    }
                    if (str_contains($td, 'tw-shadow-xs')) {
                        $shadowTyposInRowSurfaces[] = $relativePath.' (<td>)';
                    }
                    if (preg_match('/\bbtn-outline-primary\b/', $td)) {
                        $legacyOutlinePrimaryInTd[] = $relativePath;
                    }
                }
            }

            if (preg_match_all('/<x-ui\.mobile-card\b.*?<\/x-ui\.mobile-card>/is', $content, $cardMatches)) {
                foreach ($cardMatches[0] as $card) {
                    if (str_contains($card, 'tw-shadow-xs')) {
                        $shadowTyposInRowSurfaces[] = $relativePath.' (<x-ui.mobile-card>)';
                    }
                    if (preg_match_all('/<x-slot:actions\b[^>]*>.*?<\/x-slot:actions>/is', $card, $slotMatches)) {
                        foreach ($slotMatches[0] as $slot) {
                            if (preg_match('/<x-ui\.icon\b[^>]*\bname\s*=\s*["\']eye["\']/is', $slot)) {
                                $eyeIconsInMobileCardActions[] = $relativePath;
                            }
                        }
                    }
                }
            }

            foreach ($this->extractRowActionOpeningTags($content) as $tag) {
                $rowActionTagCount++;
                if (! preg_match('/(?:^|\s)(?::?context|:?aria-label)\s*=/i', $tag)) {
                    $rowActionsMissingContext[] = $relativePath.' => '.$tag;
                }
            }
        }

        $rowActionComponent = file_get_contents(resource_path('views/components/ui/row-action.blade.php')) ?: '';
        $rowActionPhp = file_get_contents(app_path('Support/Ui/RowAction.php')) ?: '';
        if (str_contains($rowActionComponent, 'tw-shadow-xs')) {
            $shadowTyposInRowSurfaces[] = 'resources/views/components/ui/row-action.blade.php';
        }
        if (str_contains($rowActionPhp, 'tw-shadow-xs')) {
            $shadowTyposInRowSurfaces[] = 'app/Support/Ui/RowAction.php';
        }

        $this->assertGreaterThan(0, $rowActionTagCount, 'Expected migrated <x-ui.row-action> usages across Blade views');
        $this->assertSame([], array_unique($eyeIconsInTd), 'Found <x-ui.icon name="eye"> inside <td>');
        $this->assertSame([], array_unique($eyeIconsInMobileCardActions), 'Found <x-ui.icon name="eye"> inside <x-ui.mobile-card> actions');
        $this->assertSame([], array_unique($shadowTyposInRowSurfaces), 'Found tw-shadow-xs typo in row action surfaces');
        $this->assertSame([], array_unique($legacyOutlinePrimaryInTd), 'Found legacy .btn-outline-primary inside <td>');
        $this->assertSame([], $rowActionsMissingContext, 'Found <x-ui.row-action> missing context or aria-label');
    }

    public function test_controllers_use_row_action_helper_instead_of_handcoded_ui_data_action_links(): void
    {
        $controllersRoot = app_path('Http/Controllers');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($controllersRoot));
        $violations = [];

        foreach ($iterator as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $content = file_get_contents($file->getPathname()) ?: '';
            if (preg_match('/<a\b[^>]*ui-data-action/', $content)) {
                $violations[] = ltrim(str_replace(['\\', base_path()], ['/', ''], $file->getPathname()), '/');
            }
        }

        $this->assertSame([], $violations, 'Found raw <a ... ui-data-action> HTML in DataTables controllers instead of RowAction::');
    }

    public function test_overflow_menu_builds_unique_popover_trigger_and_escapes_attributes(): void
    {
        $this->assertSame('', RowAction::overflowMenu('dd-empty', ['', '   '], 'More'));

        $html = RowAction::overflowMenu(
            'dd-pr-"test"',
            ['<li><button type="button" class="dropdown-item">Delete</button></li>', ''],
            'More actions for "PR & <Test>"',
            'More'
        );

        $wrapper = $this->parseSingleRootElement($html, 'div');
        $doc = $wrapper->ownerDocument;
        $xpath = new DOMXPath($doc);
        $button = $xpath->query('.//button[@data-ui-dropdown-trigger]', $wrapper)->item(0);
        $menu = $xpath->query('.//ul[@data-ui-dropdown-menu]', $wrapper)->item(0);

        $this->assertInstanceOf(DOMElement::class, $button);
        $this->assertInstanceOf(DOMElement::class, $menu);
        $this->assertSame('dd-pr-"test"', $button->getAttribute('popovertarget'));
        $this->assertSame('dd-pr-"test"', $button->getAttribute('aria-controls'));
        $this->assertSame('false', $button->getAttribute('aria-expanded'));
        $this->assertSame('More actions for "PR & <Test>"', $button->getAttribute('aria-label'));
        $this->assertSame('More', trim($button->textContent));
        $this->assertSame('dd-pr-"test"', $menu->getAttribute('id'));
        $this->assertSame('auto', $menu->getAttribute('popover'));
    }

    /**
     * Extract full opening `<x-ui.row-action ...>` tags safely despite `->` or `=>` inside quoted attributes.
     *
     * @return list<string>
     */
    private function extractRowActionOpeningTags(string $content): array
    {
        $tags = [];
        $offset = 0;
        $length = strlen($content);

        while (preg_match('/<x-ui\.row-action(?=\s|>|\/>)/i', $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $match[0][1];
            $quote = null;
            $end = $length - 1;

            for ($i = $start + strlen($match[0][0]); $i < $length; $i++) {
                $ch = $content[$i];
                if ($quote !== null) {
                    if ($ch === $quote && $content[$i - 1] !== '\\') {
                        $quote = null;
                    }

                    continue;
                }
                if ($ch === '"' || $ch === "'") {
                    $quote = $ch;

                    continue;
                }
                if ($ch === '>') {
                    $end = $i;
                    break;
                }
            }

            $tags[] = substr($content, $start, $end - $start + 1);
            $offset = $end + 1;
        }

        return $tags;
    }

    private function parseSingleRootElement(string $html, string $expectedTag): DOMElement
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded, "Failed to parse HTML fragment: $html");
        $body = $doc->getElementsByTagName('body')->item(0);
        $this->assertNotNull($body);

        $elements = [];
        foreach ($body->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $elements[] = $child;
            }
        }

        $this->assertCount(1, $elements, "Expected a single root element in: $html");
        $this->assertSame($expectedTag, strtolower($elements[0]->tagName));

        return $elements[0];
    }

    /**
     * @return list<string>
     */
    private function normalizedClasses(DOMElement $element): array
    {
        $raw = trim($element->getAttribute('class'));
        $classes = $raw === '' ? [] : preg_split('/\s+/', $raw);
        $classes = array_values(array_filter($classes ?: [], static fn (string $c): bool => $c !== ''));
        sort($classes);

        return $classes;
    }
}
