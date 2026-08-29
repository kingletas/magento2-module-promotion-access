<?php
/**
 * @package   Commerce_PromotionAccess
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\PromotionAccess\Api;

/**
 * The rule behind a coupon code.
 */
interface CouponRuleLocatorInterface
{
    public function findRuleIdByCode(string $couponCode): ?int;

    /**
     * Rule ids for a set of coupon codes, keyed by the code, in one query.
     *
     * @param string[] $couponCodes
     * @return array<string, int>
     */
    public function findRuleIdsByCodes(array $couponCodes): array;
}
