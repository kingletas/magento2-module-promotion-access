<?php
/**
 * @package   Kingletas_PromotionAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\PromotionAccess\Test\Unit\Model\Db;

use Kingletas\PromotionAccess\Model\Db\StagedEntityFilter;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StagedEntityFilterTest extends TestCase
{
    private const NOW = 1_774_526_400;

    /** @var array<string, string> Entity interface => link field the metadata reports. */
    private array $linkFields = [];

    /** @var string[] Tables that carry the staging version columns. */
    private array $stagedTables = [];

    /** @var array<int, array{condition: string, value: mixed}> */
    private array $conditions = [];

    /** @var string[] Tables the column check was asked about. */
    private array $columnChecks = [];

    private int $metadataLookups = 0;

    protected function setUp(): void
    {
        $this->linkFields = [ProductInterface::class => 'entity_id'];
        $this->stagedTables = [];
        $this->conditions = [];
        $this->columnChecks = [];
        $this->metadataLookups = 0;
    }

    /**
     * Attribute values and configurable links hang off `entity_id` on Open
     * Source and off `row_id` where content staging is installed.
     */
    public function testTheLinkFieldComesFromTheEntitysOwnMetadata(): void
    {
        $this->linkFields = [ProductInterface::class => 'row_id'];

        $this->assertSame('row_id', $this->filter()->getLinkField(ProductInterface::class));
    }

    public function testAnUnstagedInstallationLinksOnTheEntityId(): void
    {
        $this->assertSame('entity_id', $this->filter()->getLinkField(ProductInterface::class));
    }

    /**
     * An entity with no metadata registered is not staged, so it links on the
     * plain key.
     */
    public function testAnEntityWithNoRegisteredMetadataFallsBackToTheEntityId(): void
    {
        $this->assertSame('entity_id', $this->filter()->getLinkField(CategoryInterface::class));
    }

    /**
     * A metadata entry with a blank link field would otherwise produce `t. =
     * s.`, which is a syntax error rather than a wrong join.
     */
    public function testABlankLinkFieldFallsBackToTheEntityId(): void
    {
        $this->linkFields = [ProductInterface::class => ''];

        $this->assertSame('entity_id', $this->filter()->getLinkField(ProductInterface::class));
    }

    /**
     * The metadata pool is asked once per entity rather than once per row.
     */
    public function testTheLinkFieldIsResolvedOncePerEntity(): void
    {
        $filter = $this->filter();

        $filter->getLinkField(ProductInterface::class);
        $filter->getLinkField(ProductInterface::class);

        $this->assertSame(1, $this->metadataLookups);
    }

    /**
     * With staging, one entity is several rows: the live one and one per
     * scheduled update.
     */
    public function testAStagedTableIsNarrowedToTheVersionThatIsLiveNow(): void
    {
        $this->stagedTables = ['catalog_category_entity'];

        $this->filter()->applyCurrentVersion(
            $this->select(),
            'e',
            'catalog_category_entity',
            self::NOW
        );

        $this->assertSame(
            [
                ['condition' => 'e.created_in <= ?', 'value' => self::NOW],
                ['condition' => 'e.updated_in > ?', 'value' => self::NOW],
            ],
            $this->conditions
        );
    }

    /**
     * A half-open window: the row whose update takes effect exactly now is the
     * live one, and the row it replaces is not.
     */
    public function testTheVersionWindowIsHalfOpenSoOnlyOneRowIsEverLive(): void
    {
        $this->stagedTables = ['catalog_category_entity'];

        $this->filter()->applyCurrentVersion($this->select(), 'e', 'catalog_category_entity', self::NOW);

        $this->assertStringContainsString('<=', $this->conditions[0]['condition']);
        $this->assertStringContainsString('>', $this->conditions[1]['condition']);
        $this->assertStringNotContainsString('>=', $this->conditions[1]['condition']);
    }

    /**
     * A no-op where staging is not installed, which keeps the same query
     * correct on both editions.
     */
    public function testAnUnstagedTableIsLeftAlone(): void
    {
        $this->filter()->applyCurrentVersion($this->select(), 'e', 'catalog_category_entity', self::NOW);

        $this->assertSame([], $this->conditions);
    }

    /**
     * The alias is passed in because the entity table is not always `e`: a
     * query joining two entities has to qualify each one separately.
     */
    public function testTheConditionsAreQualifiedWithTheGivenAlias(): void
    {
        $this->stagedTables = ['catalog_category_entity'];

        $this->filter()->applyCurrentVersion($this->select(), 'cat', 'catalog_category_entity', self::NOW);

        $this->assertStringStartsWith('cat.', $this->conditions[0]['condition']);
    }

    /**
     * The presence of the column is the feature test, because Staging is
     * Adobe Commerce-only.
     */
    public function testStagingIsDetectedByTheColumnRatherThanByTheModule(): void
    {
        $this->stagedTables = ['catalog_category_entity'];
        $filter = $this->filter();

        $this->assertTrue($filter->isStaged('catalog_category_entity'));
        $this->assertFalse($filter->isStaged('catalog_product_entity'));
        $this->assertSame(
            ['catalog_category_entity', 'catalog_product_entity'],
            $this->columnChecks
        );
    }

    /**
     * Asked once per table: the check is a schema query, and running it per row
     * of a batch would cost more than the query it is guarding.
     */
    public function testTheColumnCheckIsMadeOncePerTable(): void
    {
        $filter = $this->filter();

        $filter->isStaged('catalog_category_entity');
        $filter->isStaged('catalog_category_entity');
        $filter->applyCurrentVersion($this->select(), 'e', 'catalog_category_entity', self::NOW);

        $this->assertSame(['catalog_category_entity'], $this->columnChecks);
    }

    /**
     * The moment defaults to now, and has to be a real timestamp or every row
     * falls outside.
     */
    public function testTheCurrentMomentIsUsedWhenTheCallerDoesNotSayOtherwise(): void
    {
        $this->stagedTables = ['catalog_category_entity'];
        $before = time();

        $this->filter()->applyCurrentVersion($this->select(), 'e', 'catalog_category_entity');

        $this->assertEqualsWithDelta($before, $this->conditions[0]['value'], 5);
    }

    private function filter(): StagedEntityFilter
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('tableColumnExists')->willReturnCallback(
            function (string $table, string $column): bool {
                $this->columnChecks[] = $table;

                return in_array($table, $this->stagedTables, true);
            }
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);

        $metadataPool = $this->createMock(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturnCallback(
            function (string $entityType): EntityMetadataInterface {
                $this->metadataLookups++;

                if (!array_key_exists($entityType, $this->linkFields)) {
                    throw new NoSuchEntityException(__('No metadata for "%1".', $entityType));
                }

                $metadata = $this->createMock(EntityMetadataInterface::class);
                $metadata->method('getLinkField')->willReturn($this->linkFields[$entityType]);

                return $metadata;
            }
        );

        return new StagedEntityFilter($resourceConnection, $metadataPool);
    }

    private function select(): Select&MockObject
    {
        $select = $this->createMock(Select::class);
        $select->method('where')->willReturnCallback(
            function (string $condition, $value = null) use (&$select): Select {
                $this->conditions[] = ['condition' => $condition, 'value' => $value];

                return $select;
            }
        );

        return $select;
    }
}
