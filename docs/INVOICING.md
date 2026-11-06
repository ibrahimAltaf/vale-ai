# Invoicing

Two invoice kinds exist.

| Kind | Who issues it | Number prefix | Who may download it |
| --- | --- | --- | --- |
| `customer` | The vendor, for their sub-order | `VIN-` | The shopper who owns the parent order, the issuing vendor, and an administrator |
| `commission` | The marketplace, to the vendor | `CIN-` | The vendor and an administrator. Another shopper receives `404` |

## Status

| Status | Meaning |
| --- | --- |
| `pending` | Row created after the vendor order is paid. No number is finalised for the customer until issue |
| `issued` | Number assigned. Upload is allowed |
| `uploaded` | A PDF is stored |

A customer invoice can be created only after the parent order is paid. Issue before upload. The commission invoice total is the commission cents for that vendor sub-order. At 10 percent of 10000 cents the total is 1000.

## PDF storage

Upload is base64. The decoded bytes must start with `%PDF` and must be at most 5 MB. The stored file name matches `^[a-f0-9]{32}\.pdf$`. The directory is created mode `0700` and the file mode is `0600`. `realpath` must stay under the invoice directory. Download returns base64 and does not expose the filesystem path.

The shop directory is `var/marketplace-invoices` under the PrestaShop root. Cron can override it with `MARKETPLACE_INVOICE_DIR`.

## Future electronic invoicing

`marketplace_invoice` already separates issuer role, kind, status, number, and total. A later e-invoicing adapter should read an issued invoice and write a provider id beside the PDF row, without changing the vendor-issues-customer-invoice and marketplace-issues-commission-invoice split. No e-invoicing provider is connected.

## Order CSV

Vendors export their own orders from `GET /api/v1/vendors/me/exports/orders`. Scheduled offer imports are in [CSV_IMPORT_AND_CRON.md](CSV_IMPORT_AND_CRON.md). There is not a second cron that emails order CSV files. The same maintenance command is the place to add that export if a file drop is required later.
