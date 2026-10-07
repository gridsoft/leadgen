<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/EmailDraft.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends outreach email as slobodan@dmmbs.com through the mailbox's own SMTP
 * server (config smtp_*), one message at a time and only when the user clicks
 * Send. Every attempt is recorded in outreach_emails, and a copy of each sent
 * message is saved to the mailbox's Sent folder over IMAP so it shows up in a
 * normal mail app.
 *
 * Limits protect the domain's sending reputation: at most DAILY_CAP emails a
 * day and MIN_GAP_SECONDS between two sends (config smtp_daily_cap / smtp_min_gap).
 */
class MailSender {
    private const DAILY_CAP = 20;
    private const MIN_GAP_SECONDS = 120;

    public static function dailyCap(): int {
        return (int) (app_config()['smtp_daily_cap'] ?? self::DAILY_CAP);
    }

    public static function minGap(): int {
        return (int) (app_config()['smtp_min_gap'] ?? self::MIN_GAP_SECONDS);
    }

    public static function fromAddress(): string {
        return (string) (app_config()['smtp_user'] ?? '');
    }

    /** Sent today (by MySQL's clock) and seconds until the next send is allowed. */
    public static function usage(PDO $pdo): array {
        $row = $pdo->query(
            "SELECT SUM(DATE(sent_at) = CURDATE()) AS today, TIMESTAMPDIFF(SECOND, MAX(sent_at), NOW()) AS since_last
               FROM outreach_emails WHERE status = 'sent' AND is_test = 0"
        )->fetch();
        $sinceLast = $row['since_last'] !== null ? (int) $row['since_last'] : null;
        return [
            'today' => (int) $row['today'],
            'cap' => self::dailyCap(),
            'wait' => $sinceLast === null ? 0 : max(0, self::minGap() - $sinceLast),
        ];
    }

    /** Null when sending is allowed now, otherwise the reason it isn't. */
    public static function limitReason(PDO $pdo): ?string {
        $u = self::usage($pdo);
        if ($u['today'] >= $u['cap']) {
            return "Daily limit reached: {$u['today']} of {$u['cap']} outreach emails sent today. Try again tomorrow.";
        }
        if ($u['wait'] > 0) {
            return "Please wait {$u['wait']} more seconds: outreach emails go out at least " . round(self::minGap() / 60, 1) . ' minutes apart.';
        }
        return null;
    }

    /**
     * Sends one plain-text email and records it.
     *
     * @return array{ok: bool, error: ?string, message_id: ?string, saved_to_sent: bool, id: int}
     */
    public static function send(PDO $pdo, string $to, string $subject, string $body, ?int $agencyId = null, ?int $analysisId = null, bool $isTest = false): array {
        $c = app_config();
        $to = strtolower(trim($to));
        $body = str_replace("\r\n", "\n", $body);
        $result = ['ok' => false, 'error' => null, 'message_id' => null, 'saved_to_sent' => false, 'id' => 0];

        try {
            if (!has_smtp_config()) {
                throw new RuntimeException("Sending isn't set up: add smtp_* settings to config.local.php.");
            }
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("\"$to\" is not a valid email address.");
            }
            if (trim($subject) === '' || trim($body) === '') {
                throw new RuntimeException('Subject and body must not be empty.');
            }

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $c['smtp_host'];
            $mail->Port = (int) ($c['smtp_port'] ?? 465);
            $mail->SMTPSecure = $mail->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAuth = true;
            $mail->Username = $c['smtp_user'];
            $mail->Password = $c['smtp_pass'];
            $mail->Timeout = 30;
            // Message-ID and EHLO name use the sending domain, not this PC's name (spam filters check it).
            $mail->Hostname = substr(strrchr($c['smtp_user'], '@'), 1);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $mail->setFrom($c['smtp_user'], $c['smtp_from_name'] ?? '');
            $mail->addReplyTo($c['smtp_user'], $c['smtp_from_name'] ?? '');
            $mail->addAddress($to);
            $mail->Subject = trim($subject);
            // HTML (bold labels, bullet list) with a plain-text alternative for text-only mail apps.
            $mail->isHTML(true);
            $mail->Body = EmailDraft::toHtml($body);
            $mail->AltBody = EmailDraft::toPlain($body);
            // Lets the recipient opt out in one click; also helps the message stay out of spam.
            $mail->addCustomHeader('List-Unsubscribe', '<mailto:' . $c['smtp_user'] . '?subject=unsubscribe>');

            $mail->send();
            $result['ok'] = true;
            $result['message_id'] = $mail->getLastMessageID();
            $result['saved_to_sent'] = self::saveToSentFolder($mail->getSentMIMEMessage());
        } catch (Throwable $e) {
            // PHPMailer messages never contain the password.
            $result['error'] = $e->getMessage();
        }

        $pdo->prepare(
            'INSERT INTO outreach_emails (agency_id, analysis_id, to_email, subject, body, status, error, message_id, saved_to_sent, is_test)
             VALUES (:agency, :analysis, :to, :subject, :body, :status, :error, :mid, :saved, :test)'
        )->execute([
            'agency' => $agencyId,
            'analysis' => $analysisId,
            'to' => $to,
            'subject' => mb_substr(trim($subject), 0, 500),
            'body' => $body,
            'status' => $result['ok'] ? 'sent' : 'failed',
            'error' => $result['error'] !== null ? mb_substr($result['error'], 0, 1000) : null,
            'mid' => $result['message_id'],
            'saved' => $result['saved_to_sent'] ? 1 : 0,
            'test' => $isTest ? 1 : 0,
        ]);
        $result['id'] = (int) $pdo->lastInsertId();
        return $result;
    }

    /** Last email the app sent to this agency, if any. */
    public static function lastSentTo(PDO $pdo, int $agencyId): ?array {
        $stmt = $pdo->prepare("SELECT * FROM outreach_emails WHERE agency_id = :id AND status = 'sent' ORDER BY id DESC LIMIT 1");
        $stmt->execute(['id' => $agencyId]);
        return $stmt->fetch() ?: null;
    }

    /** True if the app ever sent anything (non-test) to this address. */
    public static function everSentTo(PDO $pdo, string $email): bool {
        $stmt = $pdo->prepare("SELECT 1 FROM outreach_emails WHERE to_email = :e AND status = 'sent' AND is_test = 0 LIMIT 1");
        $stmt->execute(['e' => strtolower(trim($email))]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Copies the sent message into the mailbox's Sent folder (IMAP APPEND), so it
     * appears in Webmail / Outlook / phone like any other sent email. A failure
     * here doesn't undo the send — it's only reported.
     */
    private static function saveToSentFolder(string $mime): bool {
        if (!function_exists('imap_open')) {
            return false;
        }
        $c = app_config();
        $mailbox = '{' . $c['smtp_host'] . ':993/imap/ssl}';
        $imap = @imap_open($mailbox, $c['smtp_user'], $c['smtp_pass'], OP_HALFOPEN, 1);
        if (!$imap) {
            imap_errors();
            return false;
        }
        try {
            $folder = $c['smtp_sent_folder'] ?? self::findSentFolder($imap, $mailbox);
            return $folder !== null && @imap_append($imap, $mailbox . imap_utf7_encode($folder), str_replace("\n", "\r\n", str_replace("\r\n", "\n", $mime)), '\\Seen');
        } finally {
            imap_close($imap);
            imap_errors();
            imap_alerts();
        }
    }

    /** "INBOX.Sent" on this server; also finds "Sent", "Sent Items", "INBOX/Sent Messages"… */
    private static function findSentFolder($imap, string $mailbox): ?string {
        $folders = array_map(fn($f) => imap_utf7_decode(str_replace($mailbox, '', $f)), imap_list($imap, $mailbox, '*') ?: []);
        foreach ($folders as $f) {
            if (preg_match('/(^|[.\/])Sent( Items| Messages| Mail)?$/i', $f)) {
                return $f;
            }
        }
        return null;
    }
}
