# Backend acceptance checklist

Status after the 7 November 2026 run. "Tested" means an automated assertion or a recorded local HTTP/MySQL command, not a code reading.

| Item | State | Evidence |
| --- | --- | --- |
| Vendor registration stays pending | Tested | `testPendingVendorCannotLoginUntilApproved` |
| Admin approve and suspend | Implemented | Approve is used by the flow tests. Suspend is implemented and not given its own test |
| Vendor profile cannot change commission or status | Implemented | Whitelist in `updateVendor` |
| Vendor data isolation | Tested | Fulfill of another vendor's order returns 403. Invoice download by another vendor returns 404 |
| Shopper isolation | Tested | Another shopper receives 404 for the order and the invoice |
| Offers, stock, SKU update | Tested | CSV retry reports `rows_updated` |
| Ambiguous EAN/SKU is not merged | Implemented | `ambiguous_match` in import. Matcher behaviour remains in the original catalog tests |
| Shipping postcode, weight, free threshold | Tested | Northwind excluded at 80331. Harbor Munich quote is 450 cents |
| Optimizer optimal and fallback | Tested | Breakfast 5200 `OPTIMAL_VERIFIED`. `maxCombinations` 0 is `FALLBACK_NOT_PROVEN_OPTIMAL` |
| Parent order and vendor sub-orders | Tested | Checkout returns vendor orders. MySQL throwaway checkout returned 201 |
| Cancel unpaid and fulfill paid | Implemented | Fulfill of another vendor is tested. The happy-path fulfill call is implemented |
| Stock reservation and oversell | Tested | Second checkout 409. Two processes produce one order |
| Reservation expiry | Implemented | Cron calls `releaseExpired`. The local cron run released 0 because none were expired |
| Commission rounding | Tested | 10000 at 10% then refund 2500 leaves available 6750 on SQLite and on MySQL |
| Refund and reconciliation kinds | Tested | SQLite flow expects payment, refund, and transfer rows |
| Payout requires validated KYC | Tested | The payout of 6750 is processed in the flow test |
| Mangopay HTTP request shape | Tested | Fake transport. Not a sandbox call |
| Mangopay sandbox pay-in, refund, payout | Blocked | No client id or API key in the environment |
| Webhook verification and idempotency | Tested | Unknown resource is 401. Second event does not double commission |
| Failed pay-in writes no commission | Tested | `card_fail` returns 402 |
| Customer and commission invoices | Tested | PDF upload, shopper download, other shopper and other vendor denied. Commission invoice total 1000 |
| CSV schedule does not run twice immediately | Tested | Second `runMaintenance` imports nothing |
| Cron on cPanel-style CLI | Tested locally | Docker cron printed JSON twice. Not executed on a cPanel host |
| `/api/v1` on the shop | Tested | Health 200 after the webservice rewrite exception |
| OpenAPI and Postman match the route map | Tested | 53 routes, contract test passed |
| Demo orders preserved | Tested | References 1001–1004 still present after schema install |
| API creates native `ps_orders` | Not in scope of the API | Storefront gateway still does. API checkout does not |
| 120,000 offer load test | Not run | Indexes exist. No timed load at that size |
| Electronic invoicing provider | Not connected | Invoice table is ready for a later adapter |
| PHPStan | Not run | No dependency in the repository |

Production card payments stay blocked until a sandbox call is recorded and `live_provider_verified` is no longer hard-coded false.
