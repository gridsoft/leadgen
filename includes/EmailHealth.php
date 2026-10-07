<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/VerificationCache.php';
require_once __DIR__ . '/AbstractApiVerifier.php';

/**
 * Is an outreach address usable? Combines what the project already knows:
 *  - suppression_list: addresses that bounced (reported from agency_view.php) are 'bounced';
 *  - verification_cache: earlier Abstract API checks (valid / invalid / catch_all / …).
 * check() may call Abstract API (cached 60 days, 100 free calls a month); status() never does.
 */
class EmailHealth {
    public const BLOCKED = ['bounced', 'invalid'];
    private const BOUNCE_REASON = 'bounced (agency outreach)';

    /** Known status without any API call: 'bounced', a cached verification status, or null if never checked. */
    public static function status(PDO $pdo, string $email): ?string {
        $email = strtolower(trim($email));
        $stmt = $pdo->prepare("SELECT 1 FROM suppression_list WHERE type = 'email' AND value = :v LIMIT 1");
        $stmt->execute(['v' => $email]);
        if ($stmt->fetchColumn()) {
            return 'bounced';
        }
        $cached = VerificationCache::get($pdo, $email);
        return $cached['status'] ?? null;
    }

    public static function isBlocked(PDO $pdo, string $email): bool {
        return in_array(self::status($pdo, $email), self::BLOCKED, true);
    }

    /** Known status, else a live Abstract API check (if a key is configured). Null when it can't be checked. */
    public static function check(PDO $pdo, string $email): ?string {
        $known = self::status($pdo, $email);
        if ($known !== null || !has_abstractapi_key()) {
            return $known;
        }
        try {
            return VerificationCache::verify($pdo, new AbstractApiVerifier($pdo), strtolower(trim($email)))['status'];
        } catch (Throwable $e) {
            return null; // quota reached or API down — not a reason to block sending
        }
    }

    /** First address in the list that isn't known to be dead. */
    public static function firstUsable(PDO $pdo, array $emails): ?string {
        foreach ($emails as $email) {
            if ($email && !self::isBlocked($pdo, $email)) {
                return strtolower(trim($email));
            }
        }
        return null;
    }

    /**
     * Records a bounce: never offered again, cached as invalid, and the dashboard's
     * copy of the address is marked invalid.
     */
    public static function recordBounce(PDO $pdo, string $email): void {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        if (self::status($pdo, $email) !== 'bounced') {
            $pdo->prepare("INSERT INTO suppression_list (value, type, reason) VALUES (:v, 'email', :r)")
                ->execute(['v' => $email, 'r' => self::BOUNCE_REASON]);
        }
        VerificationCache::put($pdo, $email, ['status' => 'invalid', 'raw_response' => 'Bounce reported by the user (agency outreach)']);
        $pdo->prepare("UPDATE prospects SET email_verification = 'invalid' WHERE LOWER(contact_email) = :v")->execute(['v' => $email]);
    }
}
