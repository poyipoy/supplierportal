<?php

namespace Tests\Feature\Procurement;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ComparisonDimensionsTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
    }

    public function test_each_shape_displays_pr_and_supplier_dimensions_in_the_existing_cells(): void
    {
        $pr = $this->createRequisition([[], [], []]);
        $requested = [
            ['shape' => 'Flat', 'thickness' => 30, 'width' => 300, 'length' => 2000, 'd_outer' => null, 'd_inner' => null],
            ['shape' => 'Round', 'thickness' => null, 'width' => null, 'd_outer' => 60, 'd_inner' => null, 'length' => 6000],
            ['shape' => 'Hollow', 'thickness' => null, 'width' => null, 'd_outer' => 60, 'd_inner' => 40, 'length' => 6000],
        ];
        foreach ($pr->items as $index => $item) {
            $item->forceFill($requested[$index])->save();
        }
        $pr->load('items');
        $quote = $this->createQuotation($pr);
        $offered = [
            ['available_thickness' => 28, 'available_width' => 290, 'available_length' => 1800],
            ['available_d_outer' => 65, 'available_length' => 5000],
            ['available_d_outer' => 70, 'available_d_inner' => 50, 'available_length' => null, 'available_length_min' => 3000, 'available_length_max' => 6000],
        ];
        foreach ($quote->items as $index => $item) {
            $item->forceFill($offered[$index] + ['offered_weight_source' => 'estimated'])->save();
        }
        $quote->load('items');
        $original = $quote->items->map(fn ($item) => $item->getAttributes())->all();
        $response = $this->actingAs($this->purchasing)->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr]))->assertOk();
        $matrix = $response->viewData('comparison')['matrix'];
        $expectedPr = ['T30 × W300 × L2000 mm', 'D60 × L6000 mm', 'OD60 × ID40 × L6000 mm'];
        $expectedOffer = ['T28 × W290 × L1800 mm', 'D65 × L5000 mm', 'OD70 × ID50 × L3000–6000 mm'];
        $xpath = $this->xpath($response->getContent());
        $rows = $xpath->query('//table[contains(@class,"comparison-matrix-table")]//tr[@data-comparison-row]');
        $this->assertCount(3, $rows);
        foreach ($rows as $index => $row) {
            $this->assertSame(7, $xpath->query('./td', $row)->length, 'The supplier group keeps its three existing columns.');
            $this->assertSame($expectedPr[$index], trim($xpath->query('./td[1]//*[@data-requested-dimensions]', $row)->item(0)->textContent));
            $this->assertSame($expectedOffer[$index], trim($xpath->query('./td[5]//*[@data-offered-dimensions]', $row)->item(0)->textContent));
            $this->assertStringNotContainsString('Qty', $xpath->query('./td[5]', $row)->item(0)->textContent);
            $this->assertStringNotContainsString(__('purchasing.copy.est_weight'), $xpath->query('./td[5]', $row)->item(0)->textContent);
            $price = $matrix[$index]['prices'][$quote->id];
            $this->assertSame($pr->items[$index]->dimension_label, $price['requested_spec']);
            $this->assertSame($quote->items[$index]->available_dimension_label, $price['offered_spec']);
            $this->assertEquals(40000, $price['price_idr']);
            $this->assertEquals($quote->items[$index]->resolved_amount, $price['offer_amount']);
        }
        $this->assertSame($original, $quote->fresh('items')->items->map(fn ($item) => $item->getAttributes())->all());
        $response->assertSee('kg/unit')->assertSee('Total kg');
    }

    public function test_missing_offer_fields_are_not_replaced_with_pr_dimensions(): void
    {
        $pr = $this->createRequisition();
        $quote = $this->createQuotation($pr);
        $quote->items[0]->forceFill(['available_thickness' => 27, 'available_width' => null, 'available_length' => null])->save();
        $response = $this->actingAs($this->purchasing)->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr]))->assertOk();
        $row = $response->viewData('comparison')['matrix'][0];
        $this->assertSame('T2 × W100 × L200 mm', $row['dimension_display']);
        $this->assertSame('T27 × W– × L– mm', $row['prices'][$quote->id]['dimension_display']);
        $quote->items[0]->forceFill(['available_thickness' => null])->save();
        $response = $this->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr]))->assertOk();
        $this->assertSame('T– × W– × L– mm', $response->viewData('comparison')['matrix'][0]['prices'][$quote->id]['dimension_display']);
    }

    public function test_unavailable_and_missing_quotes_keep_their_supplier_cell_span(): void
    {
        $pr = $this->createRequisition([[], []]);
        $quote = $this->createQuotation($pr);
        $quote->items[0]->forceFill(['is_available' => false])->save();
        $quote->items[1]->delete();
        $response = $this->actingAs($this->purchasing)->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr]))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $cells = $xpath->query('//tr[@data-comparison-row]/td[@colspan="3"]');
        $this->assertCount(2, $cells);
        $this->assertSame(0, $xpath->query('//tr[@data-comparison-row]//*[@data-offered-dimensions]')->length);
    }

    #[DataProvider('numberFormats')]
    public function test_requested_and_offered_decimals_follow_regional_preferences_including_length_ranges(string $format, string $requested, string $offered): void
    {
        $pr = $this->createRequisition();
        $pr->items[0]->forceFill(['thickness' => 2.5])->save();
        $pr->load('items');
        $quote = $this->createQuotation($pr);
        $quote->items[0]->forceFill(['available_thickness' => 3.75, 'available_width' => null,
            'available_length_min' => 80.5, 'available_length_max' => 90.75])->save();
        $this->purchasing->preference()->updateOrCreate([], [...config('user_preferences.defaults'), 'number_format' => $format]);
        app()->forgetScopedInstances();
        $response = $this->actingAs($this->purchasing)->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr]))->assertOk();
        $row = $response->viewData('comparison')['matrix'][0];
        $this->assertSame($requested, $row['dimension_display']);
        $this->assertSame($offered, $row['prices'][$quote->id]['dimension_display']);
    }

    public static function numberFormats(): array
    {
        return [
            'indonesian' => ['indonesian', 'T2,5 × W100 × L200 mm', 'T3,75 × W– × L80,5–90,75 mm'],
            'international' => ['international', 'T2.5 × W100 × L200 mm', 'T3.75 × W– × L80.5–90.75 mm'],
        ];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }
}
