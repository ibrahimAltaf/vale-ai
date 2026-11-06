# Prista Market — demo status

7 November 2026

This is a working PrestaShop 9 marketplace demo. It is not a finished marketplace, and it is not ready for real payments or a merchant launch. The name, colors, and sample vendors on the shop are placeholders.

## What you can see today

A shopper can compare several vendors on the same product, tell a shelf price from a delivered price, and run Smart Cart. Smart Cart prices the goods and the delivery together. It splits a quantity across vendors when one vendor cannot cover it, or when the split is cheaper. The result is called optimal only after every feasible split has been priced.

An administrator can open a marketplace dashboard, edit a vendor’s status and commission, import a CSV of offers, and review orders and collections. A vendor can sign in and see only that vendor’s orders, commission, and low stock.

The business rules behind those screens were executed on 7 November 2026: 45 automated tests, 176 assertions, all passed. Those tests do not open the web pages. They check pricing, shipping, matching, stock reservation, commission, and the cart totals.

## Totals the tests lock in

For the seeded demo catalog, postcode 10115:

| Basket | Result |
| --- | --- |
| Muesli and honey | Optimal. One vendor ships both. EUR 52.00, with EUR 3.00 saved against the two cheapest shelf prices. |
| Six kilos of oats | Optimal. Two units from one vendor and four from another. EUR 58.00. |
| Oats beyond the stock on hand | Cannot be fulfilled. |

## What is not in this demo

The repository has no Mangopay integration, no refunds, no vendor payouts, no payment webhooks, no invoice upload or customer invoice download, no scheduled CSV import, no CSV export, and no REST API.

Card checkout checks the number inside the shop and keeps the last four digits. It does not charge a real card. Email is attempted and is not reliable in the Docker shop. Vendors are created by the demo seed. There is no public vendor application form.

## How far this is from the production list

Against the 22-item production checklist used for this audit, 7 items are complete in code and covered by the passing tests, 9 are partial, and 6 have not been started. Nothing was blocked by a missing login during the test run. Weighted at half credit for a partial item, that is about 52 percent of that checklist.

The original demo — catalog, offers, shipping, Smart Cart, split orders, commission math, and the two dashboards — is what the shop can present. The missing half is the production work: real payments, payouts, invoices, vendor onboarding, scheduled imports, and an API.

## Where to click

Local shop, after the Docker steps in the presentation guide: `http://localhost:8080`

A copy was also deployed earlier at `https://vale.191-215-35-197.sslip.io/`. This audit did not open that host again.

Source: `https://github.com/ibrahimAltaf/vale-ai`
