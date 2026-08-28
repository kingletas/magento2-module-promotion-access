<?php
/**
 * CartPromotionsTest.php
 *
 * @package     Commerce_PromotionAccess
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\PromotionAccess\Test\Behaviour;

use Commerce\PromotionAccess\Model\Coupon\CouponRuleLocator;
use Commerce\PromotionAccess\Model\Db\StagedEntityFilter;
use Commerce\PromotionAccess\Model\Memo\RequestMemo;
use Commerce\PromotionAccess\Model\Quote\AppliedRuleIds;
use Commerce\PromotionAccess\Model\Rule\RuleFieldReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;

/**
 * "What is this cart running, and does any of it give free shipping?"
 */
final class CartPromotionsTest extends TestCase
{
    /** @var array<int, array<string, mixed>> The salesrule table. */
    private array $rules = [];

    /** @var array<string, int> Coupon code => rule id. */
    private array $coupons = [];

    /** @var string[] Every statement the connection was asked to run. */
    private array $statements = [];

    /** @var int[] Rule ids the last statement asked about. */
    private array $requested = [];

    protected function setUp(): void
    {
        $this->statements = [];
        $this->requested = [];

        $this->rules = [
            3 => ['rule_id' => 3, 'name' => 'Spring 20%', 'simple_action' => 'by_percent', 'simple_free_shipping' => 0],
            7 => ['rule_id' => 7, 'name' => 'Free shipping over 50', 'simple_action' => 'by_fixed',
                  'simple_free_shipping' => 1],
        ];

        $this->coupons = ['SPRING20' => 3, 'SHIPFREE' => 7];
    }

    public function testACartRunningAFreeShippingRuleIsRecognisedAsSuch(): void
    {
        $quote = $this->quoteWith('3,7');
        $reader = $this->reader();

        $ruleIds = (new AppliedRuleIds())->forQuote($quote);
        $freeShipping = false;

        foreach ($ruleIds as $ruleId) {
            $freeShipping = $freeShipping || $reader->getFreeShipping($ruleId) === 1;
        }

        self::assertSame([3, 7], $ruleIds);
        self::assertTrue($freeShipping);
    }

    public function testACartWithNoFreeShippingRuleIsNotGivenAny(): void
    {
        $quote = $this->quoteWith('3');
        $reader = $this->reader();

        self::assertSame(0, $reader->getFreeShipping(3));
    }

    /**
     * `applied_rule_ids` is null until the first collection and empty when no
     * rule matched.
     */
    public function testAQuoteWithNoRulesYieldsNoIdsAndAsksNothing(): void
    {
        $appliedRuleIds = new AppliedRuleIds();

        self::assertSame([], $appliedRuleIds->forQuote($this->quoteWith(null)));
        self::assertSame([], $appliedRuleIds->forQuote($this->quoteWith('')));
        self::assertSame([], $appliedRuleIds->forQuote($this->quoteWith('0')));
    }

    /**
     * Repeats, blanks, spaces and a trailing comma are all things
     * `applied_rule_ids` genuinely contains after several totals collections.
     */
    public function testTheStringAQuoteActuallyCarriesIsCleanedUpOnTheWayIn(): void
    {
        $quote = $this->quoteWith(' 3 ,7,3,,0,');

        self::assertSame([3, 7], (new AppliedRuleIds())->forQuote($quote));
    }

    /**
     * One indexed lookup rather than loading the coupon model to read a single
     * column.
     */
    public function testACouponCodeLeadsToWhatItsRuleDoes(): void
    {
        $ruleId = $this->couponLocator()->findRuleIdByCode('SHIPFREE');

        self::assertSame(7, $ruleId);
        self::assertSame('Free shipping over 50', $this->reader()->getName($ruleId));
        self::assertTrue($this->reader()->isAction($ruleId, 'by_fixed'));
    }

    /**
     * Shoppers type coupon codes wrong.
     */
    public function testACouponThatDoesNotExistLeadsNowhere(): void
    {
        self::assertNull($this->couponLocator()->findRuleIdByCode('NOT-A-COUPON'));
    }

    /**
     * Quotes outlive rules.
     */
    public function testARuleThatHasSinceBeenDeletedReadsAsAbsent(): void
    {
        $reader = $this->reader();

        self::assertFalse($reader->exists(42));
        self::assertNull($reader->getSimpleAction(42));
        self::assertNull($reader->getFreeShipping(42));
        self::assertNull($reader->getName(42));
    }

    /**
     * The module's name is a claim about how these answers are obtained, and
     * this is where it is checked.
     */
    public function testEveryAnswerComesFromOneSelectAndNoRuleIsEverBuilt(): void
    {
        $quote = $this->quoteWith('3,7');
        $reader = $this->reader();

        foreach ((new AppliedRuleIds())->forQuote($quote) as $ruleId) {
            $reader->getSimpleAction($ruleId);
            $reader->getFreeShipping($ruleId);
            $reader->getName($ruleId);
        }

        self::assertSame(['salesrule'], array_values(array_unique($this->statements)));
    }

    /**
     * `applied_rule_ids` arrives through the quote model's `__call`, not from
     * `CartInterface`.
     */
    private function quoteWith(?string $appliedRuleIds): CartInterface
    {
        $quote = $this->createPartialMock(Quote::class, []);

        if ($appliedRuleIds !== null) {
            $quote->setData('applied_rule_ids', $appliedRuleIds);
        }

        return $quote;
    }

    private function reader(): RuleFieldReader
    {
        return new RuleFieldReader(
            $this->resourceConnection(),
            $this->createMock(StagedEntityFilter::class),
            new RequestMemo()
        );
    }

    private function couponLocator(): CouponRuleLocator
    {
        return new CouponRuleLocator($this->resourceConnection(), new RequestMemo());
    }

    private function resourceConnection(): ResourceConnection
    {
        $table = '';
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnCallback(
            function ($from) use (&$table, $select): Select {
                $table = is_array($from) ? (string) reset($from) : (string) $from;
                $this->statements[] = $table;

                return $select;
            }
        );
        $select->method('where')->willReturnCallback(
            function (string $condition, $value = null) use ($select): Select {
                if (str_contains($condition, 'rule_id IN') || str_contains($condition, 'code IN')) {
                    $this->requested = (array) $value;
                }

                return $select;
            }
        );

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($i): string => '`' . $i . '`');
        $connection->method('fetchAll')->willReturnCallback(function (): array {
            $wanted = array_flip(array_map('intval', $this->requested));

            return array_values(array_intersect_key($this->rules, $wanted));
        });
        $connection->method('fetchPairs')->willReturnCallback(
            fn (): array => array_intersect_key($this->coupons, array_flip(array_map('strval', $this->requested)))
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(static fn (string $t): string => $t);

        return $resourceConnection;
    }
}
