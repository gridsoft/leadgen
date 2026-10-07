<?php
require_once __DIR__ . '/../includes/MailboxSync.php';

function mailbox_index(): array {
    $sent = ['id' => 7, 'agency_id' => 3, 'to_email' => 'maria@brightline.com'];
    return [
        'by_message_id' => ['abc123@dmmbs.com' => $sent],
        'by_address' => ['maria@brightline.com' => $sent],
        'by_domain' => ['brightline.com' => 3, 'otheragency.io' => 9],
    ];
}

return [
    'headers: folded lines are joined, names lowercased, first value kept' => function () {
        $h = MailboxSync::parseHeaders("Subject: Re: Your\r\n  portfolio\r\nIn-Reply-To: <abc123@dmmbs.com>\r\nReceived: one\r\nReceived: two\r\n");
        assert_same('Re: Your portfolio', $h['subject']);
        assert_same('<abc123@dmmbs.com>', $h['in-reply-to']);
        assert_same('one', $h['received']);
    },
    'message ids are found and normalized' => function () {
        assert_same(['abc123@dmmbs.com', 'x@y.org'], MailboxSync::messageIds('<ABC123@dmmbs.com> <x@y.org>  <abc123@dmmbs.com>'));
    },
    'sender: name and address, encoded names decoded' => function () {
        assert_same(['maria@brightline.com', 'Maria Lopez'], MailboxSync::sender('"Maria Lopez" <Maria@Brightline.com>'));
        assert_same(['jo@x.si', ''], MailboxSync::sender('jo@x.si'));
        assert_same(['ana@x.si', 'Ana Žagar'], MailboxSync::sender('=?UTF-8?B?QW5hIMW9YWdhcg==?= <ana@x.si>'));
    },
    'a reply is matched by thread first, then address, then agency domain' => function () {
        $thread = MailboxSync::matchReply(['in-reply-to' => '<abc123@dmmbs.com>'], 'someone@else.com', mailbox_index());
        assert_same('thread', $thread['matched_by']);
        assert_same(7, $thread['email']['id']);
        assert_same('address', MailboxSync::matchReply([], 'maria@brightline.com', mailbox_index())['matched_by']);
        $colleague = MailboxSync::matchReply([], 'ceo@brightline.com', mailbox_index());
        assert_same(['domain', 3], [$colleague['matched_by'], $colleague['agency_id']]);
        assert_same(9, MailboxSync::matchReply([], 'team@mail.otheragency.io', mailbox_index())['agency_id'], 'subdomain of the agency');
    },
    'unknown senders and free-mail domains are not matched' => function () {
        assert_same(null, MailboxSync::matchReply([], 'newsletter@shop.com', mailbox_index()));
        $index = mailbox_index();
        $index['by_domain']['gmail.com'] = 5;
        assert_same(null, MailboxSync::matchReply([], 'random.person@gmail.com', $index), 'gmail.com never matches by domain');
    },
    'auto-replies: RFC 3834 header, vendor header, typical subjects' => function () {
        assert_true(MailboxSync::isAutoReply(['auto-submitted' => 'auto-replied'], 'Re: hello'));
        assert_true(MailboxSync::isAutoReply(['x-autoreply' => 'yes'], 'Re: hello'));
        assert_true(MailboxSync::isAutoReply([], 'Out of Office: back on Monday'));
        assert_true(MailboxSync::isAutoReply([], 'Automatic reply: WordPress help'));
        assert_true(!MailboxSync::isAutoReply(['auto-submitted' => 'no'], 'Re: WordPress help'), 'a person replying');
        assert_true(!MailboxSync::isAutoReply(['x-auto-response-suppress' => 'All'], 'Re: hi'), 'Outlook sets this on normal mail');
    },
    'bounces: mailer-daemon, delivery reports, typical subjects; delays are not failures' => function () {
        assert_true(MailboxSync::isBounce([], 'Undelivered Mail Returned to Sender', 'mailer-daemon@mx.host.com'));
        assert_true(MailboxSync::isBounce(['content-type' => 'multipart/report; report-type=delivery-status; boundary=x'], 'x', 'a@b.com'));
        assert_true(MailboxSync::isBounce([], 'Delivery Status Notification (Failure)', 'noreply@google.com'));
        assert_true(!MailboxSync::isBounce([], 'Re: WordPress help', 'maria@brightline.com'));
        assert_true(MailboxSync::isDelayNotice("Action: delayed\nStatus: 4.4.7", 'Delivery Status Notification (Delay)'));
        assert_true(!MailboxSync::isDelayNotice("Action: failed\nStatus: 5.1.1", 'Delivery Status Notification (Failure)'));
    },
    'a bounce is tied to the sent email by Message-ID, else by the address it names' => function () {
        assert_same(7, MailboxSync::bouncedEmail("...\nMessage-ID: <abc123@dmmbs.com>\n...", mailbox_index())['id']);
        assert_same(7, MailboxSync::bouncedEmail("Final-Recipient: rfc822; MARIA@brightline.com\nAction: failed", mailbox_index())['id']);
        assert_same(null, MailboxSync::bouncedEmail('Final-Recipient: rfc822; nobody@else.com', mailbox_index()));
    },
    'bounce summary keeps the recipient, status and reason' => function () {
        $s = MailboxSync::bounceSummary("Reporting-MTA: dns; mx\nFinal-Recipient: rfc822; maria@brightline.com\nAction: failed\nStatus: 5.1.1\nDiagnostic-Code: smtp; 550 5.1.1 user\n  unknown");
        assert_same("Final-Recipient: rfc822; maria@brightline.com\nStatus: 5.1.1\nDiagnostic-Code: smtp; 550 5.1.1 user unknown", $s);
    },
    'quoted text is split off the reply' => function () {
        [$new, $quoted] = MailboxSync::splitQuoted("Thanks, let's talk Tuesday.\n\nOn Mon, Oct 6, 2026 at 3:41 PM Slobodan <slobodan@dmmbs.com> wrote:\n> Hi Maria,");
        assert_same("Thanks, let's talk Tuesday.", $new);
        assert_contains('wrote:', $quoted);
        assert_same(['Sounds good.', '> Hi Maria'], MailboxSync::splitQuoted("Sounds good.\n> Hi Maria"));
        assert_same(['Just text.', ''], MailboxSync::splitQuoted('Just text.'));
    },
];
