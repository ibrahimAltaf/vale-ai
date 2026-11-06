# Stock reservation

Checkout holds stock before payment so two shoppers cannot buy the last unit.

## Atomic update

Inside a transaction the platform runs:

```sql
UPDATE marketplace_vendor_stock
SET reserved_quantity = reserved_quantity + :quantity, date_upd = :now
WHERE id_marketplace_vendor_offer = :offer
  AND (quantity - reserved_quantity) >= :quantity
```

The update must affect one row. SQLite binds the quantity as an integer. A text-bound comparison against an integer expression is false in this SQLite build even when the numbers look equal, so `Store` binds PHP integers with `PDO::PARAM_INT`. MySQL also takes `SELECT ... FOR UPDATE` on the stock row before the update. SQLite starts the checkout transaction with `BEGIN IMMEDIATE`.

The reservation row is `active` and expires 1800 seconds later. `runMaintenance` and the start of checkout call `releaseExpired`. Release flips the reservation to `released` only if it is still `active`, then subtracts the quantity with a `reserved_quantity >= :need` guard. Reservations whose vendor order belongs to a parent order in `paid`, `partially_refunded`, or `refunded` are not released. An index on `(status, expires_at)` supports that scan.

`pay` calls `ensureReservations` before the provider. If the hold has expired and the stock is gone, the call returns `409 insufficient_stock` and does not charge. A `FAILED` pay-in releases the order's reservations. A `SUCCEEDED` webhook that cannot restore the hold sets the payment settlement status to `stock_conflict`, leaves the order unpaid, and keeps the webhook deferred so a later delivery can be retried. A full refund of an unfulfilled vendor order releases its reservations. A partial refund does not. A fulfilled order is not restocked automatically. MySQL deadlocks on the transaction (`SQLSTATE 40001`) are retried up to three attempts. A forced deadlock was not produced in this run.

## Oversell checks

`MarketplaceFlowTest::testSecondCheckoutCannotOversellTheLastUnit` reserves the only unit and expects the next checkout to return `409` with `infeasible` or `insufficient_stock`.

`StockRaceTest` starts two PHP processes against one SQLite file after the parent connection is closed. One process prints `OK` and the other prints `NO`. A later read shows exactly one order. The child process is started with `-d extension=pdo_sqlite` because a new PHP process does not inherit the parent's `-d` flag.

Fulfillment, after payment, decrements both `quantity` and `reserved_quantity` and marks the reservation `consumed`. Cancelling an unpaid order releases the reservation. A paid order cannot be cancelled. It has to be refunded.

## Storefront

Native PrestaShop orders inserted by `PrestaShopOrderGateway` do not decrement PrestaShop stock by themselves. The storefront marketplace reservation path is `PrestaShopStockAccount` inside `confirmLines`. The API path does not call that gateway.
