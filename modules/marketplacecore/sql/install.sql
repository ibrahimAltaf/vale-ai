CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_vendor` (
  `id_marketplace_vendor` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(8) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `commission_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `email` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `contact_name` VARCHAR(255) NULL,
  `phone` VARCHAR(64) NULL,
  `date_add` DATETIME NOT NULL,
  `date_upd` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_vendor`),
  UNIQUE KEY `mp_vendor_code` (`code`),
  UNIQUE KEY `mp_vendor_slug` (`slug`),
  UNIQUE KEY `mp_vendor_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_shipping_rule` (
  `id_marketplace_shipping_rule` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_vendor` INT UNSIGNED NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `base_cents` INT UNSIGNED NOT NULL DEFAULT 0,
  `free_threshold_cents` INT UNSIGNED NULL,
  `surcharge_cents_per_kg` INT UNSIGNED NOT NULL DEFAULT 0,
  `postcode_prefixes` VARCHAR(255) NOT NULL DEFAULT '',
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `date_add` DATETIME NOT NULL,
  `date_upd` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_shipping_rule`),
  KEY `mp_shipping_vendor` (`id_marketplace_vendor`),
  CONSTRAINT `fk_mp_shipping_vendor` FOREIGN KEY (`id_marketplace_vendor`) REFERENCES `PREFIX_marketplace_vendor` (`id_marketplace_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_vendor_offer` (
  `id_marketplace_vendor_offer` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_vendor` INT UNSIGNED NOT NULL,
  `id_product` INT UNSIGNED NOT NULL,
  `vendor_sku` VARCHAR(64) NOT NULL,
  `ean13` VARCHAR(32) NULL,
  `brand` VARCHAR(128) NULL,
  `title` VARCHAR(255) NULL,
  `description` TEXT NULL,
  `price_cents` INT UNSIGNED NOT NULL,
  `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `weight_grams` INT UNSIGNED NOT NULL DEFAULT 0,
  `image_url` VARCHAR(512) NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `date_add` DATETIME NOT NULL,
  `date_upd` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_vendor_offer`),
  UNIQUE KEY `mp_offer_vendor_sku` (`id_marketplace_vendor`, `vendor_sku`),
  KEY `mp_offer_product` (`id_product`),
  KEY `mp_offer_ean` (`ean13`),
  CONSTRAINT `fk_mp_offer_vendor` FOREIGN KEY (`id_marketplace_vendor`) REFERENCES `PREFIX_marketplace_vendor` (`id_marketplace_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_vendor_stock` (
  `id_marketplace_vendor_stock` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_vendor_offer` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL DEFAULT 0,
  `reserved_quantity` INT NOT NULL DEFAULT 0,
  `low_stock_threshold` INT NOT NULL DEFAULT 3,
  `date_upd` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_vendor_stock`),
  UNIQUE KEY `mp_stock_offer` (`id_marketplace_vendor_offer`),
  CONSTRAINT `fk_mp_stock_offer` FOREIGN KEY (`id_marketplace_vendor_offer`) REFERENCES `PREFIX_marketplace_vendor_offer` (`id_marketplace_vendor_offer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_product_match` (
  `id_marketplace_product_match` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_vendor` INT UNSIGNED NOT NULL,
  `vendor_sku` VARCHAR(64) NOT NULL,
  `ean13` VARCHAR(32) NULL,
  `id_product` INT UNSIGNED NULL,
  `match_type` VARCHAR(32) NOT NULL,
  `match_key` VARCHAR(32) NOT NULL,
  `candidate_product_ids` VARCHAR(255) NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_product_match`),
  KEY `mp_match_vendor` (`id_marketplace_vendor`),
  CONSTRAINT `fk_mp_match_vendor` FOREIGN KEY (`id_marketplace_vendor`) REFERENCES `PREFIX_marketplace_vendor` (`id_marketplace_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_collection` (
  `id_marketplace_collection` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `slug` VARCHAR(128) NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `date_start` DATETIME NULL,
  `date_end` DATETIME NULL,
  `date_add` DATETIME NOT NULL,
  `date_upd` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_collection`),
  UNIQUE KEY `mp_collection_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_collection_product` (
  `id_marketplace_collection` INT UNSIGNED NOT NULL,
  `id_product` INT UNSIGNED NOT NULL,
  `position` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_marketplace_collection`, `id_product`),
  CONSTRAINT `fk_mp_collection_product` FOREIGN KEY (`id_marketplace_collection`) REFERENCES `PREFIX_marketplace_collection` (`id_marketplace_collection`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_cart` (
  `id_marketplace_cart` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token` VARCHAR(64) NOT NULL,
  `postcode` VARCHAR(16) NOT NULL DEFAULT '',
  `date_upd` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_cart`),
  UNIQUE KEY `mp_cart_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_cart_line` (
  `id_marketplace_cart_line` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_cart` INT UNSIGNED NOT NULL,
  `id_product` INT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id_marketplace_cart_line`),
  UNIQUE KEY `mp_cart_product` (`id_marketplace_cart`, `id_product`),
  CONSTRAINT `fk_mp_cart_line` FOREIGN KEY (`id_marketplace_cart`) REFERENCES `PREFIX_marketplace_cart` (`id_marketplace_cart`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_order` (
  `id_marketplace_order` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference` VARCHAR(32) NOT NULL,
  `id_marketplace_cart` INT UNSIGNED NULL,
  `postcode` VARCHAR(16) NOT NULL,
  `product_cents` INT NOT NULL,
  `shipping_cents` INT NOT NULL,
  `grand_cents` INT NOT NULL,
  `optimization_status` VARCHAR(64) NOT NULL,
  `optimization_note` TEXT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'confirmed',
  `id_customer` INT UNSIGNED NULL,
  `payment_method` VARCHAR(64) NULL,
  `payment_status` VARCHAR(32) NOT NULL DEFAULT 'recorded',
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_order`),
  UNIQUE KEY `mp_order_reference` (`reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_vendor_order` (
  `id_marketplace_vendor_order` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_order` INT UNSIGNED NOT NULL,
  `reference` VARCHAR(32) NOT NULL,
  `parent_reference` VARCHAR(32) NOT NULL,
  `id_marketplace_vendor` INT UNSIGNED NOT NULL,
  `product_cents` INT NOT NULL,
  `shipping_cents` INT NOT NULL,
  `grand_cents` INT NOT NULL,
  `postcode` VARCHAR(16) NOT NULL,
  `optimization_status` VARCHAR(64) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'confirmed',
  `id_order` INT UNSIGNED NULL,
  `shop_reference` VARCHAR(64) NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_vendor_order`),
  UNIQUE KEY `mp_vendor_order_reference` (`reference`),
  KEY `mp_vendor_order_parent` (`parent_reference`),
  KEY `mp_vendor_order_vendor` (`id_marketplace_vendor`),
  CONSTRAINT `fk_mp_vendor_order_parent` FOREIGN KEY (`id_marketplace_order`) REFERENCES `PREFIX_marketplace_order` (`id_marketplace_order`),
  CONSTRAINT `fk_mp_vendor_order_vendor` FOREIGN KEY (`id_marketplace_vendor`) REFERENCES `PREFIX_marketplace_vendor` (`id_marketplace_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_vendor_order_line` (
  `id_marketplace_vendor_order_line` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_vendor_order` INT UNSIGNED NOT NULL,
  `id_marketplace_vendor_offer` INT UNSIGNED NOT NULL,
  `id_product` INT UNSIGNED NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `unit_price_cents` INT NOT NULL,
  `line_total_cents` INT NOT NULL,
  PRIMARY KEY (`id_marketplace_vendor_order_line`),
  KEY `mp_order_line_order` (`id_marketplace_vendor_order`),
  CONSTRAINT `fk_mp_order_line_order` FOREIGN KEY (`id_marketplace_vendor_order`) REFERENCES `PREFIX_marketplace_vendor_order` (`id_marketplace_vendor_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_stock_reservation` (
  `id_marketplace_stock_reservation` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_vendor_offer` INT UNSIGNED NOT NULL,
  `id_marketplace_vendor_order` INT UNSIGNED NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `expires_at` DATETIME NOT NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_stock_reservation`),
  KEY `mp_reservation_offer` (`id_marketplace_vendor_offer`, `status`),
  CONSTRAINT `fk_mp_reservation_offer` FOREIGN KEY (`id_marketplace_vendor_offer`) REFERENCES `PREFIX_marketplace_vendor_offer` (`id_marketplace_vendor_offer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_commission` (
  `id_marketplace_commission` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_vendor` INT UNSIGNED NOT NULL,
  `id_marketplace_vendor_order` INT UNSIGNED NULL,
  `gross_cents` INT NOT NULL,
  `commission_percent` DECIMAL(5,2) NOT NULL,
  `commission_cents` INT NOT NULL,
  `net_cents` INT NOT NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_commission`),
  KEY `mp_commission_vendor` (`id_marketplace_vendor`),
  CONSTRAINT `fk_mp_commission_vendor` FOREIGN KEY (`id_marketplace_vendor`) REFERENCES `PREFIX_marketplace_vendor` (`id_marketplace_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_settlement` (
  `id_marketplace_settlement` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_vendor` INT UNSIGNED NOT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `gross_cents` INT NOT NULL,
  `commission_cents` INT NOT NULL,
  `net_cents` INT NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'open',
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_settlement`),
  KEY `mp_settlement_vendor` (`id_marketplace_vendor`, `status`),
  CONSTRAINT `fk_mp_settlement_vendor` FOREIGN KEY (`id_marketplace_vendor`) REFERENCES `PREFIX_marketplace_vendor` (`id_marketplace_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_import_job` (
  `id_marketplace_import_job` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_vendor` INT UNSIGNED NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `rows_total` INT NOT NULL DEFAULT 0,
  `rows_created` INT NOT NULL DEFAULT 0,
  `rows_updated` INT NOT NULL DEFAULT 0,
  `rows_failed` INT NOT NULL DEFAULT 0,
  `error_log` TEXT NULL,
  `date_add` DATETIME NOT NULL,
  `date_upd` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_import_job`),
  KEY `mp_import_vendor` (`id_marketplace_vendor`),
  CONSTRAINT `fk_mp_import_vendor` FOREIGN KEY (`id_marketplace_vendor`) REFERENCES `PREFIX_marketplace_vendor` (`id_marketplace_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_marketplace_password_reset` (
  `id_marketplace_password_reset` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_marketplace_vendor` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `date_add` DATETIME NOT NULL,
  PRIMARY KEY (`id_marketplace_password_reset`),
  UNIQUE KEY `mp_password_reset_token` (`token_hash`),
  KEY `mp_password_reset_vendor` (`id_marketplace_vendor`),
  CONSTRAINT `fk_mp_password_reset_vendor` FOREIGN KEY (`id_marketplace_vendor`) REFERENCES `PREFIX_marketplace_vendor` (`id_marketplace_vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
