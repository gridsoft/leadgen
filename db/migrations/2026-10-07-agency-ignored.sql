-- Adds the 'ignored' agency status ("I chose not to email this one"): kept out of
-- Ready to send and the cron stock, and never emailed. Run once on an existing
-- database (phpMyAdmin → SQL tab); db/schema.sql already has it for new installs.
ALTER TABLE agencies
    MODIFY status ENUM('pending', 'analyzing', 'fetch_failed', 'ai_failed', 'analyzed', 'sent', 'replied', 'not_interested', 'ignored')
        NOT NULL DEFAULT 'pending',
    MODIFY resume_status ENUM('sent', 'replied', 'not_interested', 'ignored') NULL;
