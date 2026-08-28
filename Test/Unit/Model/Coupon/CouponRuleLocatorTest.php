<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\PromotionAccess\Test\Unit\Model\Coupon;

use Commerce\PromotionAccess\Model\Coupon\CouponRuleLocator;
use Commerce\PromotionAccess\Model\Memo\RequestMemo;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CouponRuleLocatorTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;

    /** @var array<int, mixed> Captured IN() bind, instead of a dynamic property on a mock. */
    private array $requested = [];
    private int $queries = 0;

    /** @var array<string, int> */
    private array $table = ['SAVE10' => 7, 'REWARDS-ABC' => 3];

    protected function setUp(): void
    {
        $this->select = $this->createMock(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->select->method('where')->willReturnCallback(function (string $cond, $value) {
            $this->requested = (array) $value;
            return $this->select;
        });

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($this->select);
        $this->connection->method('fetchPairs')->willReturnCallback(function (): array {
            $this->queries++;
            return array_intersect_key($this->table, array_flip($this->requested));
        });
    }

    public function testItResolvesACodeToItsRule(): void
    {
        self::assertSame(7, $this->locator()->findRuleIdByCode('SAVE10'));
    }

    /**
     * The model-loading version returned an empty coupon whose getRuleId() was
     * also null, leaving the caller unable to tell which null it held.
     */
    public function testACodeNoCouponCarriesIsNull(): void
    {
        self::assertNull($this->locator()->findRuleIdByCode('NOPE'));
    }

    public function testASetOfCodesCostsOneQuery(): void
    {
        $found = $this->locator()->findRuleIdsByCodes(['SAVE10', 'REWARDS-ABC', 'NOPE']);

        self::assertSame(1, $this->queries);
        self::assertSame(['SAVE10' => 7, 'REWARDS-ABC' => 3], $found);
    }

    public function testUnresolvedCodesAreAbsentRatherThanNull(): void
    {
        self::assertArrayNotHasKey('NOPE', $this->locator()->findRuleIdsByCodes(['NOPE']));
    }

    public function testAMissIsRememberedSoItIsNotAskedTwice(): void
    {
        $locator = $this->locator();
        $locator->findRuleIdByCode('NOPE');
        $locator->findRuleIdByCode('NOPE');

        self::assertSame(1, $this->queries);
    }

    public function testOnlyCodesNotAlreadyKnownAreFetched(): void
    {
        $locator = $this->locator();
        $locator->findRuleIdByCode('SAVE10');
        $locator->findRuleIdsByCodes(['SAVE10', 'REWARDS-ABC']);

        self::assertSame(['REWARDS-ABC'], $this->requested);
    }

    public function testBlankCodesNeverReachTheDatabase(): void
    {
        self::assertSame([], $this->locator()->findRuleIdsByCodes(['', '   ']));
        self::assertSame(0, $this->queries);
    }

    public function testSurroundingWhitespaceDoesNotMakeADifferentCode(): void
    {
        self::assertSame(7, $this->locator()->findRuleIdByCode('  SAVE10 '));
    }

    private function locator(): CouponRuleLocator
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new CouponRuleLocator($resource, new RequestMemo(100));
    }
}
