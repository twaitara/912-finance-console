-- 912 Console — canonical database schema
-- GENERATED from the app's runtime ensure-functions (CREATE TABLE / ALTER ADD).
-- The app still auto-creates these at runtime (self-healing); this file is the
-- single source of truth for review + fresh installs. Keep it in sync when you
-- add a dated migration under sql/. Charset utf8mb4 / ENGINE=InnoDB throughout.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- ----------------------------------------------------------------------------
-- funds
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS funds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL DEFAULT '',
        balance DECIMAL(14,2) NOT NULL DEFAULT 0,
        is_primary TINYINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- fund_loans
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fund_loans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fund_id INT NOT NULL DEFAULT 0,
        borrower_type VARCHAR(20) NOT NULL DEFAULT 'person',
        borrower_name VARCHAR(190) NOT NULL DEFAULT '',
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        repaid DECIMAL(14,2) NOT NULL DEFAULT 0,
        loan_date DATE NULL,
        expected_date DATE NULL,
        note VARCHAR(500) DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'Open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- columns added by later migrations:
ALTER TABLE fund_loans ADD COLUMN fund_id INT NOT NULL DEFAULT 0 AFTER id;

-- ----------------------------------------------------------------------------
-- fund_loan_repayments
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fund_loan_repayments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        loan_id INT NOT NULL,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        repaid_date DATE NULL,
        note VARCHAR(300) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- quotes
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS quotes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        created_by VARCHAR(80) NOT NULL,
        created_email VARCHAR(190) DEFAULT '',
        zoho_customer_id VARCHAR(64) DEFAULT '',
        customer_name VARCHAR(190) DEFAULT '',
        currency VARCHAR(8) DEFAULT 'KES',
        line_items LONGTEXT,
        notes TEXT,
        sub_total DECIMAL(14,2) DEFAULT 0,
        tax_amount DECIMAL(14,2) DEFAULT 0,
        total DECIMAL(14,2) DEFAULT 0,
        status VARCHAR(32) DEFAULT 'local_draft',
        zoho_estimate_id VARCHAR(64) DEFAULT '',
        zoho_estimate_number VARCHAR(64) DEFAULT '',
        last_synced_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- columns added by later migrations:
ALTER TABLE quotes ADD COLUMN zoho_invoice_id VARCHAR(64) DEFAULT '' AFTER zoho_estimate_number;
ALTER TABLE quotes ADD COLUMN zoho_invoice_number VARCHAR(64) DEFAULT '' AFTER zoho_invoice_id;
ALTER TABLE quotes ADD COLUMN reference VARCHAR(80) DEFAULT '' AFTER customer_name;
ALTER TABLE quotes ADD COLUMN subject VARCHAR(190) DEFAULT '' AFTER reference;
ALTER TABLE quotes ADD COLUMN quote_date DATE NULL AFTER subject;
ALTER TABLE quotes ADD COLUMN expiry_date DATE NULL AFTER quote_date;
ALTER TABLE quotes ADD COLUMN terms TEXT AFTER notes;
ALTER TABLE quotes ADD COLUMN discount_value DECIMAL(12,2) DEFAULT 0 AFTER tax_amount;
ALTER TABLE quotes ADD COLUMN discount_type VARCHAR(10) DEFAULT 'percent' AFTER discount_value;
ALTER TABLE quotes ADD COLUMN discount_amount DECIMAL(14,2) DEFAULT 0 AFTER discount_type;
ALTER TABLE quotes ADD COLUMN total_cost DECIMAL(14,2) DEFAULT 0 AFTER discount_amount;
ALTER TABLE quotes ADD COLUMN profit DECIMAL(14,2) DEFAULT 0 AFTER total_cost;
ALTER TABLE quotes ADD COLUMN actual_cost DECIMAL(14,2) DEFAULT 0 AFTER profit;
ALTER TABLE quotes ADD COLUMN actual_profit DECIMAL(14,2) DEFAULT 0 AFTER actual_cost;
ALTER TABLE quotes ADD COLUMN is_project TINYINT NOT NULL DEFAULT 0 AFTER actual_profit;
ALTER TABLE quotes ADD COLUMN project_closed TINYINT NOT NULL DEFAULT 0 AFTER is_project;
ALTER TABLE quotes ADD COLUMN imported TINYINT NOT NULL DEFAULT 0 AFTER project_closed;

-- ----------------------------------------------------------------------------
-- project_costs
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_costs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quote_id INT NOT NULL,
        line_index INT NOT NULL DEFAULT 0,
        line_name VARCHAR(190) DEFAULT '',
        category VARCHAR(40) DEFAULT 'other',
        description VARCHAR(190) DEFAULT '',
        qty DECIMAL(12,2) DEFAULT 1,
        unit_cost DECIMAL(14,2) DEFAULT 0,
        amount DECIMAL(14,2) DEFAULT 0,
        zoho_expense_id VARCHAR(64) DEFAULT '',
        created_by VARCHAR(80) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (quote_id), INDEX (quote_id, line_index), INDEX (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- columns added by later migrations:
ALTER TABLE project_costs ADD COLUMN vat_mode VARCHAR(4) DEFAULT 'excl' AFTER amount;

-- ----------------------------------------------------------------------------
-- project_payments
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quote_id INT NOT NULL,
        amount DECIMAL(14,2) DEFAULT 0,
        paid_date DATE NULL,
        note VARCHAR(190) DEFAULT '',
        created_by VARCHAR(80) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (quote_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- project_assignees
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_assignees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quote_id INT NOT NULL,
        username VARCHAR(80) NOT NULL,
        assigned_by VARCHAR(80) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pa (quote_id, username),
        INDEX (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- app_users
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL UNIQUE,
        pass_hash VARCHAR(255) NOT NULL,
        email VARCHAR(190) DEFAULT '',
        tabs TEXT,
        is_admin TINYINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- columns added by later migrations:
ALTER TABLE app_users ADD COLUMN email VARCHAR(190) DEFAULT '' AFTER pass_hash;
ALTER TABLE app_users ADD COLUMN disabled TINYINT NOT NULL DEFAULT 0 AFTER is_admin;

-- ----------------------------------------------------------------------------
-- activity_log
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) DEFAULT '',
        action VARCHAR(60) DEFAULT '',
        detail VARCHAR(255) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- tasks
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        subject VARCHAR(255) DEFAULT '',
        notes TEXT,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        assignee_name VARCHAR(190),
        assignee_email VARCHAR(190),
        source VARCHAR(40) DEFAULT 'manual',
        source_ref VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        done_at TIMESTAMP NULL,
        sent_at TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- columns added by later migrations:
ALTER TABLE tasks ADD COLUMN subject VARCHAR(255) DEFAULT '' AFTER title;

-- ----------------------------------------------------------------------------
-- task_assignees
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS task_assignees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        name VARCHAR(190),
        email VARCHAR(190) NOT NULL,
        ticked TINYINT NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_task_email (task_id, email),
        KEY k_task (task_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- task_block_senders
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS task_block_senders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- customer_assignees
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_assignees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            zoho_customer_id VARCHAR(64) NOT NULL,
            customer_name VARCHAR(190) DEFAULT '',
            username VARCHAR(80) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cust_user (zoho_customer_id, username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- email_book
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_book (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id VARCHAR(64) NOT NULL,
        email VARCHAR(190) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_cust_email (customer_id, email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- email_sent_marks
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_sent_marks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id VARCHAR(64) NOT NULL UNIQUE,
            marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- audrey_marks
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audrey_marks (
        invoice_number VARCHAR(64) PRIMARY KEY,
        status VARCHAR(16) NOT NULL DEFAULT 'unpaid',
        note TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    );
-- columns added by later migrations:
ALTER TABLE audrey_marks ADD COLUMN note TEXT NULL;

-- ----------------------------------------------------------------------------
-- etr_exclusions
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS etr_exclusions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(50) NOT NULL UNIQUE,
        client VARCHAR(255) NULL,
        reason VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- cal_tokens
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cal_tokens (
        user_email VARCHAR(190) PRIMARY KEY,
        refresh_token TEXT,
        connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;
