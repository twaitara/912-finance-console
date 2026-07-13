-- ============================================================================
-- Migration: Project costs (Quote -> Project -> Invoice)
-- Date: 2026-07-13
-- Target: your existing 912 console database (e.g. tonykett_912WC) on cPanel
--
-- You do NOT strictly need to run this — the app auto-creates the table and
-- columns on first use. Run it only if you prefer to apply the change manually
-- in phpMyAdmin. It is safe to run on an existing database.
--
-- HOW TO IMPORT (phpMyAdmin):
--   1. cPanel -> phpMyAdmin -> pick your 912 database on the left.
--   2. "Import" tab -> choose this file -> Go.  (Or paste it into the "SQL" tab.)
--
-- Note: the two ALTER statements use "IF NOT EXISTS" (works on MariaDB, which
-- cPanel uses). If your server is MySQL and rejects that, either remove the
-- "IF NOT EXISTS" words, or just skip the ALTERs — the app adds those columns
-- itself on first request.
-- ============================================================================

-- 1) New table: one row per actual cost captured against a project ------------
CREATE TABLE IF NOT EXISTS `project_costs` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `quote_id`        INT NOT NULL,
  `line_index`      INT NOT NULL DEFAULT 0,
  `line_name`       VARCHAR(190) DEFAULT '',
  `category`        VARCHAR(40)  DEFAULT 'other',       -- parts | labour | consumables | subcontract | other
  `description`     VARCHAR(190) DEFAULT '',
  `qty`             DECIMAL(12,2) DEFAULT 1,
  `unit_cost`       DECIMAL(14,2) DEFAULT 0,
  `amount`          DECIMAL(14,2) DEFAULT 0,            -- qty * unit_cost
  `zoho_expense_id` VARCHAR(64)  DEFAULT '',            -- filled when pushed to Zoho at billing
  `created_by`      VARCHAR(80)  DEFAULT '',
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (`quote_id`),
  INDEX (`quote_id`, `line_index`),
  INDEX (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Cache actual cost/profit on the quote for fast list rendering ------------
ALTER TABLE `quotes`
  ADD COLUMN IF NOT EXISTS `actual_cost`   DECIMAL(14,2) DEFAULT 0 AFTER `profit`,
  ADD COLUMN IF NOT EXISTS `actual_profit` DECIMAL(14,2) DEFAULT 0 AFTER `actual_cost`;
