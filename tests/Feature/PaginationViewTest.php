<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * The single pagination view (resources/views/vendor/pagination/tailwind.blade.php) that every
 * ->links() call renders, and the contract the DataTables footer shares with it.
 */
class PaginationViewTest extends TestCase
{
    public function test_a_single_page_keeps_the_summary_and_renders_no_pager(): void
    {
        $html = (string) $this->paginator(total: 12, page: 1, perPage: 25)->links();
        $xpath = $this->xpath($html);

        $this->assertStringContainsString('Showing 1 to 12 of 12 results', $html);
        $this->assertSame(1, $xpath->query('//nav[@aria-label="Pagination Navigation"]')->length);
        $this->assertSame(0, $xpath->query('//ul')->length);
    }

    public function test_zero_results_render_no_footer_at_all(): void
    {
        $this->assertSame('', trim((string) $this->paginator(total: 0, page: 1)->links()));
    }

    public function test_the_window_is_first_last_and_the_current_page_with_its_neighbours(): void
    {
        $html = (string) $this->paginator(total: 400, page: 8)->links();

        $this->assertSame(['1', '…', '7', '8', '9', '…', '20'], $this->pageSequence($html));
        $this->assertStringContainsString('Showing 141 to 160 of 400 results', $html);
    }

    public function test_a_gap_of_one_page_is_filled_with_the_page_instead_of_an_ellipsis(): void
    {
        $this->assertSame(
            ['1', '2', '3', '4', '5', '…', '9'],
            $this->pageSequence((string) $this->paginator(total: 180, page: 4)->links())
        );
        $this->assertSame(
            ['1', '2', '3', '4'],
            $this->pageSequence((string) $this->paginator(total: 80, page: 2)->links())
        );
    }

    public function test_the_window_ignores_on_each_side(): void
    {
        $wide = $this->paginator(total: 400, page: 8)->onEachSide(5);

        $this->assertSame(['1', '…', '7', '8', '9', '…', '20'], $this->pageSequence((string) $wide->links()));
    }

    public function test_the_current_page_is_marked_and_is_not_a_link(): void
    {
        $xpath = $this->xpath((string) $this->paginator(total: 400, page: 8)->links());

        $this->assertSame('8', trim($xpath->query('//li[@aria-current="page"]/span[contains(@class, "page-link")]')->item(0)?->textContent ?? ''));
        $this->assertSame(0, $xpath->query('//a[normalize-space()="8"]')->length);
        $this->assertSame(2, $xpath->query('//li[@aria-hidden="true"]')->length);
    }

    public function test_previous_and_next_links_carry_rel_and_the_neighbouring_page(): void
    {
        $xpath = $this->xpath((string) $this->paginator(total: 400, page: 8)->links());

        $this->assertStringContainsString('page=7', $xpath->evaluate('string(//a[@rel="prev"]/@href)'));
        $this->assertStringContainsString('page=9', $xpath->evaluate('string(//a[@rel="next"]/@href)'));
        $this->assertSame(0, $xpath->query('//li[@aria-disabled="true"]')->length);
        // The phone rule in app.css keeps only these two, the active page and its neighbours.
        $this->assertSame(1, $xpath->query('//li[contains(@class, "page-item") and contains(@class, "previous")]/a[@rel="prev"]')->length);
        $this->assertSame(1, $xpath->query('//li[contains(@class, "page-item") and contains(@class, "next")]/a[@rel="next"]')->length);
    }

    public function test_the_first_and_last_page_disable_their_edge_control_without_a_link(): void
    {
        $first = $this->xpath((string) $this->paginator(total: 400, page: 1)->links());
        $this->assertSame(0, $first->query('//a[@rel="prev"]')->length);
        $this->assertSame(1, $first->query('//li[contains(@class, "disabled")][@aria-disabled="true"]')->length);
        $this->assertSame(1, $first->query('//a[@rel="next"]')->length);

        $last = $this->xpath((string) $this->paginator(total: 400, page: 20)->links());
        $this->assertSame(0, $last->query('//a[@rel="next"]')->length);
        $this->assertSame(1, $last->query('//li[contains(@class, "disabled")][@aria-disabled="true"]')->length);
        $this->assertSame(1, $last->query('//a[@rel="prev"]')->length);
    }

    public function test_prev_next_text_stays_in_the_markup_and_page_links_have_accessible_names(): void
    {
        $html = (string) $this->paginator(total: 400, page: 8)->links();

        $this->assertStringContainsString('Previous', $html);
        $this->assertStringContainsString('Next', $html);
        $this->assertStringContainsString('aria-label="Go to page 9"', $html);
        $this->assertStringNotContainsString('&laquo;', $html);
        $this->assertStringNotContainsString('&raquo;', $html);
    }

    public function test_the_landmark_label_can_be_overridden_and_survives_the_bootstrap_alias(): void
    {
        $paginator = $this->paginator(total: 400, page: 2);

        $this->assertStringContainsString('aria-label="Pagination Navigation"', (string) $paginator->links());
        $this->assertStringContainsString('aria-label="Batches pagination"', (string) $paginator->links('pagination::tailwind', ['label' => 'Batches pagination']));
        $this->assertStringContainsString('aria-label="Batches pagination"', (string) $paginator->links('pagination::bootstrap-5', ['label' => 'Batches pagination']));
        $this->assertSame($this->pageSequence((string) $paginator->links()), $this->pageSequence((string) $paginator->links('pagination::bootstrap-5')));
    }

    public function test_page_urls_keep_the_active_filters(): void
    {
        $html = (string) $this->paginator(total: 400, page: 2, query: ['q' => 'abc'])->links();

        $this->assertStringContainsString('q=abc', $this->xpath($html)->evaluate('string(//a[@rel="next"]/@href)'));
    }

    public function test_the_summary_and_controls_follow_the_user_locale(): void
    {
        app()->setLocale('id');
        $html = (string) $this->paginator(total: 400, page: 8)->links();

        $this->assertStringContainsString('Menampilkan 141 sampai 160 dari 400 hasil', $html);
        $this->assertStringContainsString('Sebelumnya', $html);
        $this->assertStringContainsString('Berikutnya', $html);
        $this->assertStringContainsString('aria-label="Navigasi halaman"', $html);
    }

    public function test_simple_pagination_uses_the_same_skin_without_a_summary(): void
    {
        $html = (string) view('pagination::simple-tailwind', ['paginator' => $this->paginator(total: 400, page: 2)])->render();
        $xpath = $this->xpath($html);

        $this->assertSame(1, $xpath->query('//ul[contains(@class, "pagination")]')->length);
        $this->assertSame(1, $xpath->query('//a[@rel="prev"]')->length);
        $this->assertSame(1, $xpath->query('//a[@rel="next"]')->length);
        $this->assertStringNotContainsString('Showing', $html);
    }

    public function test_datatables_share_the_single_skin_and_hide_the_pager_on_a_single_page(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        foreach ([
            '.pagination .page-item .page-link',
            'div.dataTables_wrapper div.dataTables_paginate ul.pagination',
            '.dataTables_wrapper.ui-dt-single-page .dataTables_paginate',
            '.pagination:has(> .active) > .page-item:not(.previous, .next, .active, :has(+ .active), .active + .page-item)',
        ] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }
        $this->assertStringContainsString("on('draw.dt'", $layout);
        $this->assertStringContainsString("classList.toggle('ui-dt-single-page'", $layout);
        $this->assertStringContainsString("pager.setAttribute('role', 'navigation')", $layout);
        $this->assertStringContainsString("@js(__('pagination.navigation'))", $layout);

        foreach (['en' => 'Showing _START_ to _END_ of _TOTAL_ results', 'id' => 'Menampilkan _START_ sampai _END_ dari _TOTAL_ hasil'] as $locale => $info) {
            app()->setLocale($locale);
            $this->assertSame($info, __('datatables.info'));
        }
    }

    private function paginator(int $total, int $page, int $perPage = 20, array $query = []): LengthAwarePaginator
    {
        $count = max(0, min($perPage, $total - ($page - 1) * $perPage));

        return (new LengthAwarePaginator(array_fill(0, $count, 'row'), $total, $perPage, $page, ['path' => 'http://localhost/items']))
            ->appends($query);
    }

    private function xpath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    /**
     * The numbers and ellipses of the pager in order, without the Previous/Next controls.
     *
     * @return list<string>
     */
    private function pageSequence(string $html): array
    {
        $sequence = [];
        foreach ($this->xpath($html)->query('//ul[contains(@class, "pagination")]/li') as $item) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $item->textContent));
            if ($text === '…' || ctype_digit($text)) {
                $sequence[] = $text;
            }
        }

        return $sequence;
    }
}
