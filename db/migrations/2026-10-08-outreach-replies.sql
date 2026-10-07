-- Replies, auto-replies and bounces read from the sending mailbox (includes/MailboxSync.php)
-- and matched to the agency they answer. Run once on an existing database
-- (phpMyAdmin → SQL tab); db/schema.sql already has it for new installs.
CREATE TABLE IF NOT EXISTS outreach_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agency_id INT NOT NULL,
    outreach_email_id INT NULL,          -- the sent email it answers, when known
    message_id VARCHAR(255) NOT NULL,    -- the incoming message's own Message-ID (each is stored once)
    kind ENUM('reply', 'auto_reply', 'bounce') NOT NULL DEFAULT 'reply',
    matched_by ENUM('thread', 'address', 'domain') NOT NULL,
    from_email VARCHAR(255) NOT NULL,
    from_name VARCHAR(255),
    subject VARCHAR(500),
    body MEDIUMTEXT,
    received_at TIMESTAMP NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_message (message_id),
    INDEX idx_agency (agency_id),
    INDEX idx_unread (kind, read_at),
    FOREIGN KEY (agency_id) REFERENCES agencies(id) ON DELETE CASCADE,
    FOREIGN KEY (outreach_email_id) REFERENCES outreach_emails(id) ON DELETE SET NULL
) ENGINE=InnoDB;
