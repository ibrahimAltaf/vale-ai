# Error codes

Errors use HTTP status plus `{"error":{"code":"...","message":"..."}}`. Unexpected exceptions become `500 internal_error`. The shop logs the exception and does not return its message.

| Code | Status | When it is returned |
| --- | --- | --- |
| `validation_error` | 422 | Missing or malformed input, including a bad idempotency key, EAN, SKU, postcode, sort field, or a value of the wrong JSON type |
| `unexpected_field` | 422 | The body contains a property the route does not accept, including commission, status, or role on registration |
| `invalid_json` | 400 | The body is not a JSON object or array |
| `unsupported_media_type` | 415 | A non-empty POST, PUT, or PATCH body is not `application/json` |
| `card_data_rejected` | 422 | The JSON body contains a raw card number or security code field |
| `unauthorized` | 401 | Missing bearer token, bad token, or wrong email or password |
| `webhook_unverified` | 401 | Mangopay did not return the resource named by the webhook |
| `forbidden` | 403 | Role, permission, or vendor scope does not allow the call |
| `vendor_not_active` | 403 | Vendor is pending or suspended |
| `not_found` | 404 | Unknown route or record, including another customer's order |
| `payment_failed` | 402 | The pay-in status is `FAILED`. The order stays unpaid and no commission is written |
| `infeasible` | 409 | The optimizer found no eligible allocation |
| `insufficient_stock` | 409 | The conditional reservation update did not change one row |
| `conflict` | 409 | Cancel of a paid order, fulfill of an unpaid order, stock below reserved, duplicate idempotency owner, or invoice state |
| `idempotency_conflict` | 409 | The idempotency key was reused with a different body |
| `vendor_onboarding_required` | 409 | The vendor has no Mangopay wallet for settlement |
| `kyc_required` | 409 | Payout requested before `kyc_status` is `VALIDATED` |
| `ambiguous_match` | 409 | EAN or SKU matches more than one master product |
| `invalid_amount` | 422 | Refund or payout exceeds the remaining amount |
| `payload_too_large` | 413 | CSV over 2 MB or PDF over 5 MB |
| `rate_limited` | 429 | More than 120 calls in 60 seconds |
| `admin_not_configured` | 503 | API admin email or password hash is empty |
| `mangopay_not_configured` | 503 | Sandbox credentials, platform wallet, or payout bank account is missing |
| `mangopay_unavailable` | 502 or 503 | The provider HTTP call could not be made |
| `mangopay_rejected` | 502 | Mangopay returned an error payload |
| `transfer_failed` | 502 | A wallet transfer failed in the memory gateway |
| `storage_unavailable` | 500 | Invoice directory or file could not be created |
| `internal_error` | 500 | Unhandled failure |

`optimal: false` is not an error. It is a successful optimizer response whose status is `FALLBACK_NOT_PROVEN_OPTIMAL`.
