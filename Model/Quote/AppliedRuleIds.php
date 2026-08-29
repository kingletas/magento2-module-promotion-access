<?php
/**
 * @package   Commerce_PromotionAccess
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\PromotionAccess\Model\Quote;

use Commerce\PromotionAccess\Api\AppliedRuleIdsInterface;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Parses `applied_rule_ids` into rule ids that are actually worth asking about.
 *
 * @see AppliedRuleIdsInterface for what each of the inline versions gets wrong
 */
class AppliedRuleIds implements AppliedRuleIdsInterface
{
    /**
     * @inheritDoc
     */
    public function forQuote(CartInterface $quote): array
    {
        // Not on CartInterface, so it arrives through the model's __call.
        $applied = $quote->getAppliedRuleIds();

        return $this->parse(is_string($applied) ? $applied : null);
    }

    /**
     * @inheritDoc
     */
    public function parse(?string $appliedRuleIds): array
    {
        if ($appliedRuleIds === null || trim($appliedRuleIds) === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', $appliedRuleIds) as $candidate) {
            $candidate = trim($candidate);

            // Anything that is not a positive whole number is dropped rather
            // than cast.
            if ($candidate === '' || !ctype_digit($candidate)) {
                continue;
            }

            $ruleId = (int) $candidate;

            if ($ruleId > 0) {
                // Keyed, so a string that repeats an id yields it once while
                // the order it was stored in survives.
                $ids[$ruleId] = true;
            }
        }

        return array_keys($ids);
    }
}
