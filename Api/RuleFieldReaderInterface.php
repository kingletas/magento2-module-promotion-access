<?php
/**
 * @package   Kingletas_PromotionAccess
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\PromotionAccess\Api;

/**
 * The scalar facts about a cart price rule, without building the rule.
 */
interface RuleFieldReaderInterface
{
    public function getSimpleAction(int $ruleId): ?string;

    /**
     * Simple actions for a set of rules, keyed by rule id, in one query.
     *
     * @param int[] $ruleIds
     * @return array<int, string|null>
     */
    public function getSimpleActions(array $ruleIds): array;

    /**
     * Whether the rule's simple action is exactly $action.
     */
    public function isAction(int $ruleId, string $action): bool;

    /**
     * The rule's `simple_free_shipping` setting, or null when it has none.
     */
    public function getFreeShipping(int $ruleId): ?int;

    /**
     * The rule's admin name.
     */
    public function getName(int $ruleId): ?string;

    public function exists(int $ruleId): bool;
}
