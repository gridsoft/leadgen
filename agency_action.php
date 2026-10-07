<?php
/**
 * Form posts from agency_view.php: email edits, outreach status, notes,
 * re-analyze. Redirects back to the agency. Nothing here sends email —
 * "Mark as sent" only records that the user sent it themselves.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/AgencyStore.php';
require_once __DIR__ . '/includes/EmailHealth.php';
require_once __DIR__ . '/includes/MailSender.php';
require_once __DIR__ . '/includes/AgencyViews.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: agency_outreach.php');
    exit;
}

$pdo = get_db();
$id = (int) ($_POST['id'] ?? 0);
$analysisId = (int) ($_POST['analysis_id'] ?? 0);
$action = $_POST['action'] ?? '';
$back = ['id' => $id];

// Edits only ever apply to an analysis that belongs to this agency.
if ($analysisId) {
    $own = $pdo->prepare('SELECT COUNT(*) FROM agency_analyses WHERE id = :aid AND agency_id = :id');
    $own->execute(['aid' => $analysisId, 'id' => $id]);
    if (!$own->fetchColumn()) {
        $analysisId = 0;
    }
}

switch ($action) {
    case 'save_email':
        if ($analysisId) {
            AgencyStore::saveEmailEdits($pdo, $analysisId, (string) ($_POST['subject'] ?? ''), (string) ($_POST['body'] ?? ''));
            $back += ['analysis' => $analysisId, 'done' => 'saved'];
        }
        break;
    case 'reset_email':
        if ($analysisId) {
            AgencyStore::resetEmailEdits($pdo, $analysisId);
            $back += ['analysis' => $analysisId, 'done' => 'reset'];
        }
        break;
    case 'mark_sent':
        AgencyStore::markSent($pdo, $id);
        $back['done'] = 'sent';
        break;
    case 'mark_replied':
        AgencyStore::markReplied($pdo, $id);
        $back['done'] = 'replied';
        break;
    case 'not_interested':
        AgencyStore::markNotInterested($pdo, $id);
        $back['done'] = 'not_interested';
        break;
    case 'ignore':
        AgencyStore::markIgnored($pdo, $id);
        $back['done'] = 'ignored';
        break;
    case 'reset_outreach':
        AgencyStore::resetOutreach($pdo, $id);
        $back['done'] = 'outreach_reset';
        break;
    case 'bounced':
        // "Address not found": block the address, and the agency counts as not contacted.
        $email = trim((string) ($_POST['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            AgencyStore::markBounced($pdo, $id, $email);
            $back['done'] = 'bounced';
        }
        break;
    case 'send_email':
        // Real sending as slobodan@dmmbs.com (MailSender). Every safety check runs here on the
        // server; the page only asks the user to confirm and retries with force_* when they do.
        header('Content-Type: application/json');
        $to = strtolower(trim((string) ($_POST['to'] ?? '')));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = (string) ($_POST['body'] ?? '');
        $agency = AgencyStore::getAgency($pdo, $id);
        $fail = function (string $code, string $message) {
            echo json_encode(['ok' => false, 'code' => $code, 'message' => $message]);
            exit;
        };
        if (!$agency || !$analysisId) {
            $fail('missing', 'Agency or analysis not found. Reload the page.');
        }
        if ($agency['status'] === 'ignored') {
            $fail('ignored', 'This agency is marked Ignored, so it is never emailed. Click Undo under Outreach first if you do want to email it.');
        }
        if (!has_smtp_config()) {
            $fail('not_configured', "Sending isn't set up: add the smtp_* settings to config.local.php.");
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $fail('bad_address', 'Enter a valid email address in the To field.');
        }
        if ($subject === '' || trim($body) === '') {
            $fail('empty', 'Subject and body must not be empty.');
        }
        if ($limit = MailSender::limitReason($pdo)) {
            $fail('limit', $limit);
        }
        if (empty($_POST['force_address']) && EmailHealth::isBlocked($pdo, $to)) {
            $fail('blocked', "$to does not accept mail (it bounced before or failed verification). The email would bounce.");
        }
        if (empty($_POST['force_contacted'])) {
            $before = [];
            if (in_array($agency['status'], ['sent', 'replied'], true)) {
                $before[] = 'marked as sent on ' . date('M j', strtotime((string) $agency['sent_at']));
            }
            if (MailSender::everSentTo($pdo, $to)) {
                $before[] = "the app already emailed $to";
            }
            if ($before) {
                $fail('contacted', 'This agency was already contacted (' . implode('; ', $before) . '). Sending again would contact them a second time.');
            }
        }

        // Portfolio line, name-only sign-off and the DMMBS / opt-out footer, even if edited away.
        $refStmt = $pdo->prepare('SELECT ref_slug FROM agency_analyses WHERE id = :id');
        $refStmt->execute(['id' => $analysisId]);
        $body = EmailDraft::finalize($body, EmailDraft::refSlug($refStmt->fetchColumn() ?: null, $agency['agency_name'], $agency['domain']));
        $sent = MailSender::send($pdo, $to, $subject, $body, $id, $analysisId);
        if (!$sent['ok']) {
            $fail('send_failed', 'The email was NOT sent: ' . $sent['error']);
        }
        // Keep the stored draft identical to what went out, then record the send everywhere.
        AgencyStore::saveEmailEdits($pdo, $analysisId, $subject, $body);
        AgencyStore::markSent($pdo, $id);
        echo json_encode(['ok' => true, 'saved_to_sent' => $sent['saved_to_sent'], 'message' => "Sent to $to."]);
        exit;
    case 'verify_email':
        // Background deliverability check from agency_view.php (Abstract API, cached 60 days).
        $email = trim((string) ($_POST['email'] ?? ''));
        header('Content-Type: application/json');
        echo json_encode(['status' => filter_var($email, FILTER_VALIDATE_EMAIL) ? EmailHealth::check($pdo, $email) : null]);
        exit;
    case 'save_notes':
        AgencyStore::saveNotes($pdo, $id, (string) ($_POST['notes'] ?? ''));
        $back['done'] = 'notes';
        break;
    case 'reanalyze':
        // agency_view.php sees the 'pending' status and runs the analysis itself.
        AgencyStore::queueReanalysis($pdo, $id);
        break;
    case 'stop_analysis':
        AgencyStore::stopAnalysis($pdo, $id);
        break;
}

// Background calls from agency_view.php (Open in Gmail marks the email sent before the tab switches).
if (!empty($_POST['ajax'])) {
    $agency = AgencyStore::getAgency($pdo, $id);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => (bool) $agency,
        'status' => $agency['status'] ?? null,
        'sent_at' => isset($agency['sent_at']) ? date('M j, Y', strtotime($agency['sent_at'])) : null,
        'follow_up_at' => isset($agency['follow_up_at']) ? date('M j, Y', strtotime($agency['follow_up_at'])) : null,
        // For agency_outreach.php's list, which swaps the row in place (e.g. "Got reply").
        'row_html' => $agency ? render_agency_row(AgencyStore::listAgencies($pdo, ['id' => $id], 'created', 'desc')[0]) : null,
    ]);
    exit;
}

// Saving edits on an older run keeps you on that run; otherwise show the latest.
if (isset($back['analysis'])) {
    $latest = $pdo->prepare('SELECT MAX(id) FROM agency_analyses WHERE agency_id = :id');
    $latest->execute(['id' => $id]);
    if ((int) $latest->fetchColumn() === $back['analysis']) {
        unset($back['analysis']);
    }
}
header('Location: agency_view.php?' . http_build_query($back));
exit;
