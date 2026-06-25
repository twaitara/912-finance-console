<?php
/**
 * 912 Working Capital Tracker — configuration TEMPLATE.
 * Copy this file to `config.php` and fill in your real values.
 * `config.php` is gitignored and must NEVER be committed (it holds live secrets).
 */
return [
    // ---- Zoho OAuth ----
    'client_id'       => 'YOUR_ZOHO_CLIENT_ID',
    'client_secret'   => 'YOUR_ZOHO_CLIENT_SECRET',
    'refresh_token'   => 'YOUR_ZOHO_REFRESH_TOKEN',

    // ---- Data centre domains ----
    'accounts_url'    => 'https://accounts.zoho.com',
    'api_domain'      => 'https://www.zohoapis.com',

    // ---- Your Zoho Books organisation ----
    'organization_id' => 'YOUR_ORG_ID',

    // ---- MySQL database ----
    'db_host'         => 'localhost',
    'db_name'         => 'your_db_name',
    'db_user'         => 'your_db_user',
    'db_pass'         => 'your_db_password',

    // ---- Working capital settings ----
    'fund'            => 1200000,   // starting fund in KES
    'annual_rate'     => 0.18,      // 18% annual interest
    'currency'        => 'KES',
    'vat_rate'        => 0.16,      // fallback only; live uses Zoho's actual VAT

    // ---- Custom field API name for "Funded by Working Capital" ----
    'wc_custom_field' => 'cf_funded_by_working_capital',

    // ---- App access password (master/admin login) ----
    'app_password'    => 'CHANGE_ME',

    // ---- Optional: Job Card / Delivery Note letterhead ----
    // 'company_pin'     => 'P0XXXXXXXXX',
    // 'company_address' => "(UN Crescent Road)\nGate No 80 Gigiri\nNairobi 7928\nKenya",
    // 'business_name'   => 'Nine One Two Holdings',
];
