<?php
/**
 * @package   Kingletas_PromotionAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\PromotionAccess\Model\Rule;

use Kingletas\PromotionAccess\Api\RuleFieldReaderInterface;
use Kingletas\PromotionAccess\Model\Db\StagedEntityFilter;
use Kingletas\PromotionAccess\Model\Memo\RequestMemo;
use Magento\Framework\App\ResourceConnection;

/**
 * Reads the scalar columns of `salesrule` for a set of rules, once.
 */
class RuleFieldReader implements RuleFieldReaderInterface
{
    public const TABLE = 'salesrule';

    /**
     * The columns one read fetches.
     */
    private const COLUMNS = ['rule_id', 'name', 'simple_action', 'simple_free_shipping'];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StagedEntityFilter $stagedEntityFilter,
        private readonly RequestMemo $memo
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getSimpleAction(int $ruleId): ?string
    {
        $value = $this->row($ruleId)['simple_action'] ?? null;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function getSimpleActions(array $ruleIds): array
    {
        $actions = [];

        foreach ($this->rows($ruleIds) as $ruleId => $row) {
            $value = $row['simple_action'] ?? null;
            $actions[$ruleId] = ($value === null || $value === '') ? null : (string) $value;
        }

        return $actions;
    }

    /**
     * @inheritDoc
     */
    public function isAction(int $ruleId, string $action): bool
    {
        // Strict, unlike the `==` the call sites this replaces used.
        return $this->getSimpleAction($ruleId) === $action;
    }

    /**
     * @inheritDoc
     */
    public function getFreeShipping(int $ruleId): ?int
    {
        $value = $this->row($ruleId)['simple_free_shipping'] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * @inheritDoc
     */
    public function getName(int $ruleId): ?string
    {
        $value = $this->row($ruleId)['name'] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function exists(int $ruleId): bool
    {
        return $this->row($ruleId) !== [];
    }

    /**
     * One rule's row, or an empty array when it does not exist.
     *
     * @return array<string, mixed>
     */
    private function row(int $ruleId): array
    {
        return $this->rows([$ruleId])[$ruleId] ?? [];
    }

    /**
     * Rows for a set of rule ids, keyed by rule id.
     *
     * @param int[] $ruleIds
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $ruleIds): array
    {
        $wanted = [];

        foreach ($ruleIds as $ruleId) {
            $ruleId = (int) $ruleId;

            if ($ruleId > 0) {
                $wanted[$ruleId] = true;
            }
        }

        $wanted = array_keys($wanted);

        if ($wanted === []) {
            return [];
        }

        $found = [];
        $missing = [];

        foreach ($wanted as $ruleId) {
            $key = $this->key($ruleId);

            if ($this->memo->has($key)) {
                // A stored null is "this rule does not exist", which is worth
                // remembering; without it a stale id re-queries every time.
                $row = $this->memo->get($key);

                if ($row !== null) {
                    $found[$ruleId] = $row;
                }

                continue;
            }

            $missing[] = $ruleId;
        }

        if ($missing !== []) {
            $found += $this->fetch($missing);
        }

        return $found;
    }

    /**
     * @param int[] $ruleIds
     * @return array<int, array<string, mixed>>
     */
    private function fetch(array $ruleIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $select = $connection->select()
            ->from(['r' => $table], self::COLUMNS)
            ->where('r.rule_id IN (?)', $ruleIds);

        $this->stagedEntityFilter->applyCurrentVersion($select, 'r', $table);

        $rows = [];

        foreach ($connection->fetchAll($select) as $row) {
            $ruleId = (int) $row['rule_id'];
            $rows[$ruleId] = $row;
            $this->memo->set($this->key($ruleId), $row);
        }

        // Remember the misses too, or a cart carrying a deleted rule id asks
        // for it again on every collection.
        foreach ($ruleIds as $ruleId) {
            if (!isset($rows[$ruleId])) {
                $this->memo->set($this->key($ruleId), null);
            }
        }

        return $rows;
    }

    private function key(int $ruleId): string
    {
        return 'rule:' . $ruleId;
    }
}
