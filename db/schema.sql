CREATE DATABASE IF NOT EXISTS leadgen CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE leadgen;

CREATE TABLE IF NOT EXISTS prospects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_name VARCHAR(255) NOT NULL,
    category VARCHAR(255),
    city VARCHAR(255),
    website VARCHAR(500),
    phone VARCHAR(50),
    address VARCHAR(500),
    google_place_id VARCHAR(255),
    website_domain VARCHAR(255),
    source VARCHAR(100),
    review_count INT,
    facebook_url VARCHAR(500),
    contact_email VARCHAR(255),
    contact_status ENUM(
        'no_data', 'website_only', 'phone_only', 'email_only',
        'website_phone', 'website_email', 'phone_email', 'full'
    ) NOT NULL DEFAULT 'no_data',
    country VARCHAR(2),
    region VARCHAR(100),
    phone_e164 VARCHAR(20),
    phone_national10 VARCHAR(10),
    outreach_status ENUM('email_ready', 'phone_only', 'needs_review', 'excluded'),
    status_reason VARCHAR(50),
    email_source ENUM('dataset', 'website', 'pattern', 'ai'),
    email_verification ENUM('valid', 'invalid', 'catch_all', 'unknown', 'disposable'),
    email_confidence ENUM('high', 'medium', 'low'),
    website_discovered TINYINT(1) NOT NULL DEFAULT 0,
    discovery_source VARCHAR(50),
    discovery_evidence_url VARCHAR(500),
    enriched_at TIMESTAMP NULL,
    contacted_at TIMESTAMP NULL,
    contact_note VARCHAR(500),
    ignored_at TIMESTAMP NULL,
    email_scan_status ENUM('found', 'none', 'failed'),
    email_scan_note VARCHAR(255),
    email_scanned_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_place_id (google_place_id),
    UNIQUE KEY uniq_website_domain (website_domain),
    INDEX idx_contact_status (contact_status),
    INDEX idx_outreach_status (outreach_status),
    INDEX idx_phone_national10 (phone_national10),
    INDEX idx_contacted_at (contacted_at),
    INDEX idx_ignored_at (ignored_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS analyses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prospect_id INT NOT NULL,
    fetch_ok TINYINT(1),
    is_wordpress TINYINT(1),
    theme_name VARCHAR(255),
    mobile_performance_score INT,
    desktop_performance_score INT,
    total_byte_weight INT,
    image_byte_weight INT,
    mobile_lcp_ms INT,
    desktop_lcp_ms INT,
    desktop_total_byte_weight INT,
    desktop_image_byte_weight INT,
    has_booking_signal TINYINT(1),
    facebook_url VARCHAR(500),
    emails_found TEXT,
    phone_found VARCHAR(50),
    has_contact_form TINYINT(1),
    has_chat_widget TINYINT(1),
    opportunity_level ENUM('LOW','MEDIUM','HIGH'),
    opportunity_points INT,
    opportunity_reasons TEXT,
    contact_gap_level ENUM('LOW','MEDIUM','HIGH'),
    contact_gap_points INT,
    contact_gap_reasons TEXT,
    error_message TEXT,
    analyzed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (prospect_id) REFERENCES prospects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS emails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prospect_id INT NOT NULL,
    subject VARCHAR(500),
    body TEXT,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (prospect_id) REFERENCES prospects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tracks which (category, location, source) combinations have already been
-- searched, so batch runs on a later day skip exhausted ground automatically
-- instead of re-fetching the same capped result set for no new leads.
CREATE TABLE IF NOT EXISTS search_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(255) NOT NULL,
    location VARCHAR(255) NOT NULL,
    source VARCHAR(20) NOT NULL,
    result_count INT NOT NULL DEFAULT 0,
    new_count INT NOT NULL DEFAULT 0,
    searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_combo (category, location, source)
) ENGINE=InnoDB;

-- One row per enrichment decision for a lead (Module A/B/C step outcomes),
-- so every lead's final status is traceable back to why.
CREATE TABLE IF NOT EXISTS enrichment_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prospect_id INT NOT NULL,
    step VARCHAR(50) NOT NULL,
    result VARCHAR(50) NOT NULL,
    reason VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_prospect (prospect_id),
    FOREIGN KEY (prospect_id) REFERENCES prospects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Module A2: cached search-provider results, keyed by exact query string.
CREATE TABLE IF NOT EXISTS search_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    query VARCHAR(500) NOT NULL,
    results_json MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    UNIQUE KEY uniq_query (query),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB;

-- Module B3: cached verifier results, keyed by email address.
CREATE TABLE IF NOT EXISTS verification_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL,
    raw_response MEDIUMTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    UNIQUE KEY uniq_email (email),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB;

-- Simple DB-backed job queue (no external queue system in this project).
CREATE TABLE IF NOT EXISTS jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    status ENUM('pending', 'running', 'done', 'failed') NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    last_error TEXT,
    run_after TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status_run_after (status, run_after)
) ENGINE=InnoDB;

-- Daily per-provider call counters, for enforcing DAILY_SEARCH_CAP / DAILY_VERIFICATION_CAP.
CREATE TABLE IF NOT EXISTS api_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usage_date DATE NOT NULL,
    provider VARCHAR(50) NOT NULL,
    request_count INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_date_provider (usage_date, provider)
) ENGINE=InnoDB;

-- Emails/domains that must never receive outreach, checked by Classifier
-- and enforced again in the campaign export query itself.
CREATE TABLE IF NOT EXISTS suppression_list (
    id INT AUTO_INCREMENT PRIMARY KEY,
    value VARCHAR(255) NOT NULL,
    type ENUM('email', 'domain') NOT NULL,
    reason VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_value_type (value, type)
) ENGINE=InnoDB;

-- "Check with AI" results (Find emails page): Claude Code (ai_worker.php) researches
-- a lead on the live web and recommends whether it's worth contacting. Latest row per prospect wins.
CREATE TABLE IF NOT EXISTS ai_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prospect_id INT NOT NULL,
    verdict ENUM('worth_trying', 'maybe', 'skip') NOT NULL,
    summary VARCHAR(1000),
    email VARCHAR(255),
    email_source_url VARCHAR(500),
    based_in VARCHAR(255),
    location_matches_search TINYINT(1),
    result_json MEDIUMTEXT,
    model VARCHAR(50),
    duration_seconds INT,
    cost_usd DECIMAL(8,4),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_prospect (prospect_id),
    FOREIGN KEY (prospect_id) REFERENCES prospects(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- Heartbeats from long-running local workers (ai_worker.php), so the web UI
-- can tell whether one is running.
CREATE TABLE IF NOT EXISTS workers (
    name VARCHAR(50) PRIMARY KEY,
    last_seen TIMESTAMP NULL,
    info VARCHAR(255),
    stop_requested TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- Agency Outreach (agency_outreach.php): web agencies the user pastes by URL,
-- qualified by an AI API call that also drafts the first outreach email.
-- Nothing here sends email — the user copies the draft and sends it themselves.
CREATE TABLE IF NOT EXISTS agencies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    normalized_url VARCHAR(500) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    agency_name VARCHAR(255),
    status ENUM('pending', 'analyzing', 'fetch_failed', 'ai_failed', 'analyzed', 'sent', 'replied', 'not_interested', 'ignored')
        NOT NULL DEFAULT 'pending',
    -- Outreach status to restore after a re-analysis of an agency already contacted.
    resume_status ENUM('sent', 'replied', 'not_interested', 'ignored') NULL,
    last_error TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    analyzed_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    follow_up_at TIMESTAMP NULL,
    replied_at TIMESTAMP NULL,
    UNIQUE KEY uniq_domain (domain),
    INDEX idx_status (status),
    INDEX idx_follow_up_at (follow_up_at)
) ENGINE=InnoDB;

-- One row per scrape + AI run, so a re-analysis keeps the earlier result.
-- The list and detail pages show the latest row per agency.
CREATE TABLE IF NOT EXISTS agency_analyses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agency_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    pages_json TEXT,
    emails_json TEXT,
    -- Emails already saved for this website in the lead list (dashboard), offered to the AI too.
    known_emails_json TEXT,
    phones_json TEXT,
    platform VARCHAR(100),
    warnings_json TEXT,
    decision ENUM('SEND', 'SEND_LOW_PRIORITY', 'SKIP'),
    score TINYINT UNSIGNED,
    reasons_json TEXT,
    red_flags_json TEXT,
    contact_name VARCHAR(255),
    contact_role VARCHAR(255),
    to_email VARCHAR(255),
    to_email_source ENUM('site', 'lead_list'),
    other_channel VARCHAR(500),
    ref_slug VARCHAR(100),
    subject VARCHAR(500),
    body TEXT,
    follow_up_tip VARCHAR(1000),
    edited_subject VARCHAR(500),
    edited_body TEXT,
    error TEXT,
    raw_response MEDIUMTEXT,
    model VARCHAR(100),
    input_tokens INT,
    output_tokens INT,
    INDEX idx_agency (agency_id),
    FOREIGN KEY (agency_id) REFERENCES agencies(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Small user-editable settings (e.g. the outreach email signature fields).
-- Secrets such as API keys never go here — they live in config.local.php.
CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(100) PRIMARY KEY,
    value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Every email the app sent itself (MailSender, SMTP as slobodan@dmmbs.com): an exact
-- record of what went out, and the source for the daily limit / minimum gap between sends.
CREATE TABLE IF NOT EXISTS outreach_emails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agency_id INT NULL,
    analysis_id INT NULL,
    to_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    body TEXT NOT NULL,
    status ENUM('sent', 'failed') NOT NULL,
    error VARCHAR(1000),
    message_id VARCHAR(255),
    saved_to_sent TINYINT(1) NOT NULL DEFAULT 0,
    is_test TINYINT(1) NOT NULL DEFAULT 0,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_agency (agency_id),
    INDEX idx_sent_at (sent_at),
    INDEX idx_to_email (to_email),
    FOREIGN KEY (agency_id) REFERENCES agencies(id) ON DELETE SET NULL
) ENGINE=InnoDB;
