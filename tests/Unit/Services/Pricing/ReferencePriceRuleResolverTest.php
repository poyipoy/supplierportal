<?php

namespace Tests\Unit\Services\Pricing;

use App\Models\PrItem;
use App\Models\ReferencePriceRule;
use App\Models\User;
use App\Services\Pricing\ReferencePriceRuleResolver;
use Database\Seeders\ReferencePriceRulesInitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferencePriceRuleResolverTest extends TestCase
{
    use RefreshDatabase;

    private ReferencePriceRuleResolver $resolver;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'purchasing']);
        $this->seed(ReferencePriceRulesInitialSeeder::class);
        $this->resolver = app(ReferencePriceRuleResolver::class);
    }

    public function test_flat_15_matches_1_to_15(): void
    {
        $result = $this->resolver->resolve(PrItem::SHAPE_FLAT, 15.0);

        $this->assertTrue($result->isMatched());
        $this->assertSame('1-15', $result->rule?->category_key);
        $this->assertSame('thickness', $result->dimensionField);
        $this->assertSame('15.0000', $result->rawDimension);
        $this->assertSame(15, $result->roundedDimension);
    }

    public function test_flat_15_point_5_ceils_to_16_and_matches_16_to_50(): void
    {
        $result = $this->resolver->resolve(PrItem::SHAPE_FLAT, 15.5);

        $this->assertTrue($result->isMatched());
        $this->assertSame('16-50', $result->rule?->category_key);
        $this->assertSame('15.5000', $result->rawDimension);
        $this->assertSame(16, $result->roundedDimension);
    }

    public function test_round_50_point_5_ceils_to_51_and_matches_51_to_100(): void
    {
        $result = $this->resolver->resolve(PrItem::SHAPE_ROUND, 50.5);

        $this->assertTrue($result->isMatched());
        $this->assertSame('51-100', $result->rule?->category_key);
        $this->assertSame('d_outer', $result->dimensionField);
        $this->assertSame(51, $result->roundedDimension);
    }

    public function test_hollow_uses_d_outer_and_ceils_100_point_2_to_101(): void
    {
        $result = $this->resolver->resolve(PrItem::SHAPE_HOLLOW, '100.2000');

        $this->assertTrue($result->isMatched());
        $this->assertSame('101-150', $result->rule?->category_key);
        $this->assertSame('d_outer', $result->dimensionField);
        $this->assertSame(101, $result->roundedDimension);
    }

    public function test_dimension_700_matches_601_to_700_and_700_point_5_is_not_covered(): void
    {
        $res700 = $this->resolver->resolve(PrItem::SHAPE_ROUND, 700.0);
        $this->assertTrue($res700->isMatched());
        $this->assertSame('601-700', $res700->rule?->category_key);

        $res701 = $this->resolver->resolve(PrItem::SHAPE_ROUND, 700.5);
        $this->assertFalse($res701->isMatched());
        $this->assertSame('dimension_not_covered', $res701->reasonCode);
        $this->assertSame(701, $res701->roundedDimension);
    }

    public function test_unsupported_shape_returns_shape_unsupported(): void
    {
        $result = $this->resolver->resolve('Hexagon', 20.0);

        $this->assertFalse($result->isMatched());
        $this->assertSame('shape_unsupported', $result->reasonCode);
    }

    public function test_missing_or_non_positive_dimension_returns_dimension_missing(): void
    {
        $nullResult = $this->resolver->resolve(PrItem::SHAPE_FLAT, null);
        $this->assertFalse($nullResult->isMatched());
        $this->assertSame('dimension_missing', $nullResult->reasonCode);

        $zeroResult = $this->resolver->resolve(PrItem::SHAPE_FLAT, 0);
        $this->assertFalse($zeroResult->isMatched());
        $this->assertSame('dimension_missing', $zeroResult->reasonCode);

        $negResult = $this->resolver->resolve(PrItem::SHAPE_FLAT, -5);
        $this->assertFalse($negResult->isMatched());
        $this->assertSame('dimension_missing', $negResult->reasonCode);
    }

    public function test_inactive_rule_returns_rule_inactive(): void
    {
        $rule = ReferencePriceRule::where('category_key', '1-15')->firstOrFail();
        $rule->update(['status' => ReferencePriceRule::STATUS_INACTIVE]);

        $result = $this->resolver->resolve(PrItem::SHAPE_FLAT, 10.0);
        $this->assertFalse($result->isMatched());
        $this->assertSame('rule_inactive', $result->reasonCode);
    }
}
