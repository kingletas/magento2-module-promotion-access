<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\PromotionAccess\Test\Unit\Model\Quote;

use Commerce\PromotionAccess\Model\Quote\AppliedRuleIds;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;

final class AppliedRuleIdsTest extends TestCase
{
    private AppliedRuleIds $ids;

    protected function setUp(): void
    {
        $this->ids = new AppliedRuleIds();
    }

    /**
     * `explode(',', '')` is `['']`, which casts to rule id 0.
     */
    public function testAnEmptyStringYieldsNoRulesRatherThanRuleZero(): void
    {
        self::assertSame([], $this->ids->parse(''));
        self::assertSame([], $this->ids->parse(null));
        self::assertSame([], $this->ids->parse('   '));
    }

    public function testItReadsTheIdsInTheOrderTheyWereStored(): void
    {
        self::assertSame([7, 3, 11], $this->ids->parse('7,3,11'));
    }

    public function testWhitespaceAroundIdsIsToleratedRatherThanCastToZero(): void
    {
        self::assertSame([7, 3], $this->ids->parse(' 7 , 3 '));
    }

    public function testARepeatedIdIsAskedAboutOnce(): void
    {
        self::assertSame([7, 3], $this->ids->parse('7,3,7,7'));
    }

    /**
     * A trailing comma is what a rule being removed leaves behind.
     */
    public function testTrailingAndDoubledSeparatorsDoNotProduceRuleZero(): void
    {
        self::assertSame([7], $this->ids->parse('7,'));
        self::assertSame([7, 3], $this->ids->parse('7,,3'));
        self::assertSame([], $this->ids->parse(','));
    }

    public function testNonNumericAndNegativeValuesAreDroppedRatherThanCast(): void
    {
        self::assertSame([3], $this->ids->parse('abc,-5,0,3'));
    }

    public function testItReadsTheValueOffAQuote(): void
    {
        self::assertSame([4, 9], $this->ids->forQuote($this->quoteWith('4,9')));
    }

    /**
     * A quote whose totals have never been collected has no such field, and
     * that arrives as null rather than as an empty string.
     */
    public function testAQuoteThatHasNeverCollectedTotalsYieldsNoRules(): void
    {
        self::assertSame([], $this->ids->forQuote($this->quoteWith(null)));
    }

    /**
     * `applied_rule_ids` arrives through the model's `__call`, so the double
     * carries real data.
     */
    private function quoteWith(?string $appliedRuleIds): Quote
    {
        $quote = $this->createPartialMock(Quote::class, []);

        if ($appliedRuleIds !== null) {
            $quote->setData('applied_rule_ids', $appliedRuleIds);
        }

        return $quote;
    }
}
