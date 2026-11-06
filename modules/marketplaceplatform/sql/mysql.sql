CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_master_product` (
  `id_marketplace_master_product` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ean13` VARCHAR(32) NULL,
  `sku` VARCHAR(64) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_master_product`),
  UNIQUE KEY `mp_master_ean` (`ean13`),
  KEY `mp_master_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_shopper` (
  `id_marketplace_shopper` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(128) NOT NULL,
  `last_name` VARCHAR(128) NOT NULL,
  `mangopay_user_id` VARCHAR(64) NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_shopper`),
  UNIQUE KEY `mp_shopper_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_payment` (
  `id_marketplace_payment` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_order` INT UNSIGNED NOT NULL,
  `id_marketplace_shopper` INT UNSIGNED NOT NULL,
  `provider` VARCHAR(32) NOT NULL DEFAULT 'mangopay',
  `provider_id` VARCHAR(64) NULL,
  `idempotency_key` VARCHAR(80) NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `settlement_status` VARCHAR(32) NOT NULL DEFAULT 'not_started',
  `amount_cents` INT NOT NULL,
  `commission_cents` INT NOT NULL DEFAULT 0,
  `currency` CHAR(3) NOT NULL DEFAULT 'EUR',
  `card_alias` VARCHAR(64) NULL,
  `provider_status` VARCHAR(64) NULL,
  `date_add` DATETIME NOT NULL,
  `date_upd` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_payment`),
  UNIQUE KEY `mp_payment_idempotency` (`idempotency_key`),
  UNIQUE KEY `mp_payment_provider` (`provider`, `provider_id`),
  KEY `mp_payment_order` (`id_marketplace_order`),
  CONSTRAINT `fk_mp_payment_order` FOREIGN KEY (`id_marketplace_order`) REFERENCES `PREFIX_marketplace_order` (`id_marketplace_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_mangopay_user` (
  `id_marketplace_mangopay_user` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_vendor` INT UNSIGNED NOT NULL,
  `provider_id` VARCHAR(64) NOT NULL,
  `legal_type` VARCHAR(32) NOT NULL,
  `kyc_status` VARCHAR(32) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `date_add` DATETIME NOT NULL,
  `date_upd` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_mangopay_user`),
  UNIQUE KEY `mp_mangopay_vendor` (`id_marketplace_vendor`),
  UNIQUE KEY `mp_mangopay_provider_user` (`provider_id`),
  CONSTRAINT `fk_mp_mangopay_vendor` FOREIGN KEY (`id_marketplace_vendor`) REFERENCES `PREFIX_marketplace_vendor` (`id_marketplace_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_mangopay_wallet` (
  `id_marketplace_mangopay_wallet` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_vendor` INT UNSIGNED NOT NULL,
  `provider_user_id` VARCHAR(64) NOT NULL,
  `provider_wallet_id` VARCHAR(64) NOT NULL,
  `currency` CHAR(3) NOT NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_mangopay_wallet`),
  UNIQUE KEY `mp_wallet_vendor_currency` (`id_marketplace_vendor`, `currency`),
  UNIQUE KEY `mp_wallet_provider` (`provider_wallet_id`),
  CONSTRAINT `fk_mp_wallet_vendor` FOREIGN KEY (`id_marketplace_vendor`) REFERENCES `PREFIX_marketplace_vendor` (`id_marketplace_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_refund` (
  `id_marketplace_refund` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_payment` INT UNSIGNED NOT NULL,
  `id_marketplace_order` INT UNSIGNED NOT NULL,
  `id_marketplace_vendor` INT UNSIGNED NOT NULL,
  `id_marketplace_vendor_order` INT UNSIGNED NOT NULL,
  `amount_cents` INT NOT NULL,
  `commission_cents` INT NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `provider_id` VARCHAR(64) NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_refund`),
  KEY `mp_refund_order` (`id_marketplace_order`),
  KEY `mp_refund_payment` (`id_marketplace_payment`),
  CONSTRAINT `fk_mp_refund_payment` FOREIGN KEY (`id_marketplace_payment`) REFERENCES `PREFIX_marketplace_payment` (`id_marketplace_payment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_payout` (
  `id_marketplace_payout` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_vendor` INT UNSIGNED NOT NULL,
  `amount_cents` INT NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `provider_id` VARCHAR(64) NULL,
  `idempotency_key` VARCHAR(80) NOT NULL,
  `date_add` DATETIME NOT NULL,
  `date_upd` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_payout`),
  UNIQUE KEY `mp_payout_idempotency` (`idempotency_key`),
  KEY `mp_payout_vendor` (`id_marketplace_vendor`, `status`),
  CONSTRAINT `fk_mp_payout_vendor` FOREIGN KEY (`id_marketplace_vendor`) REFERENCES `PREFIX_marketplace_vendor` (`id_marketplace_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_webhook_event` (
  `id_marketplace_webhook_event` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` VARCHAR(80) NOT NULL,
  `resource_id` VARCHAR(64) NOT NULL,
  `payload_hash` CHAR(64) NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_webhook_event`),
  UNIQUE KEY `mp_webhook_resource` (`event_type`, `resource_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_reconciliation_line` (
  `id_marketplace_reconciliation_line` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_payment` INT UNSIGNED NULL,
  `id_marketplace_refund` INT UNSIGNED NULL,
  `kind` VARCHAR(32) NOT NULL,
  `amount_cents` INT NOT NULL,
  `note` VARCHAR(255) NOT NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_reconciliation_line`),
  KEY `mp_recon_payment` (`id_marketplace_payment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_import_schedule` (
  `id_marketplace_import_schedule` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_vendor` INT UNSIGNED NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `csv_body` MEDIUMTEXT NOT NULL,
  `interval_minutes` INT UNSIGNED NOT NULL,
  `last_run` INT UNSIGNED NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_import_schedule`),
  KEY `mp_import_schedule_vendor` (`id_marketplace_vendor`, `active`),
  CONSTRAINT `fk_mp_import_schedule_vendor` FOREIGN KEY (`id_marketplace_vendor`) REFERENCES `PREFIX_marketplace_vendor` (`id_marketplace_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_invoice` (
  `id_marketplace_invoice` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issuer_role` VARCHAR(32) NOT NULL,
  `id_marketplace_vendor` INT UNSIGNED NOT NULL,
  `id_marketplace_order` INT UNSIGNED NULL,
  `id_marketplace_vendor_order` INT UNSIGNED NULL,
  `kind` VARCHAR(32) NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `number` VARCHAR(64) NOT NULL,
  `total_cents` INT NOT NULL,
  `date_add` DATETIME NOT NULL,
  `date_upd` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_invoice`),
  UNIQUE KEY `mp_invoice_number` (`number`),
  KEY `mp_invoice_vendor` (`id_marketplace_vendor`, `status`),
  KEY `mp_invoice_order` (`id_marketplace_order`),
  CONSTRAINT `fk_mp_invoice_vendor` FOREIGN KEY (`id_marketplace_vendor`) REFERENCES `PREFIX_marketplace_vendor` (`id_marketplace_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_invoice_file` (
  `id_marketplace_invoice_file` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_invoice` INT UNSIGNED NOT NULL,
  `stored_name` VARCHAR(64) NOT NULL,
  `sha256` CHAR(64) NOT NULL,
  `bytes` INT UNSIGNED NOT NULL,
  `mime` VARCHAR(64) NOT NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_invoice_file`),
  UNIQUE KEY `mp_invoice_file_invoice` (`id_marketplace_invoice`),
  CONSTRAINT `fk_mp_invoice_file` FOREIGN KEY (`id_marketplace_invoice`) REFERENCES `PREFIX_marketplace_invoice` (`id_marketplace_invoice`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_audit_log` (
  `id_marketplace_audit_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_role` VARCHAR(32) NOT NULL,
  `actor_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `action` VARCHAR(64) NOT NULL,
  `subject` VARCHAR(128) NOT NULL,
  `detail` VARCHAR(512) NOT NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_audit_log`),
  KEY `mp_audit_actor` (`actor_role`, `actor_id`),
  KEY `mp_audit_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_api_credential` (
  `id_marketplace_api_credential` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role` VARCHAR(32) NOT NULL,
  `subject_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` INT UNSIGNED NOT NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_api_credential`),
  UNIQUE KEY `mp_api_token` (`token_hash`),
  KEY `mp_api_subject` (`role`, `subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_api_permission` (
  `role` VARCHAR(32) NOT NULL,
  `permission` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`role`, `permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_idempotency` (
  `id_marketplace_idempotency` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_key` VARCHAR(80) NOT NULL,
  `idempotency_key` VARCHAR(80) NOT NULL,
  `request_hash` CHAR(64) NOT NULL,
  `status_code` INT NOT NULL,
  `response_json` MEDIUMTEXT NOT NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_idempotency`),
  UNIQUE KEY `mp_idempotency_actor` (`actor_key`, `idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_api_hit` (
  `id_marketplace_api_hit` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_hash` CHAR(64) NOT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id_marketplace_api_hit`),
  KEY `mp_api_hit_token` (`token_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_order_event` (
  `id_marketplace_order_event` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_order` INT UNSIGNED NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `note` VARCHAR(255) NOT NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_order_event`),
  KEY `mp_order_event_order` (`id_marketplace_order`),
  CONSTRAINT `fk_mp_order_event_order` FOREIGN KEY (`id_marketplace_order`) REFERENCES `PREFIX_marketplace_order` (`id_marketplace_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `PREFIX_marketplace_order` ADD COLUMN `id_marketplace_shopper` INT UNSIGNED NULL;

ALTER TABLE `PREFIX_marketplace_vendor_order` ADD COLUMN `fulfillment_status` VARCHAR(32) NOT NULL DEFAULT 'pending';

ALTER TABLE `PREFIX_marketplace_vendor_offer` ADD INDEX `mp_offer_product_active` (`id_product`, `active`, `price_cents`);

ALTER TABLE `PREFIX_marketplace_order` ADD INDEX `mp_order_shopper` (`id_marketplace_shopper`);

ALTER TABLE `PREFIX_marketplace_stock_reservation` ADD INDEX `mp_reservation_expiry` (`status`, `expires_at`);
