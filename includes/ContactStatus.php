<?php

/**
 * Single source of truth for the contact_status label stored on each
 * prospect, so search, manual add, and re-analysis all classify the
 * same way and the dashboard filter stays meaningful.
 */
class ContactStatus {
    public const NO_DATA = 'no_data';
    public const WEBSITE_ONLY = 'website_only';
    public const PHONE_ONLY = 'phone_only';
    public const EMAIL_ONLY = 'email_only';
    public const WEBSITE_PHONE = 'website_phone';
    public const WEBSITE_EMAIL = 'website_email';
    public const PHONE_EMAIL = 'phone_email';
    public const FULL = 'full';

    public const LABELS = [
        self::NO_DATA => 'No data',
        self::WEBSITE_ONLY => 'Website only',
        self::PHONE_ONLY => 'Phone only',
        self::EMAIL_ONLY => 'Email only',
        self::WEBSITE_PHONE => 'Website + phone',
        self::WEBSITE_EMAIL => 'Website + email',
        self::PHONE_EMAIL => 'Phone + email',
        self::FULL => 'Full contact',
    ];

    public static function compute(?string $website, ?string $phone, ?string $email): string {
        $w = !empty($website);
        $p = !empty($phone);
        $e = !empty($email);

        if ($w && $p && $e) return self::FULL;
        if ($w && $p) return self::WEBSITE_PHONE;
        if ($w && $e) return self::WEBSITE_EMAIL;
        if ($p && $e) return self::PHONE_EMAIL;
        if ($w) return self::WEBSITE_ONLY;
        if ($p) return self::PHONE_ONLY;
        if ($e) return self::EMAIL_ONLY;
        return self::NO_DATA;
    }

    public static function label(string $status): string {
        return self::LABELS[$status] ?? $status;
    }
}
