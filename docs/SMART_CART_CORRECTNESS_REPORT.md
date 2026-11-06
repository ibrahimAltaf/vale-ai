# Smart cart correctness report

Date: 7 November 2026. Optimizer: `Marketplace\Optimizer\Service\CartOptimizer`. Shipping: `ShippingCalculator`. Money is integer cents.

## Supported model

The proven model is:

- unit price and quantity, split across offers when one offer cannot cover the line
- available stock `quantity - reserved_quantity`
- postcode prefix eligibility, including an empty prefix list as nationwide
- weight surcharge `intdiv(grams * surcharge_cents_per_kg, 1000)`
- free shipping when the vendor product subtotal reaches `free_threshold_cents`
- shipping cost added to the product cost. Commission is not part of the optimizer

Pallet-only products, pallet rate tables, volume, automatic parcel-to-pallet switching, and vendor minimum-order amounts are **not in the model**. They were not added. Changing them would change the demo totals that the existing tests lock. A result cannot be called optimal for a rule the optimizer does not read.

`OPTIMAL_VERIFIED` is returned only when the search space is within `maxCombinations` (default 200000) and every feasible allocation in that space was priced. `maxCombinations` of 0 forces `FALLBACK_NOT_PROVEN_OPTIMAL`. The explanation must contain "not a proven optimum". `INFEASIBLE` means no allocation satisfies stock and shipping.

## Independent reference

`OptimizerReferenceTest::testOneLineSplitMatchesTheExhaustiveReference` builds two offers for one product, quantity 2:

| Offer | Unit price | Stock | Shipping |
| --- | --- | --- | --- |
| A | 500 | 2 | 0 |
| B | 300 | 2 | 200 if any unit is taken |

The test walks every feasible split, prices it with the same shipping rules, and compares the cheapest grand total with `CartOptimizer`. The status is `OPTIMAL_VERIFIED` and the totals are equal. The test also asserts that this single call finishes in under 5.0 seconds. That is the execution budget for one optimization on host PHP 8.3, not a cluster SLO and not a 120,000-offer import budget.

`testFallbackIsNotDescribedAsOptimal` uses `maxCombinations` 0. Status is `FALLBACK_NOT_PROVEN_OPTIMAL`. The explanation contains "not a proven optimum".

Demo totals that the older tests still lock, and that this audit did not change:

| Scenario | Grand total |
| --- | --- |
| Oats split A2+B4 | 5800 |
| Breakfast, all Alpine | 5200, shipping 0 |
| Coffee split A5+B1 | 7420 |
| Oats quantity 30 | `INFEASIBLE` |

Postcode `80331` still excludes Northwind prefixes `10`, `20`, and `75`.

## Scale measurement, not a second proof

On `marketplace_bench` (30 vendors, 120000 offers), one cart of two lines had 30 candidates per line. Twenty calls averaged 173.1 ms. Status `OPTIMAL_VERIFIED`, grand 3505 cents, search space 13950, process peak 2 MB. The status means the optimizer exhausted its own bound. The independent walker was not run on those 13950 allocations. Label for this workload: **performance measured, optimality not independently proven**.

The 5-second PHPUnit bound was not approached by the 173 ms mean or by the earlier 200-run breakfast timing of 0.283 seconds recorded in `docs/SMART_CART_OPTIMIZER.md`.

## Correctness gaps left open

| Gap | Status |
| --- | --- |
| Pallet and volume pricing | Not implemented. Do not mark demo results optimal under those rules |
| Vendor minimum order | Not implemented |
| Commission VAT inside the optimizer | Not implemented. Commission remains a payment-time percent of product cents |
| Fallback labelled as optimal | Rejected by test |
| 120,000-offer global proof | **NOT TESTED** beyond the optimizer's own status flag |
