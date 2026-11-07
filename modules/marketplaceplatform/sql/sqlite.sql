CREATE TABLE IF NOT EXISTS PREFIX_marketplace_vendor (
  id_marketplace_vendor INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL DEFAULT 'active',
  commission_percent TEXT NOT NULL DEFAULT '0.00',
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  description TEXT NULL,
  contact_name TEXT NULL,
  phone TEXT NULL,
  city TEXT NULL,
  brands TEXT NULL,
  coverage TEXT NULL,
  logo_path TEXT NULL,
  min_order_enabled INTEGER NOT NULL DEFAULT 0,
  min_order_cents INTEGER NOT NULL DEFAULT 0,
  vat_rate_percent TEXT NOT NULL DEFAULT '0.00',
  processing_fee_percent TEXT NOT NULL DEFAULT '0.00',
  date_add TEXT NOT NULL,
  date_upd TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_shipping_rule (
  id_marketplace_shipping_rule INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_vendor INTEGER NOT NULL,
  name TEXT NOT NULL,
  base_cents INTEGER NOT NULL DEFAULT 0,
  free_threshold_cents INTEGER NULL,
  surcharge_cents_per_kg INTEGER NOT NULL DEFAULT 0,
  postcode_prefixes TEXT NOT NULL DEFAULT '',
  pallet_base_cents INTEGER NOT NULL DEFAULT 0,
  pallet_free_threshold_cents INTEGER NULL,
  parcel_max_weight_grams INTEGER NULL,
  parcel_max_volume_cm3 INTEGER NULL,
  pallet_capacity_weight_grams INTEGER NULL,
  pallet_capacity_volume_cm3 INTEGER NULL,
  active INTEGER NOT NULL DEFAULT 1,
  date_add TEXT NOT NULL,
  date_upd TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_vendor_offer (
  id_marketplace_vendor_offer INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_vendor INTEGER NOT NULL,
  id_product INTEGER NOT NULL,
  vendor_sku TEXT NOT NULL,
  ean13 TEXT NULL,
  brand TEXT NULL,
  title TEXT NULL,
  description TEXT NULL,
  price_cents INTEGER NOT NULL,
  discount_percent TEXT NOT NULL DEFAULT '0.00',
  weight_grams INTEGER NOT NULL DEFAULT 0,
  volume_cm3 INTEGER NOT NULL DEFAULT 0,
  pallet_only INTEGER NOT NULL DEFAULT 0,
  image_url TEXT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  date_add TEXT NOT NULL,
  date_upd TEXT NOT NULL,
  UNIQUE (id_marketplace_vendor, vendor_sku)
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_vendor_stock (
  id_marketplace_vendor_stock INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_vendor_offer INTEGER NOT NULL UNIQUE,
  quantity INTEGER NOT NULL DEFAULT 0,
  reserved_quantity INTEGER NOT NULL DEFAULT 0,
  low_stock_threshold INTEGER NOT NULL DEFAULT 3,
  date_upd TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_product_match (
  id_marketplace_product_match INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_vendor INTEGER NOT NULL,
  vendor_sku TEXT NOT NULL,
  ean13 TEXT NULL,
  id_product INTEGER NULL,
  match_type TEXT NOT NULL,
  match_key TEXT NOT NULL,
  candidate_product_ids TEXT NULL,
  date_add TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_order (
  id_marketplace_order INTEGER PRIMARY KEY AUTOINCREMENT,
  reference TEXT NOT NULL UNIQUE,
  id_marketplace_cart INTEGER NULL,
  postcode TEXT NOT NULL,
  product_cents INTEGER NOT NULL,
  shipping_cents INTEGER NOT NULL,
  grand_cents INTEGER NOT NULL,
  optimization_status TEXT NOT NULL,
  optimization_note TEXT NULL,
  status TEXT NOT NULL DEFAULT 'confirmed',
  id_customer INTEGER NULL,
  payment_method TEXT NULL,
  payment_status TEXT NOT NULL DEFAULT 'recorded',
  id_marketplace_shopper INTEGER NULL,
  date_add TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_vendor_order (
  id_marketplace_vendor_order INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_order INTEGER NOT NULL,
  reference TEXT NOT NULL UNIQUE,
  parent_reference TEXT NOT NULL,
  id_marketplace_vendor INTEGER NOT NULL,
  product_cents INTEGER NOT NULL,
  shipping_cents INTEGER NOT NULL,
  grand_cents INTEGER NOT NULL,
  postcode TEXT NOT NULL,
  optimization_status TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'confirmed',
  id_order INTEGER NULL,
  shop_reference TEXT NULL,
  fulfillment_status TEXT NOT NULL DEFAULT 'pending',
  date_add TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_vendor_order_line (
  id_marketplace_vendor_order_line INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_vendor_order INTEGER NOT NULL,
  id_marketplace_vendor_offer INTEGER NOT NULL,
  id_product INTEGER NOT NULL,
  product_name TEXT NOT NULL,
  quantity INTEGER NOT NULL,
  unit_price_cents INTEGER NOT NULL,
  line_total_cents INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_stock_reservation (
  id_marketplace_stock_reservation INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_vendor_offer INTEGER NOT NULL,
  id_marketplace_vendor_order INTEGER NULL,
  quantity INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'active',
  expires_at TEXT NOT NULL,
  date_add TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS PREFIX_mp_reservation_expiry ON PREFIX_marketplace_stock_reservation (status, expires_at);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_commission (
  id_marketplace_commission INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_vendor INTEGER NOT NULL,
  id_marketplace_vendor_order INTEGER NULL,
  gross_cents INTEGER NOT NULL,
  commission_percent TEXT NOT NULL,
  commission_cents INTEGER NOT NULL,
  vat_cents INTEGER NOT NULL DEFAULT 0,
  net_cents INTEGER NOT NULL,
  date_add TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_import_job (
  id_marketplace_import_job INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_vendor INTEGER NOT NULL,
  filename TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  rows_total INTEGER NOT NULL DEFAULT 0,
  rows_created INTEGER NOT NULL DEFAULT 0,
  rows_updated INTEGER NOT NULL DEFAULT 0,
  rows_failed INTEGER NOT NULL DEFAULT 0,
  error_log TEXT NULL,
  date_add TEXT NOT NULL,
  date_upd TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_master_product (
  id_marketplace_master_product INTEGER PRIMARY KEY AUTOINCREMENT,
  ean13 TEXT NULL UNIQUE,
  sku TEXT NOT NULL,
  name TEXT NOT NULL,
  date_add TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_shopper (
  id_marketplace_shopper INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  first_name TEXT NOT NULL,
  last_name TEXT NOT NULL,
  mangopay_user_id TEXT NULL,
  id_customer INTEGER NULL,
  date_add TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_payment (
  id_marketplace_payment INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_order INTEGER NOT NULL,
  id_marketplace_shopper INTEGER NOT NULL,
  provider TEXT NOT NULL DEFAULT 'mangopay',
  provider_id TEXT NULL,
  idempotency_key TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL,
  settlement_status TEXT NOT NULL DEFAULT 'not_started',
  amount_cents INTEGER NOT NULL,
  commission_cents INTEGER NOT NULL DEFAULT 0,
  currency TEXT NOT NULL DEFAULT 'EUR',
  card_alias TEXT NULL,
  provider_status TEXT NULL,
  date_add TEXT NOT NULL,
  date_upd TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_mangopay_user (
  id_marketplace_mangopay_user INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_vendor INTEGER NOT NULL UNIQUE,
  provider_id TEXT NOT NULL UNIQUE,
  legal_type TEXT NOT NULL,
  kyc_status TEXT NOT NULL,
  email TEXT NOT NULL,
  date_add TEXT NOT NULL,
  date_upd TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_mangopay_wallet (
  id_marketplace_mangopay_wallet INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_vendor INTEGER NOT NULL,
  provider_user_id TEXT NOT NULL,
  provider_wallet_id TEXT NOT NULL UNIQUE,
  currency TEXT NOT NULL,
  date_add TEXT NOT NULL,
  UNIQUE (id_marketplace_vendor, currency)
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_refund (
  id_marketplace_refund INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_payment INTEGER NOT NULL,
  id_marketplace_order INTEGER NOT NULL,
  id_marketplace_vendor INTEGER NOT NULL,
  id_marketplace_vendor_order INTEGER NOT NULL,
  amount_cents INTEGER NOT NULL,
  commission_cents INTEGER NOT NULL,
  status TEXT NOT NULL,
  provider_id TEXT NULL,
  date_add TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_payout (
  id_marketplace_payout INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_vendor INTEGER NOT NULL,
  amount_cents INTEGER NOT NULL,
  status TEXT NOT NULL,
  provider_id TEXT NULL,
  idempotency_key TEXT NOT NULL UNIQUE,
  date_add TEXT NOT NULL,
  date_upd TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_webhook_event (
  id_marketplace_webhook_event INTEGER PRIMARY KEY AUTOINCREMENT,
  event_type TEXT NOT NULL,
  resource_id TEXT NOT NULL,
  payload_hash TEXT NOT NULL,
  status TEXT NOT NULL,
  date_add TEXT NOT NULL,
  UNIQUE (event_type, resource_id)
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_reconciliation_line (
  id_marketplace_reconciliation_line INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_payment INTEGER NULL,
  id_marketplace_refund INTEGER NULL,
  kind TEXT NOT NULL,
  amount_cents INTEGER NOT NULL,
  note TEXT NOT NULL,
  date_add TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_import_schedule (
  id_marketplace_import_schedule INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_vendor INTEGER NOT NULL,
  filename TEXT NOT NULL,
  csv_body TEXT NOT NULL,
  interval_minutes INTEGER NOT NULL,
  last_run INTEGER NULL,
  active INTEGER NOT NULL DEFAULT 1,
  date_add TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_invoice (
  id_marketplace_invoice INTEGER PRIMARY KEY AUTOINCREMENT,
  issuer_role TEXT NOT NULL,
  id_marketplace_vendor INTEGER NOT NULL,
  id_marketplace_order INTEGER NULL,
  id_marketplace_vendor_order INTEGER NULL,
  kind TEXT NOT NULL,
  status TEXT NOT NULL,
  number TEXT NOT NULL UNIQUE,
  total_cents INTEGER NOT NULL,
  date_add TEXT NOT NULL,
  date_upd TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_invoice_file (
  id_marketplace_invoice_file INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_invoice INTEGER NOT NULL UNIQUE,
  stored_name TEXT NOT NULL,
  sha256 TEXT NOT NULL,
  bytes INTEGER NOT NULL,
  mime TEXT NOT NULL,
  date_add TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_audit_log (
  id_marketplace_audit_log INTEGER PRIMARY KEY AUTOINCREMENT,
  actor_role TEXT NOT NULL,
  actor_id INTEGER NOT NULL DEFAULT 0,
  action TEXT NOT NULL,
  subject TEXT NOT NULL,
  detail TEXT NOT NULL,
  date_add TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_api_credential (
  id_marketplace_api_credential INTEGER PRIMARY KEY AUTOINCREMENT,
  role TEXT NOT NULL,
  subject_id INTEGER NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  expires_at INTEGER NOT NULL,
  date_add TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_api_permission (
  role TEXT NOT NULL,
  permission TEXT NOT NULL,
  PRIMARY KEY (role, permission)
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_idempotency (
  id_marketplace_idempotency INTEGER PRIMARY KEY AUTOINCREMENT,
  actor_key TEXT NOT NULL,
  idempotency_key TEXT NOT NULL,
  request_hash TEXT NOT NULL,
  status_code INTEGER NOT NULL,
  response_json TEXT NOT NULL,
  date_add TEXT NOT NULL,
  UNIQUE (actor_key, idempotency_key)
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_api_hit (
  id_marketplace_api_hit INTEGER PRIMARY KEY AUTOINCREMENT,
  token_hash TEXT NOT NULL,
  created_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS mp_api_hit_token ON PREFIX_marketplace_api_hit (token_hash, created_at);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_order_event (
  id_marketplace_order_event INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_order INTEGER NOT NULL,
  status TEXT NOT NULL,
  note TEXT NOT NULL,
  date_add TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS mp_offer_product_active ON PREFIX_marketplace_vendor_offer (id_product, active, price_cents);
CREATE INDEX IF NOT EXISTS mp_offer_ean ON PREFIX_marketplace_vendor_offer (ean13);
CREATE INDEX IF NOT EXISTS mp_order_shopper ON PREFIX_marketplace_order (id_marketplace_shopper);
CREATE INDEX IF NOT EXISTS mp_reservation_offer_status ON PREFIX_marketplace_stock_reservation (id_marketplace_vendor_offer, status);
CREATE UNIQUE INDEX IF NOT EXISTS mp_shopper_customer ON PREFIX_marketplace_shopper (id_customer);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_customer_link (
  id_marketplace_customer_link INTEGER PRIMARY KEY AUTOINCREMENT,
  id_marketplace_shopper INTEGER NOT NULL,
  id_customer INTEGER NOT NULL,
  code_hash TEXT NOT NULL,
  expires_at INTEGER NOT NULL,
  consumed INTEGER NOT NULL DEFAULT 0,
  date_add TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS PREFIX_marketplace_api_reset (
  id_marketplace_api_reset INTEGER PRIMARY KEY AUTOINCREMENT,
  role TEXT NOT NULL,
  email TEXT NOT NULL,
  token_hash TEXT NOT NULL,
  expires_at INTEGER NOT NULL,
  consumed INTEGER NOT NULL DEFAULT 0,
  date_add TEXT NOT NULL
);
