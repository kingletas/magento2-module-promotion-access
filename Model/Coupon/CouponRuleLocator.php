<?php
/**
 * @package   Commerce_PromotionAccess
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\PromotionAccess\Model\Coupon;

use Commerce\PromotionAccess\Api\CouponRuleLocatorInterface;
use Commerce\PromotionAccess\Model\Memo\RequestMemo;
use Magento\Framework\App\ResourceConnection;

/**
 * Resolves coupon codes to the rule that issued them.
 *
 * @see CouponRuleLocatorInterface for what loading the coupon model costs
 */
class CouponRuleLocator implements CouponRuleLocatorInterface
{
    public const TABLE = 'salesrule_coupon';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly RequestMemo $memo
    ) {
    }

    /**
     * @inheritDoc
     */
    public function findRuleIdByCode(string $couponCode): ?int
    {
        // Trimmed on the way in as well as out, because the batch keys its
        // result by the trimmed code.
        return $this->findRuleIdsByCodes([$couponCode])[trim($couponCode)] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function findRuleIdsByCodes(array $couponCodes): array
    {
        $wanted = $this->normalise($couponCodes);

        if ($wanted === []) {
            return [];
        }

        [$found, $missing] = $this->fromMemo($wanted);

        if ($missing !== []) {
            $found += $this->fetch($missing);
        }

        return $found;
    }

    /**
     * Trimmed, non-empty, each code once.
     *
     * @param string[] $couponCodes
     * @return string[]
     */
    private function normalise(array $couponCodes): array
    {
        $wanted = [];

        foreach ($couponCodes as $code) {
            $code = trim((string) $code);

            if ($code !== '') {
                $wanted[$code] = true;
            }
        }

        return array_keys($wanted);
    }

    /**
     * Split what is already known from what still has to be read.
     *
     * @param string[] $codes
     * @return array{0: array<string, int>, 1: string[]}
     */
    private function fromMemo(array $codes): array
    {
        $found = [];
        $missing = [];

        foreach ($codes as $code) {
            $key = $this->key($code);

            if (!$this->memo->has($key)) {
                $missing[] = $code;
                continue;
            }

            // A stored null means "no coupon carries this code", which is an
            // answer worth keeping.
            $ruleId = $this->memo->get($key);

            if ($ruleId !== null) {
                $found[$code] = (int) $ruleId;
            }
        }

        return [$found, $missing];
    }

    /**
     * @param string[] $codes
     * @return array<string, int>
     */
    private function fetch(array $codes): array
    {
        $connection = $this->resourceConnection->getConnection();

        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->resourceConnection->getTableName(self::TABLE), ['code', 'rule_id'])
                ->where('code IN (?)', $codes)
        );

        $found = [];

        foreach ($codes as $code) {
            // The lookup is case-sensitive in the same way the column is, so a
            // code that came back under a different casing is not this one.
            $ruleId = isset($rows[$code]) ? (int) $rows[$code] : null;

            $this->memo->set($this->key($code), $ruleId);

            if ($ruleId !== null) {
                $found[$code] = $ruleId;
            }
        }

        return $found;
    }

    private function key(string $couponCode): string
    {
        return 'coupon:' . $couponCode;
    }
}
