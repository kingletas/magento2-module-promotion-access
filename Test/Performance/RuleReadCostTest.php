<?php
/**
 * @package   Commerce_PromotionAccess
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\PromotionAccess\Test\Performance;

use Commerce\Foundation\Test\Support\BudgetAssertions;
use Commerce\PromotionAccess\Model\Coupon\CouponRuleLocator;
use Commerce\PromotionAccess\Model\Db\StagedEntityFilter;
use Commerce\PromotionAccess\Model\Memo\RequestMemo;
use Commerce\PromotionAccess\Model\Rule\RuleFieldReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

/**
 * What reading a promotion costs.
 */
class RuleReadCostTest extends TestCase
{
    use BudgetAssertions;

    private int $queries = 0;

    /** @var int[]|string[] What the last statement asked about. */
    private array $requested = [];

    /** @var array<int, array<string, mixed>> */
    private array $rules = [];

    /** @var array<string, int> */
    private array $coupons = [];

    protected function setUp(): void
    {
        $this->queries = 0;
        $this->requested = [];
        $this->rules = [];
        $this->coupons = [];
    }

    /**
     * Fifty is not hypothetical on a store that runs stacked category
     * promotions; `applied_rule_ids` on a full cart there is a long string.
     */
    public function testReadingManyRulesCostsTheSameAsReadingOne(): void
    {
        $this->assertConstantCost(
            'queries while reading rule actions',
            function (int $rules): int {
                $this->queries = 0;
                $this->rules = $this->salesrule($rules);

                $this->reader()->getSimpleActions(range(1, $rules));

                return $this->queries;
            },
            [1, 50]
        );
    }

    /**
     * The action, the free-shipping flag and the name are one question asked
     * once.
     */
    public function testReadingThreeFieldsOffOneRuleIsOneQuery(): void
    {
        $this->rules = $this->salesrule(1);
        $reader = $this->reader();

        $reader->getSimpleAction(1);
        $reader->getFreeShipping(1);
        $reader->getName(1);
        $reader->exists(1);

        $this->assertCostAtMost('four reads of one rule', 1, $this->queries);
    }

    /**
     * Totals are collected several times per request and the rule set barely
     * changes between collections.
     */
    public function testAnOverlappingBatchOnlyAsksAboutWhatIsNew(): void
    {
        $this->rules = $this->salesrule(10);
        $reader = $this->reader();

        $reader->getSimpleActions([1, 2, 3]);
        $afterFirst = $this->queries;

        $reader->getSimpleActions([1, 2, 3]);

        $this->assertSame($afterFirst, $this->queries, 'A fully-memoised batch should ask nothing.');

        $reader->getSimpleActions([3, 4, 5]);

        $this->assertSame($afterFirst + 1, $this->queries, 'Only the two new ids needed a query.');
        $this->assertSame([4, 5], array_values(array_map('intval', $this->requested)));
    }

    /**
     * Quotes outlive rules, so a stale id on an abandoned cart is ordinary.
     */
    public function testARuleThatDoesNotExistIsNotLookedUpTwice(): void
    {
        $this->rules = [];
        $reader = $this->reader();

        $reader->exists(42);
        $afterFirst = $this->queries;

        $reader->exists(42);
        $reader->getSimpleAction(42);
        $reader->getName(42);

        $this->assertCostAtMost('re-asking about a deleted rule', $afterFirst, $this->queries);
    }

    /**
     * `applied_rule_ids` arrives as a string and is split; a blank entry
     * becomes zero.
     */
    public function testIdsThatCouldNotBeRulesAreNeverAskedAbout(): void
    {
        $this->reader()->getSimpleActions([0, -1]);

        $this->assertSame(0, $this->queries);
    }

    /**
     * The admin's coupon grid and the order export both resolve many codes at
     * once; per-code is a query per row of a page.
     */
    public function testResolvingManyCouponCodesCostsTheSameAsResolvingOne(): void
    {
        $this->assertConstantCost(
            'queries while resolving coupon codes',
            function (int $codes): int {
                $this->queries = 0;
                $this->coupons = $this->couponTable($codes);

                $this->couponLocator()->findRuleIdsByCodes(array_keys($this->coupons));

                return $this->queries;
            },
            [1, 50]
        );
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
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $condition, $value = null) use ($select): Select {
                if (str_contains($condition, 'IN')) {
                    $this->requested = (array) $value;
                }

                return $select;
            }
        );

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($i): string => '`' . $i . '`');
        $connection->method('fetchAll')->willReturnCallback(function (): array {
            $this->queries++;

            return array_values(array_intersect_key(
                $this->rules,
                array_flip(array_map('intval', $this->requested))
            ));
        });
        $connection->method('fetchPairs')->willReturnCallback(function (): array {
            $this->queries++;

            return array_intersect_key($this->coupons, array_flip(array_map('strval', $this->requested)));
        });

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(static fn (string $t): string => $t);

        return $resourceConnection;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function salesrule(int $count): array
    {
        $rules = [];

        for ($ruleId = 1; $ruleId <= $count; $ruleId++) {
            $rules[$ruleId] = [
                'rule_id' => $ruleId,
                'name' => 'Rule ' . $ruleId,
                'simple_action' => 'by_percent',
                'simple_free_shipping' => 0,
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, int>
     */
    private function couponTable(int $count): array
    {
        $coupons = [];

        for ($i = 1; $i <= $count; $i++) {
            $coupons['CODE-' . $i] = $i;
        }

        return $coupons;
    }
}
