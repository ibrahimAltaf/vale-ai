# Smart cart optimizer

`POST /api/v1/cart/optimize` and `POST /api/v1/cart/validate` both call `CartOptimizer`. Validate does not reserve stock. Checkout runs the same optimizer inside the reservation transaction.

## Inputs

Each line is a product id and a quantity from 1 to 999, with at most 50 lines. The postcode is 2 to 12 letters or digits. Offers come from active vendors and active offers with available stock of at least 1. Available stock is `quantity - reserved_quantity`.

For each offer the platform loads that vendor's active shipping rules, asks `ShippingRuleSelector` for a candidate, and keeps the offer only when `ShippingCalculator::postcodeAllowed` agrees. The quote is:

- ineligible when the postcode matches none of the prefixes, unless the prefix list is empty
- weight surcharge `intdiv(grams * surcharge_cents_per_kg, 1000)`
- zero when the product subtotal is greater than or equal to the free-shipping threshold

A longer matching prefix beats a nationwide rule. Postcode `80331` excludes Northwind, whose prefixes are `10`, `20`, and `75`. Harbor's Munich rule, prefix `80`, base 300 cents and 150 cents per kg, quotes 450 cents for 1000 grams below the free threshold.

## Status values

| Status | `optimal` | Meaning |
| --- | --- | --- |
| `OPTIMAL_VERIFIED` | true | The search space was fully evaluated |
| `FALLBACK_NOT_PROVEN_OPTIMAL` | false | A feasible allocation was found and the explanation says it is not a proven optimum. This happens when the search space exceeds `maxCombinations` (default 200000). `maxCombinations` of 0 forces this path |
| `INFEASIBLE` | false | No allocation satisfies stock, shipping, and postcode together. Checkout returns `409` |

The optimizer may split a line across vendors when one vendor cannot cover the quantity. The demo oats case of 6 units is the storefront example of that split. Fallback results must not be described as the cheapest possible combination.

## Checked totals

These demo totals are covered by the existing optimizer tests and were not changed:

| Scenario | Result |
| --- | --- |
| Breakfast, postcode 10115, muesli 1 and honey 1 | All Alpine, product 5200, shipping 0, grand 5200. Unit-price baseline 5500 |
| Oats must split, quantity 6 | A2 + B4, product 5000, shipping 800, grand 5800 |
| Coffee split, quantity 6 | A5 + B1, product 7120, shipping 300, grand 7420 |
| Oats with quantity 30 | Infeasible. Demo stock is 30, not 99 |

A separate run of 200 breakfast optimizations completed in 0.283 seconds and stayed at `OPTIMAL_VERIFIED` / 5200 cents. The PHPUnit assertion allows 5 seconds for one `optimize` call.

The supported cost model is product price, weight surcharge, postcode prefixes, free-shipping threshold, and stock. Pallet rates, volume, automatic parcel-to-pallet switching, and vendor minimum-order amounts are not inputs. `OPTIMAL_VERIFIED` means the search space was exhausted under this model. It is not a proof for a model the code does not implement.

`OptimizerReferenceTest` compares one two-offer split with an independent exhaustive walk. The totals match and the status is `OPTIMAL_VERIFIED`. Setting `maxCombinations` to 0 returns `FALLBACK_NOT_PROVEN_OPTIMAL` and the explanation contains "not a proven optimum". The 120,000-offer timing run is a performance measurement, not a second exhaustive proof. See `docs/SMART_CART_CORRECTNESS_REPORT.md`.
