<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\PromotionAccess\Test\Unit\Model\Rule;

use Commerce\PromotionAccess\Model\Db\StagedEntityFilter;
use Commerce\PromotionAccess\Model\Memo\RequestMemo;
use Commerce\PromotionAccess\Model\Rule\RuleFieldReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Reading a rule's scalars without building the rule.
 */
final class RuleFieldReaderTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private StagedEntityFilter&MockObject $staging;
    private Select&MockObject $select;

    /** @var array<int, mixed> Captured IN() bind, instead of a dynamic property on a mock. */
    private array $requested = [];

    /** @var array<int, array<string, mixed>> */
    private array $table = [];

    private int $queries = 0;

    protected function setUp(): void
    {
        $this->table = [
            7 => ['rule_id' => 7, 'name' => 'Expedited', 'simple_action' => 'expedited_shipping',
                  'simple_free_shipping' => 0],
            3 => ['rule_id' => 3, 'name' => 'Free ship', 'simple_action' => 'by_percent',
                  'simple_free_shipping' => 1],
            9 => ['rule_id' => 9, 'name' => null, 'simple_action' => null, 'simple_free_shipping' => null],
        ];

        $this->select = $this->createMock(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->select->method('where')->willReturnCallback(function (string $cond, $value) {
            if (str_contains($cond, 'rule_id IN')) {
                $this->requested = (array) $value;
            }
            return $this->select;
        });

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($this->select);
        $this->connection->method('fetchAll')->willReturnCallback(function (): array {
            $this->queries++;
            $requested = $this->requested;
            return array_values(array_intersect_key($this->table, array_flip($requested)));
        });

        $this->staging = $this->createMock(StagedEntityFilter::class);
    }

    public function testItReadsTheSimpleAction(): void
    {
        self::assertSame('expedited_shipping', $this->reader()->getSimpleAction(7));
    }

    public function testARuleThatDoesNotExistHasNoAction(): void
    {
        self::assertNull($this->reader()->getSimpleAction(404));
        self::assertFalse($this->reader()->exists(404));
    }

    public function testAnEmptyActionReadsAsNullRatherThanAnEmptyString(): void
    {
        self::assertNull($this->reader()->getSimpleAction(9));
        self::assertTrue($this->reader()->exists(9), 'the rule exists, its action is simply unset');
    }

    /**
     * The comparison every call site this replaces was written to make.
     */
    public function testIsActionAnswersTheComparisonTheCallSitesMade(): void
    {
        $reader = $this->reader();

        self::assertTrue($reader->isAction(7, 'expedited_shipping'));
        self::assertFalse($reader->isAction(3, 'expedited_shipping'));
        self::assertFalse($reader->isAction(404, 'expedited_shipping'));
    }

    public function testFreeShippingIsReturnedAsItsNumberNotABoolean(): void
    {
        $reader = $this->reader();

        self::assertSame(1, $reader->getFreeShipping(3));
        self::assertSame(0, $reader->getFreeShipping(7));
        self::assertNull($reader->getFreeShipping(9));
    }

    /**
     * The shape being removed is one query per applied rule id.
     */
    public function testASetOfRulesCostsOneQuery(): void
    {
        $actions = $this->reader()->getSimpleActions([7, 3, 9]);

        self::assertSame(1, $this->queries);
        self::assertSame(['expedited_shipping', 'by_percent', null], array_values($actions));
    }

    public function testTheSecondQuestionAboutARuleCostsNothing(): void
    {
        $reader = $this->reader();
        $reader->getSimpleAction(7);
        $reader->getFreeShipping(7);
        $reader->getName(7);

        self::assertSame(1, $this->queries, 'all three come off one row');
    }

    /**
     * Without this, a cart carrying a deleted rule id re-queries for it on
     * every totals collection - the exact cost being removed.
     */
    public function testAMissIsRememberedSoItIsNotAskedTwice(): void
    {
        $reader = $this->reader();
        $reader->getSimpleAction(404);
        $reader->getSimpleAction(404);

        self::assertSame(1, $this->queries);
    }

    public function testOnlyTheRulesNotAlreadyKnownAreFetched(): void
    {
        $reader = $this->reader();
        $reader->getSimpleAction(7);
        $reader->getSimpleActions([7, 3]);

        self::assertSame([3], $this->requested, 'the second read asks only for what it lacks');
        self::assertSame(2, $this->queries);
    }

    public function testIdsThatCannotBeRuleIdsNeverReachTheDatabase(): void
    {
        self::assertSame([], $this->reader()->getSimpleActions([0, -1]));
        self::assertSame(0, $this->queries);
    }

    /**
     * On Adobe Commerce `salesrule` is staged: rule_id repeats, one row per
     * scheduled update.
     */
    public function testTheLiveVersionWindowIsAppliedToEveryRead(): void
    {
        $this->staging->expects(self::once())
            ->method('applyCurrentVersion')
            ->with($this->select, 'r', self::stringContains('salesrule'));

        $this->reader()->getSimpleAction(7);
    }

    private function reader(): RuleFieldReader
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new RuleFieldReader($resource, $this->staging, new RequestMemo(100));
    }
}
