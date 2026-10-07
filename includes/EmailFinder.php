<?php
require_once __DIR__ . '/SiteScanner.php';
require_once __DIR__ . '/ContactStatus.php';
require_once __DIR__ . '/ProspectStore.php';

/**
 * Scans a lead's own website (homepage + contact page) for email addresses
 * and stores the best one in prospects.contact_email. Spends no verifier
 * credits: found emails are stored as email_source='website' with
 * email_verification left NULL, so enrich.php can verify them later.
 *
 * Shared by find_emails.php (CLI) and email_finder.php (web).
 */
class EmailFinder {
    private const GENERIC_LOCALS = ['info', 'hello', 'contact', 'sales', 'hi', 'team', 'office', 'support', 'admin'];

    // Placeholder/demo addresses left in themes, and system senders.
    private const JUNK_LOCAL_PATTERN = '/^(no-?reply|donotreply|example|test|email|your|name|you$|user(name)?$|abc$|john.?doe|jane.?doe)/';

    private SiteScanner $scanner;

    public function __construct() {
        $this->scanner = new SiteScanner();
    }

    /**
     * Every scan records its outcome in email_scan_status / email_scan_note,
     * so the dashboard can say *why* a prospect has no email.
     *
     * @return array{outcome: string, email: ?string, others: string[], note: ?string}
     *   outcome is 'found', 'none' (site loaded, no usable email) or 'failed' (site didn't load)
     */
    public function findForProspect(PDO $pdo, array $prospect, bool $dryRun = false): array {
        $scan = $this->scanner->scan($prospect['website']);
        if (!$scan['fetch_ok']) {
            $note = $scan['fetch_error'] ?? "Couldn't load the site";
            $this->recordOutcome($pdo, $prospect, 'failed', $note, $dryRun);
            return ['outcome' => 'failed', 'email' => null, 'others' => [], 'note' => $note];
        }

        $email = self::pickBest($scan['emails_found'], ProspectStore::normalizeDomain($prospect['website']));
        if ($email === null) {
            $note = self::describeNoEmail($scan);
            $this->recordOutcome($pdo, $prospect, 'none', $note, $dryRun);
            return ['outcome' => 'none', 'email' => null, 'others' => [], 'note' => $note];
        }

        if (!$dryRun) {
            $pdo->prepare(
                "UPDATE prospects SET contact_email = :email, email_source = 'website', email_verification = NULL,
                    email_confidence = NULL, contact_status = :status,
                    email_scan_status = 'found', email_scan_note = NULL, email_scanned_at = NOW() WHERE id = :id"
            )->execute([
                'id' => $prospect['id'],
                'email' => $email,
                'status' => ContactStatus::compute($prospect['website'], $prospect['phone'], $email),
            ]);
        }

        return ['outcome' => 'found', 'email' => $email, 'others' => array_values(array_diff($scan['emails_found'], [$email])), 'note' => null];
    }

    private function recordOutcome(PDO $pdo, array $prospect, string $status, string $note, bool $dryRun): void {
        if ($dryRun) {
            return;
        }
        $pdo->prepare(
            'UPDATE prospects SET email_scan_status = :status, email_scan_note = :note, email_scanned_at = NOW() WHERE id = :id'
        )->execute(['id' => $prospect['id'], 'status' => $status, 'note' => mb_substr($note, 0, 255)]);
    }

    private static function describeNoEmail(array $scan): string {
        $where = $scan['contact_page_checked'] ? 'on homepage or contact page' : 'on homepage (no contact page link found)';
        if (!empty($scan['emails_found'])) {
            return "Only placeholder/junk addresses $where";
        }
        if ($scan['has_contact_form']) {
            return "No email $where, uses a contact form";
        }
        return "No email $where";
    }

    /**
     * Prefer an address on the business's own domain, then generic inboxes
     * (info@, hello@...) over personal ones, since those reach whoever
     * handles new-business enquiries. Placeholders are rejected outright.
     */
    public static function pickBest(array $emails, ?string $siteDomain): ?string {
        if (!$emails) {
            return null;
        }
        $score = function (string $email) use ($siteDomain): int {
            [$local, $domain] = explode('@', $email, 2);
            $s = 0;
            if ($siteDomain !== null && ($domain === $siteDomain || str_ends_with($domain, ".$siteDomain"))) {
                $s += 10;
            }
            if (in_array($local, self::GENERIC_LOCALS, true)) {
                $s += 3;
            }
            if (preg_match(self::JUNK_LOCAL_PATTERN, $local)) {
                $s -= 20;
            }
            return $s;
        };
        usort($emails, fn($a, $b) => $score($b) <=> $score($a));
        return $score($emails[0]) > -10 ? $emails[0] : null;
    }

    /**
     * The given leads, if they have a website (explicitly picked, so they're
     * scanned even if they already have an email).
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    public static function byIds(PDO $pdo, array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM prospects WHERE id IN ($placeholders) AND website IS NOT NULL AND website != '' ORDER BY id");
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }

    /**
     * Leads with a website matching the filters, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function candidates(PDO $pdo, ?string $category, ?string $city, bool $rescan, int $limit): array {
        $conditions = ["website IS NOT NULL", "website != ''", "ignored_at IS NULL"];
        $params = [];
        if (!$rescan) {
            $conditions[] = "(contact_email IS NULL OR contact_email = '')";
        }
        if ($category !== null && $category !== '') {
            $conditions[] = 'category = :category';
            $params['category'] = $category;
        }
        if ($city !== null && $city !== '') {
            $conditions[] = 'city = :city';
            $params['city'] = $city;
        }
        $limit = max(1, $limit);
        $stmt = $pdo->prepare('SELECT * FROM prospects WHERE ' . implode(' AND ', $conditions) . " ORDER BY id LIMIT $limit");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
