<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/AgencyStore.php';
require_once __DIR__ . '/MailSender.php';
require_once __DIR__ . '/EmailHealth.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/OutreachInbox.php';
require_once __DIR__ . '/MailboxSync.php';

/**
 * Automatic sending (cron_send.php, every 5 minutes): emails the Ready to send
 * agencies, best score first, from the sending mailbox — paced to protect the
 * domain's reputation:
 *  - a daily limit that starts at START_LIMIT and grows by STEP each week up to
 *    MAX_LIMIT, only while the week's bounce rate stays under GROW_MAX_BOUNCE_RATE
 *    (manual sends count toward the same limit, see MailSender::dailyCap);
 *  - Monday–Friday inside WINDOW, Slovenian time (≈ US business hours), spread
 *    over the window with random gaps rather than a fixed rhythm;
 *  - paused automatically when bounces pile up or the mail server refuses a
 *    message, until resumed in Agency outreach → Settings.
 * Off until switched on there.
 */
final class AutoSender {
    public const TIMEZONE = 'Europe/Ljubljana';
    public const WINDOW = [15, 22];   // 15:00–22:00 here ≈ 9:00–16:00 US Eastern
    public const START_LIMIT = 15;
    public const STEP = 5;
    public const MAX_LIMIT = 40;
    public const GROW_MAX_BOUNCE_RATE = 0.03;
    /** Pause when the last 7 days bounce at this rate with at least PAUSE_MIN_BOUNCES, or 3 bounce within a day. */
    public const PAUSE_BOUNCE_RATE = 0.05;
    public const PAUSE_MIN_BOUNCES = 2;
    private const MIN_GAP_MINUTES = 4;
    /** Bounces arrive within minutes: read the mailbox before sending if it's older than this. */
    private const MAILBOX_FRESH_MINUTES = 15;

    public static function enabled(PDO $pdo): bool {
        return Settings::get($pdo, 'auto_send_enabled') === '1';
    }

    /** Why sending is paused ('' when it isn't). */
    public static function pausedReason(PDO $pdo): string {
        return Settings::get($pdo, 'auto_send_paused');
    }

    /** $startDate (Y-m-d): no email goes out before that day; null = right away. */
    public static function enable(PDO $pdo, ?string $startDate = null): void {
        $today = self::now()->format('Y-m-d');
        $start = $startDate !== null && $startDate > $today ? $startDate : $today;
        Settings::set($pdo, 'auto_send_enabled', '1');
        Settings::set($pdo, 'auto_send_paused', '');
        Settings::set($pdo, 'auto_send_start', $start);
        if (Settings::get($pdo, 'auto_send_limit') === '') {
            Settings::set($pdo, 'auto_send_limit', (string) self::START_LIMIT);
            Settings::set($pdo, 'auto_send_limit_since', $start); // the first week counts from the first sending day
        }
    }

    /** The first day emails may go out (Y-m-d), '' if not set. */
    public static function startDate(PDO $pdo): string {
        return Settings::get($pdo, 'auto_send_start');
    }

    public static function disable(PDO $pdo): void {
        Settings::set($pdo, 'auto_send_enabled', '0');
    }

    public static function resume(PDO $pdo): void {
        Settings::set($pdo, 'auto_send_paused', '');
    }

    private static function pause(PDO $pdo, string $reason): void {
        Settings::set($pdo, 'auto_send_paused', self::now()->format('M j, H:i') . ' — ' . $reason);
    }

    /** Today's daily limit, or null if automatic sending was never switched on. */
    public static function limit(PDO $pdo): ?int {
        $v = Settings::get($pdo, 'auto_send_limit');
        return $v === '' ? null : (int) $v;
    }

    /** When the limit next grows (Y-m-d), if it still can. */
    public static function nextGrowth(PDO $pdo): ?string {
        $limit = self::limit($pdo);
        $since = Settings::get($pdo, 'auto_send_limit_since');
        if ($limit === null || $limit >= self::MAX_LIMIT || $since === '') {
            return null;
        }
        return (new DateTimeImmutable($since, new DateTimeZone(self::TIMEZONE)))->modify('+7 days')->format('Y-m-d');
    }

    /** One step up after a week at the current level, if bounces stayed low and the level was actually used. */
    public static function maybeGrow(PDO $pdo): void {
        $next = self::nextGrowth($pdo);
        if ($next === null || self::now()->format('Y-m-d') < $next) {
            return;
        }
        $week = self::recentBounces($pdo, 7);
        if ($week['sent'] < self::START_LIMIT || $week['rate'] >= self::GROW_MAX_BOUNCE_RATE) {
            return; // hold at this level; checked again tomorrow
        }
        Settings::set($pdo, 'auto_send_limit', (string) min(self::MAX_LIMIT, self::limit($pdo) + self::STEP));
        Settings::set($pdo, 'auto_send_limit_since', self::now()->format('Y-m-d'));
    }

    /** Emails sent from the app over the last $days days, and how many of them bounced. */
    public static function recentBounces(PDO $pdo, int $days): array {
        $bounced = OutreachInbox::ready($pdo)
            ? "EXISTS (SELECT 1 FROM outreach_replies r WHERE r.outreach_email_id = e.id AND r.kind = 'bounce')"
            : '0';
        $r = $pdo->query(
            "SELECT COUNT(*) AS sent, COALESCE(SUM($bounced), 0) AS bounced FROM outreach_emails e
             WHERE e.status = 'sent' AND e.is_test = 0 AND e.sent_at >= NOW() - INTERVAL " . (int) $days . ' DAY'
        )->fetch();
        $sent = (int) $r['sent'];
        $n = (int) $r['bounced'];
        return ['sent' => $sent, 'bounced' => $n, 'rate' => $sent > 0 ? $n / $sent : 0.0];
    }

    public static function now(): DateTimeImmutable {
        return new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
    }

    public static function inWindow(DateTimeImmutable $t): bool {
        $hour = (int) $t->format('G');
        return (int) $t->format('N') <= 5 && $hour >= self::WINDOW[0] && $hour < self::WINDOW[1];
    }

    /**
     * The next agencies that would be emailed, best score first: Ready to send
     * rows whose address isn't blocked, was never emailed, and whose business
     * wasn't marked Reached out by hand on the dashboard.
     *
     * @return array<int, array{agency: array, to: string, analysis: array}>
     */
    public static function queue(PDO $pdo, int $limit = 5): array {
        $manual = $pdo->prepare(
            'SELECT COUNT(*) FROM prospects WHERE website_domain = :d AND contacted_at IS NOT NULL
               AND (contact_note IS NULL OR contact_note <> :auto)'
        );
        $analysis = $pdo->prepare('SELECT * FROM agency_analyses WHERE id = :id');
        $out = [];
        foreach (AgencyStore::listAgencies($pdo, ['decision' => ['SEND'], 'status' => ['analyzed'], 'email' => ['yes']], 'score', 'desc') as $a) {
            $to = strtolower((string) $a['to_email']);
            if ($to === '' || EmailHealth::isBlocked($pdo, $to) || MailSender::everSentTo($pdo, $to)) {
                continue;
            }
            $manual->execute(['d' => $a['domain'], 'auto' => AgencyStore::AUTO_CONTACT_NOTE]);
            if ($manual->fetchColumn()) {
                continue;
            }
            $analysis->execute(['id' => $a['analysis_id']]);
            $an = $analysis->fetch();
            if (!$an || ($an['edited_subject'] ?? $an['subject']) === null || ($an['edited_body'] ?? $an['body']) === null) {
                continue;
            }
            // A draft you haven't edited must pass the email quality rules (AgencyQualifier::qualityIssues);
            // one that doesn't (e.g. written before the current prompt) is rewritten before it is ever sent.
            $weak = $an['edited_body'] === null ? AgencyQualifier::qualityIssues((string) $an['body']) : [];
            $out[] = ['agency' => $a, 'to' => $to, 'analysis' => $an, 'weak' => $weak];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** When the next automatic email may go out (Unix time), 0 if not planned yet. */
    public static function nextAt(PDO $pdo): int {
        return (int) Settings::get($pdo, 'auto_send_next_at', '0');
    }

    /**
     * One cron tick: sends at most one email when everything allows it.
     * Returns a line for the log, or null when there was nothing to do.
     * $now and $send are for tests (a fixed clock, a fake sender); the cron passes neither.
     */
    public static function tick(PDO $pdo, ?DateTimeImmutable $now = null, ?callable $send = null): ?string {
        if (!self::enabled($pdo) || self::pausedReason($pdo) !== '' || !has_smtp_config()) {
            return null;
        }
        $now = $now ?? self::now();
        $send = $send ?? [AgencyStore::class, 'sendOutreach'];
        if (!self::inWindow($now) || $now->format('Y-m-d') < self::startDate($pdo)) {
            return null;
        }
        self::maybeGrow($pdo);

        // Fresh bounce information first: never keep sending into a growing bounce problem.
        if (MailboxSync::available() && OutreachInbox::ready($pdo)
            && $now->getTimestamp() - (int) Settings::get($pdo, 'mailbox_synced_at', '0') > self::MAILBOX_FRESH_MINUTES * 60) {
            try {
                MailboxSync::run($pdo);
            } catch (Throwable $e) {
                return 'Not sending: the mailbox could not be checked for bounces (' . $e->getMessage() . ').';
            }
        }
        $week = self::recentBounces($pdo, 7);
        $day = self::recentBounces($pdo, 1);
        if (($week['bounced'] >= self::PAUSE_MIN_BOUNCES && $week['rate'] >= self::PAUSE_BOUNCE_RATE) || $day['bounced'] >= 3) {
            $reason = sprintf('%d of %d emails bounced in the last 7 days (%.0f%%). Check the addresses before resuming.', $week['bounced'], $week['sent'], $week['rate'] * 100);
            self::pause($pdo, $reason);
            return "Paused: $reason";
        }

        if (MailSender::limitReason($pdo) !== null) {
            return null; // daily limit reached, or the minimum gap since the last send hasn't passed
        }
        // Spread the day: the first send lands at a random minute early in the window.
        $windowStart = $now->setTime(self::WINDOW[0], 0);
        $nextAt = self::nextAt($pdo);
        if ($nextAt < $windowStart->getTimestamp()) {
            $nextAt = $windowStart->getTimestamp() + random_int(0, 20) * 60;
            Settings::set($pdo, 'auto_send_next_at', (string) $nextAt);
        }
        if ($now->getTimestamp() < $nextAt) {
            return null;
        }

        $pick = null;
        $rewrites = [];
        foreach (self::queue($pdo, 25) as $candidate) {
            if (!$candidate['weak']) {
                $pick = $candidate;
                break;
            }
            // Never send a weak draft: back to the analysis queue (cron_analyze.php) for a rewrite.
            AgencyStore::queueReanalysis($pdo, (int) $candidate['agency']['id']);
            $rewrites[] = $candidate['agency']['domain'];
        }
        $note = $rewrites ? ' Queued for a rewrite first (draft below the quality bar): ' . implode(', ', $rewrites) . '.' : '';
        if ($pick === null) {
            return $note !== '' ? trim($note) : null; // nothing sendable right now; the analysis cron refills the stock
        }
        $a = $pick['agency'];
        $an = $pick['analysis'];
        $sent = $send($pdo, AgencyStore::getAgency($pdo, (int) $a['id']), (int) $an['id'], $pick['to'],
            (string) ($an['edited_subject'] ?? $an['subject']), (string) ($an['edited_body'] ?? $an['body']));
        if (!$sent['ok']) {
            // A refusal can mean the server is limiting us: stop for now rather than retrying into it.
            self::pause($pdo, 'the mail server refused an email to ' . $pick['to'] . ': ' . $sent['error']);
            return "Paused: sending to {$pick['to']} failed: {$sent['error']}";
        }

        // Next send: the rest of today's limit spread over the rest of the window, ±40%, never closer than MIN_GAP_MINUTES.
        $usage = MailSender::usage($pdo);
        $left = max(1, $usage['cap'] - $usage['today']);
        $minutesLeft = max(0, ($now->setTime(self::WINDOW[1], 0)->getTimestamp() - $now->getTimestamp()) / 60);
        $gap = max(self::MIN_GAP_MINUTES, $minutesLeft / $left * random_int(60, 140) / 100);
        $nextAt = $now->getTimestamp() + (int) round($gap * 60);
        Settings::set($pdo, 'auto_send_next_at', (string) $nextAt);
        return sprintf('Sent to %s (#%d %s, score %d) — %d of %d today; next ≈ %s.',
            $pick['to'], $a['id'], $a['domain'], $a['score'], $usage['today'], $usage['cap'],
            (new DateTimeImmutable('@' . $nextAt))->setTimezone(new DateTimeZone(self::TIMEZONE))->format('H:i')) . $note;
    }
}
