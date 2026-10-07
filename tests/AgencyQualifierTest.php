<?php
require_once __DIR__ . '/../includes/AgencyQualifier.php';

/** Returns scripted replies in order and records each call. Never touches the network. */
final class FakeAiClient implements AiClient {
    public array $calls = [];
    private array $replies;

    public function __construct(array $replies) {
        $this->replies = $replies;
    }

    public function complete(string $system, string $user, array $schema): array {
        $this->calls[] = ['system' => $system, 'user' => $user, 'schema' => $schema];
        $reply = array_shift($this->replies);
        if ($reply instanceof Throwable) {
            throw $reply;
        }
        return ['text' => $reply, 'model' => 'fake-model', 'input_tokens' => 1000, 'output_tokens' => 400, 'stop_reason' => 'end_turn', 'raw' => "RAW:$reply"];
    }
}

function sample_scrape(): array {
    return [
        'ok' => true,
        'pages' => [
            'home' => ['url' => 'https://brightlinedigital.com', 'text' => 'WordPress websites that grow small businesses'],
            'contact' => ['url' => 'https://brightlinedigital.com/contact', 'text' => 'Write to hello@brightlinedigital.com'],
        ],
        'emails' => ['hello@brightlinedigital.com', 'maria@brightlinedigital.com'],
        'phones' => ['+1 (512) 555-0100'],
        'platform' => 'WordPress 6.9',
        'warnings' => [],
    ];
}

function ai_reply(array $overrides = []): string {
    return json_encode(array_merge([
        'agency_name' => 'Brightline Digital',
        'decision' => 'SEND',
        'score' => 78,
        'reasons' => ['Five-person WordPress studio', 'Founder is a designer'],
        'red_flags' => [],
        'contact_name' => 'Maria Lopez',
        'contact_role' => 'Founder',
        'to_email' => 'maria@brightlinedigital.com',
        'other_channel' => null,
        'ref_slug' => 'brightline-digital',
        'subject' => 'WordPress development help for Brightline',
        'body' => "Hi Maria,\n\n...\n\nBest regards,\nSlobodan Stevkovski\nhttps://dmmbs.com · {EMAIL} · {PHONE} · {LINKEDIN}",
        'follow_up_tip' => 'Message Maria on LinkedIn',
    ], $overrides));
}

function qualifier(FakeAiClient $ai): AgencyQualifier {
    return new AgencyQualifier($ai, 'SYSTEM PROMPT', AgencyQualifier::readPromptFile('user-template.txt'));
}

return [
    'user message: placeholders filled, missing pages become (not found)' => function () {
        $msg = AgencyQualifier::buildUserMessage(AgencyQualifier::readPromptFile('user-template.txt'), 'https://brightlinedigital.com', sample_scrape());
        assert_contains('WEBSITE: https://brightlinedigital.com', $msg);
        assert_contains('DETECTED PLATFORM: WordPress 6.9', $msg);
        assert_contains('EMAILS FOUND ON SITE: hello@brightlinedigital.com, maria@brightlinedigital.com', $msg);
        assert_contains('PHONES FOUND ON SITE: +1 (512) 555-0100', $msg);
        assert_contains("=== HOME PAGE ===\nWordPress websites that grow small businesses", $msg);
        assert_contains("=== ABOUT / TEAM PAGE ===\n(not found)", $msg);
        assert_contains("=== CONTACT PAGE ===\nWrite to hello@brightlinedigital.com", $msg);
        assert_not_contains('{text}', $msg);
        assert_not_contains('{URL}', $msg);
    },
    'user message: no emails or phones reads "none"' => function () {
        $scrape = sample_scrape();
        $scrape['emails'] = [];
        $scrape['phones'] = [];
        $msg = AgencyQualifier::buildUserMessage(AgencyQualifier::readPromptFile('user-template.txt'), 'https://a.com', $scrape);
        assert_contains("EMAILS FOUND ON SITE: none\nPHONES FOUND ON SITE: none", $msg);
    },
    'prompt files load and are sent unchanged as the system message' => function () {
        $system = AgencyQualifier::readPromptFile('system.txt');
        assert_contains('You help Slobodan Stevkovski', $system);
        assert_contains("You can see my portfolio here:\nhttps://dmmbs.com/?ref=<agency-slug>", $system);
        assert_contains('I can support your team as a flexible white-label engineering resource, particularly with:', $system);
        assert_contains('Swap test', $system);
        assert_not_contains('(optional third bullet)', $system, 'no annotation outside <angle brackets> that the AI could copy');
        $full = AgencyQualifier::systemPrompt();
        assert_same(1, substr_count($full, '# PORTFOLIO PROJECTS'), 'the portfolio project list is appended exactly once');
        $portfolio = substr($full, strpos($full, '# PORTFOLIO PROJECTS'));
        assert_contains('Upflip Academy (USA) | System Architect & Developer', $portfolio);
        assert_contains('never that he "built" it', $portfolio);
        assert_contains("Best regards,\nSlobodan Stevkovski\n", $system);
        assert_not_contains('{EMAIL}', $system);
        $ai = new FakeAiClient([ai_reply()]);
        (new AgencyQualifier($ai))->analyze('https://brightlinedigital.com', sample_scrape());
        assert_same($full, $ai->calls[0]['system'], 'system.txt + portfolio list, unchanged');
        assert_same(0, strpos($full, $system), 'system.txt is sent word for word, first');
        assert_same(AgencyQualifier::OUTPUT_SCHEMA, $ai->calls[0]['schema'], 'structured-output schema is requested');
    },
    'valid JSON is accepted' => function () {
        $ai = new FakeAiClient([ai_reply()]);
        $r = qualifier($ai)->analyze('https://brightlinedigital.com', sample_scrape());
        assert_true($r['ok']);
        assert_same('SEND', $r['data']['decision']);
        assert_same(78, $r['data']['score']);
        assert_same('maria@brightlinedigital.com', $r['data']['to_email']);
        assert_same([], $r['data']['red_flags']);
        assert_same(1, count($ai->calls));
        assert_same(1000, $r['input_tokens']);
        assert_same(400, $r['output_tokens']);
    },
    'JSON wrapped in a code fence is still parsed' => function () {
        assert_same('SEND', AgencyQualifier::parseResponse("```json\n" . ai_reply() . "\n```")['decision']);
    },
    'invalid JSON is retried once, then succeeds' => function () {
        $ai = new FakeAiClient(['this is not json', ai_reply()]);
        $r = qualifier($ai)->analyze('https://brightlinedigital.com', sample_scrape());
        assert_true($r['ok']);
        assert_same(2, count($ai->calls));
        assert_same(2000, $r['input_tokens'], 'tokens from both calls are counted');
        assert_contains('RAW:this is not json', $r['raw'], 'raw responses of both attempts are kept');
    },
    'invalid JSON twice fails with the raw response kept' => function () {
        $ai = new FakeAiClient(['nope', '{"broken": ']);
        $r = qualifier($ai)->analyze('https://brightlinedigital.com', sample_scrape());
        assert_same(false, $r['ok']);
        assert_same(2, count($ai->calls), 'exactly one retry');
        assert_contains('not valid JSON', $r['error']);
        assert_contains('RAW:nope', $r['raw']);
        assert_contains('RAW:{"broken": ', $r['raw']);
    },
    'wrong decision value fails validation' => function () {
        $errors = AgencyQualifier::validate(json_decode(ai_reply(['decision' => 'MAYBE']), true));
        assert_contains('decision must be', implode(' ', $errors));
        $r = qualifier(new FakeAiClient([ai_reply(['decision' => 'MAYBE']), ai_reply(['decision' => 'send'])]))->analyze('https://a.com', sample_scrape());
        assert_same(false, $r['ok']);
        assert_contains('failed validation', $r['error']);
    },
    'score must be an integer from 0 to 100' => function () {
        foreach ([101, -1, '80', 79.5, null] as $bad) {
            assert_true(AgencyQualifier::validate(json_decode(ai_reply(['score' => $bad]), true)) !== [], 'should reject score ' . var_export($bad, true));
        }
        assert_same([], AgencyQualifier::validate(json_decode(ai_reply(['score' => 0]), true)));
        assert_same([], AgencyQualifier::validate(json_decode(ai_reply(['score' => 100]), true)));
    },
    'SEND needs a subject and body; SKIP does not' => function () {
        assert_true(AgencyQualifier::validate(json_decode(ai_reply(['body' => '  ']), true)) !== []);
        assert_true(AgencyQualifier::validate(json_decode(ai_reply(['decision' => 'SEND_LOW_PRIORITY', 'subject' => null]), true)) !== []);
        assert_same([], AgencyQualifier::validate(json_decode(ai_reply(['decision' => 'SKIP', 'score' => 20, 'subject' => null, 'body' => null]), true)));
    },
    'to_email not found on the site is cleared and flagged' => function () {
        $ai = new FakeAiClient([ai_reply(['to_email' => 'maria.lopez@brightlinedigital.com'])]);
        $r = qualifier($ai)->analyze('https://brightlinedigital.com', sample_scrape());
        assert_true($r['ok']);
        assert_same(null, $r['data']['to_email']);
        assert_same([AgencyQualifier::INVENTED_EMAIL_FLAG], $r['data']['red_flags']);
    },
    'to_email found on the site is kept (case-insensitive)' => function () {
        $data = AgencyQualifier::applySafetyCheck(['to_email' => 'Hello@BrightlineDigital.com', 'red_flags' => []], sample_scrape()['emails']);
        assert_same('hello@brightlinedigital.com', $data['to_email']);
        assert_same([], $data['red_flags']);
    },
    'API failure is reported without a retry storm' => function () {
        $ai = new FakeAiClient([new AiRequestException('AI API failed after 3 attempts. Last error: HTTP 529: Overloaded')]);
        $r = qualifier($ai)->analyze('https://a.com', sample_scrape());
        assert_same(false, $r['ok']);
        assert_same(1, count($ai->calls), 'transport errors were already retried inside the client');
        assert_contains('529', $r['error']);
    },
    'a used-up daily quota is passed to the caller, not reported as a failed reply' => function () {
        $ai = new FakeAiClient([new AiQuotaExhausted('The free daily AI quota is used up for every model.')]);
        try {
            qualifier($ai)->analyze('https://a.com', sample_scrape());
            throw new AssertionFailed('expected AiQuotaExhausted');
        } catch (AiQuotaExhausted $e) {
            assert_same(1, count($ai->calls));
        }
    },
    'lead-list emails are offered to the AI, labelled' => function () {
        $scrape = sample_scrape();
        $scrape['emails'] = [];
        $scrape['known_emails'] = ['info@cre8media.com'];
        $msg = AgencyQualifier::buildUserMessage(AgencyQualifier::readPromptFile('user-template.txt'), 'https://cre8.agency', $scrape);
        assert_contains('EMAILS FOUND ON SITE: info@cre8media.com (from our lead list, not seen on the site)', $msg);
    },
    'AI may pick a lead-list email; its source is recorded' => function () {
        $scrape = sample_scrape();
        $scrape['known_emails'] = ['info@cre8media.com'];
        $r = qualifier(new FakeAiClient([ai_reply(['to_email' => 'info@cre8media.com (from our lead list, not seen on the site)'])]))->analyze('https://a.com', $scrape);
        assert_same('info@cre8media.com', $r['data']['to_email'], 'label echoed by the AI is stripped');
        assert_same('lead_list', $r['data']['to_email_source']);
        assert_same([], $r['data']['red_flags']);
        $r = qualifier(new FakeAiClient([ai_reply()]))->analyze('https://a.com', $scrape);
        assert_same('site', $r['data']['to_email_source']);
    },
    'no email on the site and none chosen: falls back to the lead list' => function () {
        $scrape = sample_scrape();
        $scrape['emails'] = [];
        $scrape['known_emails'] = ['Info@Cre8media.com'];
        $r = qualifier(new FakeAiClient([ai_reply(['to_email' => null])]))->analyze('https://cre8.agency', $scrape);
        assert_same('info@cre8media.com', $r['data']['to_email']);
        assert_same('lead_list', $r['data']['to_email_source']);
    },
    'no fallback when the site has emails, or the decision is SKIP' => function () {
        $scrape = sample_scrape();
        $scrape['known_emails'] = ['info@cre8media.com'];
        $r = qualifier(new FakeAiClient([ai_reply(['to_email' => null])]))->analyze('https://a.com', $scrape);
        assert_same(null, $r['data']['to_email'], 'the site has its own addresses; the AI chose none of them on purpose');
        $scrape['emails'] = [];
        $r = qualifier(new FakeAiClient([ai_reply(['decision' => 'SKIP', 'score' => 10, 'to_email' => null, 'subject' => null, 'body' => null])]))->analyze('https://a.com', $scrape);
        assert_same(null, $r['data']['to_email']);
    },
    'an email in neither list is still cleared and flagged' => function () {
        $scrape = sample_scrape();
        $scrape['known_emails'] = ['info@cre8media.com'];
        $r = qualifier(new FakeAiClient([ai_reply(['to_email' => 'eric@cre8.agency'])]))->analyze('https://a.com', $scrape);
        assert_same(null, $r['data']['to_email']);
        assert_same([AgencyQualifier::INVENTED_EMAIL_FLAG], $r['data']['red_flags']);
    },
    'ref_slug is sanitised to lowercase letters, numbers and hyphens' => function () {
        $r = qualifier(new FakeAiClient([ai_reply(['ref_slug' => 'Brightline Digital!!'])]))->analyze('https://a.com', sample_scrape());
        assert_same('brightline-digital', $r['data']['ref_slug']);
    },
];
