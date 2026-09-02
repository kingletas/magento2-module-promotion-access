# Commerce_PromotionAccess

The handful of cart price rule reads that every module ends up writing — what kind of promotion is this, does it give free shipping, which rules is this cart running, which rule issued this coupon — written once, without loading a rule to answer any of them.

It is the promotions half of what [`Commerce_CatalogAccess`](../module-catalog-access) does for the catalogue, and it exists for the same reason: each of these is easy enough to write inline in four lines, and each of those four-line versions has the same defect as the last one.

---

## What's inside

| Contract | Default | Use it for |
| --- | --- | --- |
| `RuleFieldReaderInterface` | `Model\Rule\RuleFieldReader` | A rule's action, free-shipping setting and admin name — batched, staging-aware, memoised |
| `AppliedRuleIdsInterface` | `Model\Quote\AppliedRuleIds` | Turning `applied_rule_ids` into rule ids that are worth asking about |
| `CouponRuleLocatorInterface` | `Model\Coupon\CouponRuleLocator` | The rule behind a coupon code, without loading the coupon model |
| — | `Model\Db\StagedEntityFilter` | The version window a staged `salesrule` needs |
| — | `Model\Memo\RequestMemo` | The bounded LRU memo both readers are built on |

There is no configuration, no table, no ACL and no route. It is a library of contracts.

---

## The problems it is built to prevent

### Loading a rule to read a column

`RuleRepository::getById()` loads the rule row, then its website ids, then its customer group ids, then its store labels, and then converts the whole condition and action tree into data models by reflection. **It holds no cache of any kind**, so the second identical call costs exactly what the first did.

It is commonly called in a loop over a cart's applied rule ids, on every totals collection, to compare one string:

```php
$rule = $this->ruleRepository->getById($appliedRuleId);
if ($rule->getSimpleAction() == self::CART_RULE_EXPEDITED_SHIPPING) {
```

A fifth loads a coupon as a model to read one foreign key, on a shared instance — so the coupon model left in the container is carrying whichever code was asked about last, and a later `getCouponCode()` on it returns something nobody set.

| Written the usual way | What goes wrong | What this does instead |
| --- | --- | --- |
| `$ruleRepository->getById($id)->getSimpleAction()` | Four queries and a reflective condition conversion, per rule, per collection, uncached | `getSimpleAction()` — one query for the whole set of ids, memoised |
| `$coupon->loadByCode($code)->getRuleId()` | A model load, and the shared instance is left holding that code | `findRuleIdByCode()` — null when no coupon carries it, and nothing is mutated |
| `explode(',', $quote->getAppliedRuleIds())` | `explode(',', '')` is `['']`, which casts to rule id **0** — so a cart with no promotions has one applied rule, and the loop asks the repository for it | `forQuote()` — empty means empty |

### The staging trap

**`salesrule` is a staged table on Adobe Commerce.** Its primary key is `row_id`, `rule_id` repeats, and one rule is several rows — the live one and one per scheduled update.

```sql
SELECT simple_action FROM salesrule WHERE rule_id = 7   -- more than one row
```

The query does not fail. It returns every version and whichever sorts first wins, so a rule scheduled to become a different kind of promotion next month can decide today's shipping method. `StagedEntityFilter` narrows every read here to the version whose window contains now, and is a no-op on Open Source — the presence of the columns is the feature test, so the same query is correct on both editions.

### Absence

| Case | What this returns |
| --- | --- |
| Rule id that does not exist | `null` action, `false` from `exists()` — and the miss is remembered, so a cart carrying a deleted rule id does not re-query on every collection |
| Rule that exists with no action set | `null` action, `true` from `exists()` — the two are different questions |
| Coupon code no coupon carries | `null`, rather than an empty model whose `getRuleId()` is also null |
| `simple_free_shipping` | The number — 0, 1 or 2 — not a boolean. Magento's own values distinguish "for matching items" from "for the whole shipment", and callers here compare rather than cast |

---

## What it does not do

**Conditions and actions.** They are the reason `getById()` is expensive, and the code that evaluates them genuinely needs the data model — the free-gift validator here reads `getCondition()` and should keep using the repository. This module covers the questions that are a column lookup wearing an object's clothes, and stops there.

**Store labels.** `getName()` returns the rule's admin name, from the `salesrule` row. What a customer is shown lives in `salesrule_label` and is a different thing that is routinely different in practice. Adding it would be a second query and a scope decision, and nothing here has needed it yet.

**Writes.** Every contract is a read.

---

## Tests

```bash
make check
```

The coding standard and all four suites — 75 tests, no database and no Magento bootstrap. Narrow it to one suite with `SUITE`:

```bash
make test SUITE=behaviour
```

36 tests. The ones worth knowing about pin the three defects above: that an empty `applied_rule_ids` yields no rules rather than rule zero, that a set of rule ids costs one query and a repeat costs none, and that the live version window is applied to every read.
