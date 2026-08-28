<?php
/**
 * AppliedRuleIdsInterface.php
 *
 * @package     Commerce_PromotionAccess
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\PromotionAccess\Api;

use Magento\Quote\Api\Data\CartInterface;

/**
 * Which cart price rules a quote currently has applied.
 */
interface AppliedRuleIdsInterface
{
    /**
     * Rule ids applied to this quote: unique, positive, in the order stored.
     *
     * @return int[]
     */
    public function forQuote(CartInterface $quote): array;

    /**
     * The same, from the raw `applied_rule_ids` value.
     *
     * @return int[]
     */
    public function parse(?string $appliedRuleIds): array;
}
