<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/AgencyStore.php';
require_once __DIR__ . '/Settings.php';

/**
 * Reads the sending mailbox (the smtp_* account, over IMAP) and matches what
 * came back to the agencies it was sent to:
 *  - replies (and auto-replies) are stored in outreach_replies, and a real
 *    reply marks a sent agency Replied;
 *  - bounces are stored too, and mark the agency bounced (AgencyStore::markBounced).
 *
 * Read-only on the mailbox: it's opened with OP_READONLY and bodies are read
 * with FT_PEEK, so nothing is marked read, moved or deleted. Messages that
 * don't belong to an outreach email are ignored and nothing about them is kept.
 *
 * Matching, most certain first: the reply's In-Reply-To/References name a sent
 * email's Message-ID (thread); the sender is an address we emailed (address);
 * the sender's domain is a contacted agency's domain, free-mail domains excluded (domain).
 */
final class MailboxSync {
    /** Days of mail re-read on each run, so nothing is missed between runs. */
    private const OVERLAP_DAYS = 2;
    /** First run: how far back to look at most. */
    private const MAX_BACK_DAYS = 60;
    private const FREE_MAIL = ['gmail.com', 'googlemail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'icloud.com', 'me.com', 'aol.com', 'gmx.com', 'gmx.net', 'proton.me', 'protonmail.com', 'yandex.com', 'mail.com', 'msn.com'];

    public static function available(): bool {
        return function_exists('imap_open') && has_smtp_config();
    }

    /**
     * One pass over the inbox. With $dryRun nothing is written: the result just
     * lists what would be recorded.
     *
     * @return array{checked: int, replies: int, auto_replies: int, bounces: int, matches: array<int, array>}
     */
    public static function run(PDO $pdo, bool $dryRun = false): array {
        try {
            $result = self::connectAndScan($pdo, $dryRun);
        } catch (Throwable $e) {
            if (!$dryRun) {
                Settings::set($pdo, 'mailbox_last_error', date('M j, H:i') . ' — ' . $e->getMessage());
            }
            throw $e;
        }
        if (!$dryRun) {
            Settings::set($pdo, 'mailbox_synced_at', (string) time());
            Settings::set($pdo, 'mailbox_last_error', '');
        }
        return $result;
    }

    private static function connectAndScan(PDO $pdo, bool $dryRun): array {
        if (!function_exists('imap_open')) {
            throw new RuntimeException("This server's PHP has no IMAP extension, so the mailbox can't be read.");
        }
        if (!has_smtp_config()) {
            throw new RuntimeException('Mailbox not configured: add the smtp_* settings to config.local.php.');
        }
        $c = app_config();
        $host = $c['imap_host'] ?? $c['smtp_host'];
        $mailbox = '{' . $host . ':' . (int) ($c['imap_port'] ?? 993) . '/imap/ssl}' . ($c['imap_folder'] ?? 'INBOX');
        $imap = @imap_open($mailbox, $c['smtp_user'], $c['smtp_pass'], OP_READONLY, 1);
        if (!$imap) {
            $error = implode('; ', imap_errors() ?: ['unknown error']);
            throw new RuntimeException("Could not open the mailbox: $error");
        }
        try {
            return self::scan($pdo, $imap, strtolower($c['smtp_user']), $dryRun);
        } finally {
            imap_close($imap);
            imap_errors();
            imap_alerts();
        }
    }

    private static function scan(PDO $pdo, $imap, string $ownAddress, bool $dryRun): array {
        $result = ['checked' => 0, 'replies' => 0, 'auto_replies' => 0, 'bounces' => 0, 'matches' => []];
        $since = self::since($pdo);
        if ($since === null) {
            return $result; // nothing was ever sent, so nothing can come back
        }
        $uids = imap_search($imap, 'SINCE "' . date('j-M-Y', $since) . '"', SE_UID) ?: [];
        if (!$uids) {
            return $result;
        }
        $index = self::index($pdo);
        $seen = $pdo->prepare('SELECT COUNT(*) FROM outreach_replies WHERE message_id = :m');

        foreach (array_chunk($uids, 100) as $chunk) {
            foreach (imap_fetch_overview($imap, implode(',', $chunk), FT_UID) ?: [] as $o) {
                $result['checked']++;
                $headers = self::parseHeaders((string) imap_fetchheader($imap, $o->uid, FT_UID));
                $messageId = trim($headers['message-id'] ?? '') ?: ('uid-' . $o->uid . '@' . md5(($headers['date'] ?? '') . ($headers['from'] ?? '')));
                $seen->execute(['m' => $messageId]);
                if ($seen->fetchColumn()) {
                    continue;
                }
                [$fromEmail, $fromName] = self::sender($headers['from'] ?? '');
                if ($fromEmail === '' || $fromEmail === $ownAddress) {
                    continue;
                }
                $subject = self::decode($headers['subject'] ?? '');
                $received = isset($headers['date']) ? (strtotime($headers['date']) ?: time()) : time();

                if (self::isBounce($headers, $subject, $fromEmail)) {
                    $raw = (string) imap_body($imap, $o->uid, FT_UID | FT_PEEK);
                    if (self::isDelayNotice($raw, $subject)) {
                        continue; // "still trying to deliver": not a failure
                    }
                    $sent = self::bouncedEmail($raw . "\n" . ($headers['in-reply-to'] ?? '') . ' ' . ($headers['references'] ?? ''), $index);
                    if ($sent === null) {
                        continue;
                    }
                    $match = ['kind' => 'bounce', 'matched_by' => 'thread', 'agency_id' => $sent['agency_id'], 'email' => $sent];
                } else {
                    $match = self::matchReply($headers, $fromEmail, $index);
                    if ($match === null) {
                        continue;
                    }
                    $match['kind'] = self::isAutoReply($headers, $subject) ? 'auto_reply' : 'reply';
                }

                $body = $match['kind'] === 'bounce' ? self::bounceSummary($raw ?? '') : self::textBody($imap, (int) $o->uid);
                $row = $match + compact('messageId', 'fromEmail', 'fromName', 'subject', 'received');
                $result[['reply' => 'replies', 'auto_reply' => 'auto_replies', 'bounce' => 'bounces'][$match['kind']]]++;
                $result['matches'][] = ['kind' => $match['kind'], 'matched_by' => $match['matched_by'], 'agency_id' => $match['agency_id'], 'from' => $fromEmail, 'subject' => $subject, 'received' => date('Y-m-d H:i', $received)];
                if (!$dryRun) {
                    self::record($pdo, $row, $body);
                }
            }
        }
        return $result;
    }

    /** Where this run starts reading: a little before the last run, or before the first email ever sent. */
    private static function since(PDO $pdo): ?int {
        $last = (int) Settings::get($pdo, 'mailbox_synced_at', '0');
        if ($last > 0) {
            return $last - self::OVERLAP_DAYS * 86400;
        }
        $first = $pdo->query(
            "SELECT LEAST(COALESCE((SELECT UNIX_TIMESTAMP(MIN(sent_at)) FROM outreach_emails WHERE status = 'sent' AND is_test = 0), 2147483647),
                          COALESCE((SELECT UNIX_TIMESTAMP(MIN(sent_at)) FROM agencies), 2147483647))"
        )->fetchColumn();
        if ((int) $first === 2147483647) {
            return null;
        }
        return max((int) $first - 86400, time() - self::MAX_BACK_DAYS * 86400);
    }

    /** Everything a message is matched against, loaded once per run. */
    private static function index(PDO $pdo): array {
        $index = ['by_message_id' => [], 'by_address' => [], 'by_domain' => []];
        foreach ($pdo->query("SELECT id, agency_id, LOWER(to_email) AS to_email, message_id FROM outreach_emails
                              WHERE status = 'sent' AND is_test = 0 AND agency_id IS NOT NULL ORDER BY id") as $e) {
            $email = ['id' => (int) $e['id'], 'agency_id' => (int) $e['agency_id'], 'to_email' => $e['to_email']];
            if ($e['message_id']) {
                $index['by_message_id'][self::normalizeId($e['message_id'])] = $email;
            }
            $index['by_address'][$e['to_email']] = $email; // latest email to that address wins
        }
        foreach ($pdo->query("SELECT id, domain FROM agencies WHERE sent_at IS NOT NULL OR status IN ('replied', 'not_interested')") as $a) {
            $index['by_domain'][strtolower($a['domain'])] = (int) $a['id'];
        }
        return $index;
    }

    /** @return array{agency_id: int, matched_by: string, email: ?array}|null */
    public static function matchReply(array $headers, string $fromEmail, array $index): ?array {
        foreach (self::messageIds(($headers['in-reply-to'] ?? '') . ' ' . ($headers['references'] ?? '')) as $id) {
            if (isset($index['by_message_id'][$id])) {
                $email = $index['by_message_id'][$id];
                return ['agency_id' => $email['agency_id'], 'matched_by' => 'thread', 'email' => $email];
            }
        }
        if (isset($index['by_address'][$fromEmail])) {
            $email = $index['by_address'][$fromEmail];
            return ['agency_id' => $email['agency_id'], 'matched_by' => 'address', 'email' => $email];
        }
        $domain = substr(strrchr($fromEmail, '@') ?: '', 1);
        if ($domain !== '' && !in_array($domain, self::FREE_MAIL, true)) {
            // A colleague's reply, or one from a subdomain (mail.agency.com → agency.com).
            foreach ($index['by_domain'] as $agencyDomain => $agencyId) {
                if ($domain === $agencyDomain || substr($domain, -strlen('.' . $agencyDomain)) === '.' . $agencyDomain) {
                    return ['agency_id' => $agencyId, 'matched_by' => 'domain', 'email' => null];
                }
            }
        }
        return null;
    }

    /** The sent email a bounce is about: by its Message-ID in the bounce, else by the address it names. */
    public static function bouncedEmail(string $raw, array $index): ?array {
        foreach (self::messageIds($raw) as $id) {
            if (isset($index['by_message_id'][$id])) {
                return $index['by_message_id'][$id];
            }
        }
        $lower = strtolower($raw);
        foreach ($index['by_address'] as $address => $email) {
            if ($address !== '' && strpos($lower, $address) !== false) {
                return $email;
            }
        }
        return null;
    }

    private static function record(PDO $pdo, array $r, string $body): void {
        $email = $r['email'] ?? null;
        $pdo->prepare(
            'INSERT IGNORE INTO outreach_replies (agency_id, outreach_email_id, message_id, kind, matched_by, from_email, from_name, subject, body, received_at)
             VALUES (:agency, :email, :mid, :kind, :by, :from, :name, :subject, :body, FROM_UNIXTIME(:received))'
        )->execute([
            'agency' => $r['agency_id'], 'email' => $email['id'] ?? null, 'mid' => mb_substr($r['messageId'], 0, 255),
            'kind' => $r['kind'], 'by' => $r['matched_by'], 'from' => mb_substr($r['fromEmail'], 0, 255),
            'name' => $r['fromName'] !== '' ? mb_substr($r['fromName'], 0, 255) : null, 'subject' => mb_substr($r['subject'], 0, 500),
            'body' => mb_substr($body, 0, 200000), 'received' => $r['received'],
        ]);
        $agency = AgencyStore::getAgency($pdo, $r['agency_id']);
        if (!$agency) {
            return;
        }
        if ($r['kind'] === 'reply' && $agency['status'] === 'sent') {
            AgencyStore::markReplied($pdo, $r['agency_id'], $r['received']);
        } elseif ($r['kind'] === 'bounce' && $agency['status'] === 'sent' && $email) {
            AgencyStore::markBounced($pdo, $r['agency_id'], $email['to_email']);
        }
    }

    /* ---------- Parsing (no mailbox needed; covered by tests/MailboxSyncTest.php) ---------- */

    /** Raw header block → lowercased name => value (folded lines joined; the first of repeated headers wins). */
    public static function parseHeaders(string $raw): array {
        $out = [];
        $raw = preg_replace("/\r?\n[ \t]+/", ' ', $raw);
        foreach (preg_split("/\r?\n/", (string) $raw) as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) {
                $name = strtolower($m[1]);
                $out[$name] ??= trim($m[2]);
            }
        }
        return $out;
    }

    /** Every <message-id> in a header value or text, normalized. */
    public static function messageIds(string $text): array {
        preg_match_all('/<([^<>\s]+@[^<>\s]+)>/', $text, $m);
        return array_values(array_unique(array_map(fn($id) => self::normalizeId($id), $m[1])));
    }

    private static function normalizeId(string $id): string {
        return strtolower(trim($id, " <>\t\r\n"));
    }

    /** "Jane Doe <Jane@Agency.com>" → ['jane@agency.com', 'Jane Doe']. */
    public static function sender(string $from): array {
        $from = self::decode($from);
        if (preg_match('/<([^<>@\s]+@[^<>\s]+)>/', $from, $m)) {
            return [strtolower($m[1]), trim(trim(str_replace($m[0], '', $from)), '" ')];
        }
        if (preg_match('/([^\s<>"]+@[^\s<>"]+)/', $from, $m)) {
            return [strtolower($m[1]), ''];
        }
        return ['', ''];
    }

    public static function isBounce(array $headers, string $subject, string $fromEmail): bool {
        $local = strtolower(strstr($fromEmail, '@', true) ?: '');
        return in_array($local, ['mailer-daemon', 'postmaster', 'mail-daemon'], true)
            || stripos($headers['content-type'] ?? '', 'report-type=delivery-status') !== false
            || preg_match('/\b(undeliver(able|ed)|delivery (status notification|has failed|failure)|mail delivery (failed|subsystem)|returned mail|failure notice|address not found)\b/i', $subject) === 1;
    }

    /** A "delayed, still retrying" notice rather than a failure. */
    public static function isDelayNotice(string $raw, string $subject): bool {
        if (preg_match('/\bAction:\s*failed\b/i', $raw)) {
            return false;
        }
        return preg_match('/\bAction:\s*delayed\b/i', $raw) === 1 || preg_match('/\b(delay(ed)?|warning)\b/i', $subject) === 1;
    }

    /** Out-of-office and other automatic answers (RFC 3834 headers, common vendor headers, typical subjects). */
    public static function isAutoReply(array $headers, string $subject): bool {
        $auto = strtolower($headers['auto-submitted'] ?? 'no');
        if ($auto !== 'no' && $auto !== '') {
            return true;
        }
        foreach (['x-autoreply', 'x-autorespond', 'x-auto-response-suppress'] as $h) {
            if (isset($headers[$h]) && stripos($h, 'suppress') === false) {
                return true;
            }
        }
        if (in_array(strtolower($headers['precedence'] ?? ''), ['auto_reply', 'auto-reply'], true)) {
            return true;
        }
        return preg_match('/^(auto(matic)?[ -]?(reply|response|antwort)|out of (the )?office|abwesenheit|automatische antwort|odsotnost|samodejni odgovor|i am (currently )?out)/i', trim($subject)) === 1;
    }

    /** The bounce's human-readable gist: the failed address and the server's reason, without the whole report. */
    public static function bounceSummary(string $raw): string {
        $lines = [];
        foreach (['Final-Recipient', 'Status', 'Diagnostic-Code'] as $field) {
            if (preg_match('/^' . $field . ':\s*(.+(?:\r?\n[ \t].+)*)/mi', $raw, $m)) {
                $lines[] = $field . ': ' . preg_replace('/\s+/', ' ', trim($m[1]));
            }
        }
        return $lines ? implode("\n", $lines) : mb_substr(trim(strip_tags($raw)), 0, 1000);
    }

    /** Splits a reply into what they wrote and the quoted earlier message (from "On … wrote:" / "> " lines). */
    public static function splitQuoted(string $text): array {
        $text = str_replace("\r\n", "\n", trim($text));
        $markers = [
            '/^On .{0,200}wrote:\s*$/m',
            '/^-{2,}\s*Original Message\s*-{2,}/mi',
            '/^From:\s.+\n(Sent|Date):\s/m',
            '/^Dne .{0,200}napisal\(a\):\s*$/m',
            '/^Am .{0,200}schrieb .{0,200}:\s*$/m',
        ];
        $cut = null;
        foreach ($markers as $re) {
            if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE) && ($cut === null || $m[0][1] < $cut)) {
                $cut = $m[0][1];
            }
        }
        if (preg_match('/^>/m', $text, $m, PREG_OFFSET_CAPTURE) && ($cut === null || $m[0][1] < $cut)) {
            $cut = $m[0][1];
        }
        if ($cut === null || $cut === 0) {
            return [$text, ''];
        }
        return [rtrim(substr($text, 0, $cut)), trim(substr($text, $cut))];
    }

    /** Decodes RFC 2047 encoded words ("=?UTF-8?B?…?=") to UTF-8. */
    public static function decode(string $s): string {
        if (strpos($s, '=?') === false) {
            return trim($s);
        }
        $decoded = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return trim($decoded !== false ? $decoded : $s);
    }

    /* ---------- Message bodies ---------- */

    /** The message as plain text: its text/plain part, else its text/html part converted. */
    private static function textBody($imap, int $uid): string {
        $structure = imap_fetchstructure($imap, $uid, FT_UID);
        if (!$structure) {
            return '';
        }
        $found = ['plain' => null, 'html' => null];
        self::walk($imap, $uid, $structure, '', $found);
        if ($found['plain'] !== null) {
            return trim($found['plain']);
        }
        if ($found['html'] !== null) {
            return self::htmlToText($found['html']);
        }
        return '';
    }

    private static function walk($imap, int $uid, object $part, string $section, array &$found): void {
        if (!empty($part->parts)) {
            foreach ($part->parts as $i => $sub) {
                self::walk($imap, $uid, $sub, $section === '' ? (string) ($i + 1) : $section . '.' . ($i + 1), $found);
            }
            return;
        }
        $isAttachment = isset($part->disposition) && strtolower($part->disposition) === 'attachment';
        if ((int) $part->type !== TYPETEXT || $isAttachment) {
            return;
        }
        $kind = strtolower($part->subtype ?? '') === 'html' ? 'html' : 'plain';
        if ($found[$kind] !== null) {
            return;
        }
        $data = (string) imap_fetchbody($imap, $uid, $section === '' ? '1' : $section, FT_UID | FT_PEEK);
        if ((int) $part->encoding === ENCBASE64) {
            $data = (string) base64_decode($data);
        } elseif ((int) $part->encoding === ENCQUOTEDPRINTABLE) {
            $data = quoted_printable_decode($data);
        }
        $charset = 'UTF-8';
        foreach (array_merge($part->parameters ?? [], $part->dparameters ?? []) as $p) {
            if (strtolower($p->attribute) === 'charset') {
                $charset = $p->value;
            }
        }
        if (strtoupper($charset) !== 'UTF-8') {
            $converted = @mb_convert_encoding($data, 'UTF-8', $charset);
            $data = $converted !== false ? $converted : $data;
        }
        $found[$kind] = $data;
    }

    private static function htmlToText(string $html): string {
        $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#si', '', $html);
        $html = preg_replace('#<br\s*/?>|</p>|</div>|</li>|</tr>|</h[1-6]>#i', "\n", (string) $html);
        $html = preg_replace('#<blockquote[^>]*>#i', "\n> ", (string) $html);
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text)));
    }
}
