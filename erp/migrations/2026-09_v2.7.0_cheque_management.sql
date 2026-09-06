-- ============================================================================
-- ADA PHARMA ERP — Migration v2.7.0 : Cheque Management (سیستم مدیریت چک)
-- تاریخ: شهریور ۱۴۰۵
-- توجه: این SQL فقط برای نسخه‌بندی/مرجع است؛ نصب واقعی با deploy_deploy_cheques.php
--        انجام می‌شود (که بکاپ می‌گیرد، جداول را idempotent می‌سازد و گرته می‌زند).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `checks` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `kind` ENUM('payment','guarantee') NOT NULL DEFAULT 'payment',
  `direction` ENUM('received','issued') NOT NULL,
  `cheque_number` VARCHAR(50) NULL,
  `sayyad_id` VARCHAR(20) NULL,
  `series` VARCHAR(30) NULL,
  `serial` VARCHAR(30) NULL,
  `bank_name` VARCHAR(100) NULL,
  `branch_name` VARCHAR(150) NULL,
  `branch_code` VARCHAR(20) NULL,
  `bank_account_id` BIGINT UNSIGNED NULL,
  `party_type` ENUM('customer','supplier','other') NOT NULL DEFAULT 'other',
  `customer_id` BIGINT UNSIGNED NULL,
  `supplier_id` BIGINT UNSIGNED NULL,
  `party_name` VARCHAR(200) NULL,
  `amount` DECIMAL(20,0) NOT NULL DEFAULT 0,
  `issue_date` DATE NULL,
  `due_date` DATE NULL,
  `guarantee_reason` VARCHAR(255) NULL,
  `guarantee_return_date` DATE NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'in_hand',
  `ref_type` VARCHAR(30) NULL,
  `ref_id` VARCHAR(50) NULL,
  `image_front` VARCHAR(255) NULL,
  `image_back` VARCHAR(255) NULL,
  `description` TEXT NULL,
  `checkbook_id` BIGINT UNSIGNED NULL,
  `locked_at` DATETIME NULL,
  `locked_by` BIGINT UNSIGNED NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  `deleted_at` DATETIME NULL,
  UNIQUE KEY `uq_sayyad` (`sayyad_id`),
  KEY `idx_status` (`status`),
  KEY `idx_due` (`due_date`),
  KEY `idx_direction` (`direction`),
  KEY `idx_kind` (`kind`),
  KEY `idx_party_customer` (`customer_id`),
  KEY `idx_party_supplier` (`supplier_id`),
  KEY `idx_bank_account` (`bank_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `checkbooks` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `bank_account_id` BIGINT UNSIGNED NULL,
  `series` VARCHAR(30) NULL,
  `start_number` BIGINT NOT NULL,
  `end_number` BIGINT NOT NULL,
  `received_date` DATE NULL,
  `status` ENUM('active','finished','cancelled') NOT NULL DEFAULT 'active',
  `notes` VARCHAR(255) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- لجر رویدادها — append-only با زنجیره hash (مقاوم در برابر دستکاری)
CREATE TABLE IF NOT EXISTS `check_events` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `check_id` BIGINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(30) NOT NULL,
  `event_date` DATE NOT NULL,
  `amount` DECIMAL(20,0) NULL,
  `bank_ref` VARCHAR(100) NULL,
  `note` TEXT NULL,
  `attachment` VARCHAR(255) NULL,
  `approval_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `approved_by` BIGINT UNSIGNED NULL,
  `approved_at` DATETIME NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL,
  `prev_hash` CHAR(64) NULL,
  `hash` CHAR(64) NOT NULL,
  KEY `idx_check` (`check_id`),
  KEY `idx_approval` (`approval_status`),
  KEY `idx_date` (`event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- انتقال/خرج چک دریافتی به طرف دیگر
CREATE TABLE IF NOT EXISTS `check_endorsements` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `check_id` BIGINT UNSIGNED NOT NULL,
  `to_party_type` ENUM('supplier','customer','other') NOT NULL DEFAULT 'other',
  `to_supplier_id` BIGINT UNSIGNED NULL,
  `to_customer_id` BIGINT UNSIGNED NULL,
  `to_name` VARCHAR(200) NULL,
  `endorsement_date` DATE NULL,
  `ref_type` VARCHAR(30) NULL,
  `ref_id` VARCHAR(50) NULL,
  `amount` DECIMAL(20,0) NULL,
  `note` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL,
  KEY `idx_check` (`check_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- اتصال چک به تراکنش‌های بانکی (برای تطبیق خودکار در فاز بعد)
-- ALTER TABLE `bank_transactions` ADD COLUMN `check_id` BIGINT UNSIGNED NULL;
-- (اسکریپت نصب به‌صورت ایمن و فقط در صورت نبود ستون این کار را می‌کند)

-- INSERT INTO schema_migrations (version, name, applied_at)
-- VALUES ('v2.7.0', 'Cheque management system', NOW());
