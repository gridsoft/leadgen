<?php

/**
 * Read side of what MailboxSync stored: the Replies list, an agency's
 * conversation, read marks and delivery numbers. Every method copes with the
 * outreach_replies table not existing yet (migration not run): it returns
 * nothing rather than breaking the page.
 */
final class OutreachInbox {
    public const KINDS = ['reply' => 'Replies', 'auto_reply' => 'Auto-replies', 'bounce' => 'Bounces'];
    /** A sent email with no bounce after this long counts as delivered. */
    public const DELIVERED_AFTER_HOURS = 48;

    public static function ready(PDO $pdo): bool {
        try {
            $pdo->query('SELECT 1 FROM outreach_replies LIMIT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** New real replies nobody has opened yet (the sidebar badge). */
    public static function unreadCount(PDO $pdo): int {
        try {
            return (int) $pdo->query("SELECT COUNT(*) FROM outreach_replies WHERE kind = 'reply' AND read_at IS NULL")->fetchColumn();
        } catch (PDOException $e) {
            return 0; // db/migrations/2026-10-08-outreach-replies.sql not run yet
        }
    }

    /** Count per kind, plus unread real replies. */
    public static function counts(PDO $pdo): array {
        $out = array_fill_keys(array_keys(self::KINDS), 0) + ['unread' => 0];
        if (!self::ready($pdo)) {
            return $out;
        }
        foreach ($pdo->query('SELECT kind, COUNT(*) FROM outreach_replies GROUP BY kind')->fetchAll(PDO::FETCH_KEY_PAIR) as $kind => $n) {
            $out[$kind] = (int) $n;
        }
        $out['unread'] = (int) $pdo->query("SELECT COUNT(*) FROM outreach_replies WHERE kind = 'reply' AND read_at IS NULL")->fetchColumn();
        return $out;
    }

    /** Newest first, with the agency's name. $kind null = every kind. */
    public static function listReplies(PDO $pdo, ?string $kind): array {
        if (!self::ready($pdo)) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT r.*, COALESCE(a.agency_name, a.domain) AS agency_name, a.domain, a.status AS agency_status
             FROM outreach_replies r JOIN agencies a ON a.id = r.agency_id'
            . ($kind !== null ? ' WHERE r.kind = :kind' : '')
            . ' ORDER BY r.received_at DESC, r.id DESC LIMIT 500'
        );
        $stmt->execute($kind !== null ? ['kind' => $kind] : []);
        return $stmt->fetchAll();
    }

    /**
     * Everything exchanged with one agency, oldest first: emails the app sent
     * ('out') and what came back ('in'), each with 'at' as a timestamp string.
     */
    public static function conversation(PDO $pdo, int $agencyId): array {
        $items = [];
        $sent = $pdo->prepare("SELECT id, to_email, subject, body, sent_at FROM outreach_emails WHERE agency_id = :id AND status = 'sent' AND is_test = 0");
        $sent->execute(['id' => $agencyId]);
        foreach ($sent->fetchAll() as $e) {
            $items[] = ['dir' => 'out', 'at' => $e['sent_at']] + $e;
        }
        if (self::ready($pdo)) {
            $in = $pdo->prepare('SELECT * FROM outreach_replies WHERE agency_id = :id');
            $in->execute(['id' => $agencyId]);
            foreach ($in->fetchAll() as $r) {
                $items[] = ['dir' => 'in', 'at' => $r['received_at'] ?? $r['created_at']] + $r;
            }
        }
        usort($items, fn($x, $y) => strcmp((string) $x['at'], (string) $y['at']));
        return $items;
    }

    public static function markRead(PDO $pdo, int $agencyId): void {
        if (self::ready($pdo)) {
            $pdo->prepare('UPDATE outreach_replies SET read_at = NOW() WHERE agency_id = :id AND read_at IS NULL')->execute(['id' => $agencyId]);
        }
    }

    public static function markAllRead(PDO $pdo): void {
        if (self::ready($pdo)) {
            $pdo->exec('UPDATE outreach_replies SET read_at = NOW() WHERE read_at IS NULL');
        }
    }

    /**
     * Emails sent from the app: delivered (no bounce after DELIVERED_AFTER_HOURS),
     * pending (younger, no bounce yet) and bounced.
     */
    public static function delivery(PDO $pdo): array {
        $bounced = self::ready($pdo)
            ? 'EXISTS (SELECT 1 FROM outreach_replies r WHERE r.outreach_email_id = e.id AND r.kind = \'bounce\')'
            : '0';
        $r = $pdo->query(
            "SELECT COUNT(*) AS sent,
                    SUM($bounced) AS bounced,
                    SUM(NOT $bounced AND e.sent_at > NOW() - INTERVAL " . self::DELIVERED_AFTER_HOURS . " HOUR) AS pending
             FROM outreach_emails e WHERE e.status = 'sent' AND e.is_test = 0"
        )->fetch();
        $sent = (int) $r['sent'];
        $bouncedN = (int) $r['bounced'];
        $pending = (int) $r['pending'];
        return ['sent' => $sent, 'bounced' => $bouncedN, 'pending' => $pending, 'delivered' => $sent - $bouncedN - $pending];
    }
}
