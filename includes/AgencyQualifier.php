<?php
require_once __DIR__ . '/AiClient.php';

/**
 * Sends one agency's scraped site to the AI with the fixed prompt in
 * prompts/agency-qualifier/, then validates the answer: decision, score,
 * contact and a draft outreach email.
 *
 * Safety rule: the AI is never the source of an email address. A to_email
 * that the scraper didn't find on the site is cleared and flagged.
 */
class AgencyQualifier {
    public const DECISIONS = ['SEND', 'SEND_LOW_PRIORITY', 'SKIP'];
    public const INVENTED_EMAIL_FLAG = 'AI suggested an email that is not on the site';
    private const PROMPT_DIR = __DIR__ . '/../prompts/agency-qualifier';

    /** User-template headings, in order, and the scraper page type that fills each. */
    private const PAGE_HEADINGS = [
        'HOME PAGE' => 'home',
        'ABOUT / TEAM PAGE' => 'about',
        'CONTACT PAGE' => 'contact',
        'CAREERS / JOBS PAGE' => 'careers',
        'SERVICES PAGE' => 'services',
    ];

    /** Mirrors the OUTPUT section of the system prompt, for the API's structured-output mode. */
    public const OUTPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'agency_name' => ['type' => 'string'],
            'decision' => ['type' => 'string', 'enum' => self::DECISIONS],
            'score' => ['type' => 'integer'],
            'reasons' => ['type' => 'array', 'items' => ['type' => 'string']],
            'red_flags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'contact_name' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
            'contact_role' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
            'to_email' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
            'other_channel' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
            'ref_slug' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
            'subject' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
            'body' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
            'follow_up_tip' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
        ],
        'required' => [
            'agency_name', 'decision', 'score', 'reasons', 'red_flags', 'contact_name', 'contact_role',
            'to_email', 'other_channel', 'ref_slug', 'subject', 'body', 'follow_up_tip',
        ],
        'additionalProperties' => false,
    ];

    private AiClient $client;
    private string $systemPrompt;
    private string $userTemplate;

    public function __construct(AiClient $client, ?string $systemPrompt = null, ?string $userTemplate = null) {
        $this->client = $client;
        $this->systemPrompt = $systemPrompt ?? self::systemPrompt();
        $this->userTemplate = $userTemplate ?? self::readPromptFile('user-template.txt');
    }

    /**
     * system.txt plus the PORTFOLIO PROJECTS list (portfolio.txt, built from dmmbs.com by
     * refresh_portfolio.php) that the email cites its example project from.
     */
    public static function systemPrompt(): string {
        $prompt = self::readPromptFile('system.txt');
        if (is_file(self::PROMPT_DIR . '/portfolio.txt')) {
            $prompt = rtrim($prompt) . "\n\n# PORTFOLIO PROJECTS\n\n" . self::readPromptFile('portfolio.txt');
        }
        return $prompt;
    }

    public static function readPromptFile(string $name): string {
        $text = @file_get_contents(self::PROMPT_DIR . '/' . $name);
        if ($text === false) {
            throw new RuntimeException("Missing prompt file prompts/agency-qualifier/$name");
        }
        return str_replace("\r\n", "\n", $text);
    }

    /**
     * Fills the user template from a scrape result (AgencyScraper::scrape shape).
     * Pages that weren't found become "(not found)".
     */
    public static function buildUserMessage(string $template, string $url, array $scrape): string {
        $template = str_replace("\r\n", "\n", $template);
        // Emails the user already has for this site (dashboard) go in the same list, labelled,
        // so the AI may choose one but knows it wasn't on the pages it read.
        $emails = $scrape['emails'] ?? [];
        foreach (self::extraKnownEmails($scrape) as $known) {
            $emails[] = "$known (from our lead list, not seen on the site)";
        }
        $pairs = [
            '{URL}' => $url,
            '{e.g. WordPress 6.9 / Wix / unknown}' => $scrape['platform'] ?? 'unknown',
            '{comma-separated list, or none}' => $emails ? implode(', ', $emails) : 'none',
            '{list, or none}' => !empty($scrape['phones']) ? implode(', ', $scrape['phones']) : 'none',
        ];
        foreach (self::PAGE_HEADINGS as $heading => $type) {
            $text = trim($scrape['pages'][$type]['text'] ?? '');
            $pairs["=== $heading ===\n{text}"] = "=== $heading ===\n" . ($text !== '' ? $text : '(not found)');
        }
        // strtr replaces in one pass, so page text containing "{URL}" etc. is left alone.
        return strtr($template, $pairs);
    }

    /**
     * Runs the AI call. Invalid output (unparsable JSON or failed validation)
     * is retried once.
     *
     * @return array{ok: bool, data: ?array, error: ?string, raw: string, model: ?string, input_tokens: int, output_tokens: int}
     */
    public function analyze(string $url, array $scrape): array {
        $user = self::buildUserMessage($this->userTemplate, $url, $scrape);
        $out = ['ok' => false, 'data' => null, 'error' => null, 'raw' => '', 'model' => null, 'input_tokens' => 0, 'output_tokens' => 0];
        $raws = [];

        for ($try = 1; $try <= 2; $try++) {
            try {
                $res = $this->client->complete($this->systemPrompt, $user, self::OUTPUT_SCHEMA);
            } catch (AiRequestException $e) {
                // Rate limits / outages were already retried inside the client; don't hammer it again.
                if ($e->raw !== null) {
                    $raws[] = $e->raw;
                }
                $out['error'] = $e->getMessage();
                break;
            }
            $raws[] = $res['raw'];
            $out['model'] = $res['model'];
            $out['input_tokens'] += $res['input_tokens'];
            $out['output_tokens'] += $res['output_tokens'];

            $data = self::parseResponse($res['text']);
            if ($data === null) {
                $out['error'] = $res['stop_reason'] === 'refusal'
                    ? 'The AI declined to answer (refusal)'
                    : 'The AI reply was not valid JSON' . ($res['stop_reason'] === 'max_tokens' ? ' (it was cut off at the token limit)' : '');
                continue;
            }
            $errors = self::validate($data);
            if ($errors) {
                $out['error'] = 'The AI reply failed validation: ' . implode('; ', $errors);
                continue;
            }

            $out['ok'] = true;
            $out['error'] = null;
            $out['data'] = self::chooseFallbackEmail(
                self::applySafetyCheck(self::normalize($data), $scrape['emails'] ?? [], self::extraKnownEmails($scrape)),
                $scrape
            );
            break;
        }

        $out['raw'] = implode("\n\n----- retry -----\n\n", $raws);
        return $out;
    }

    /** Decodes the reply; tolerates a ```json fence or text around the object. Null if not a JSON object. */
    public static function parseResponse(string $text): ?array {
        $text = trim($text);
        $data = json_decode($text, true);
        if (!is_array($data) && preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);
        }
        // An object, not a JSON list (array_is_list() would need PHP 8.1; composer.json allows 7.4).
        return is_array($data) && $data !== [] && array_keys($data) !== range(0, count($data) - 1) ? $data : null;
    }

    /** @return string[] problems; empty when the reply is usable */
    public static function validate(array $data): array {
        $errors = [];
        if (!in_array($data['decision'] ?? null, self::DECISIONS, true)) {
            $errors[] = 'decision must be SEND, SEND_LOW_PRIORITY or SKIP (got ' . json_encode($data['decision'] ?? null) . ')';
        }
        $score = $data['score'] ?? null;
        if (!is_int($score) || $score < 0 || $score > 100) {
            $errors[] = 'score must be an integer from 0 to 100 (got ' . json_encode($score) . ')';
        }
        if (($data['decision'] ?? 'SKIP') !== 'SKIP') {
            foreach (['subject', 'body'] as $field) {
                if (!is_string($data[$field] ?? null) || trim($data[$field]) === '') {
                    $errors[] = "$field must not be empty when the decision is " . $data['decision'];
                }
            }
        }
        return $errors;
    }

    /**
     * Clears a to_email that is neither on the site nor already in the user's
     * lead list, and flags it. Sets to_email_source to 'site' or 'lead_list'.
     */
    public static function applySafetyCheck(array $data, array $foundEmails, array $knownEmails = []): array {
        $data['to_email_source'] = null;
        $email = $data['to_email'] ?? null;
        if ($email === null) {
            return $data;
        }
        // The AI may echo the "(from our lead list…)" label; compare the address only.
        $email = strtolower(trim(preg_replace('/\s*\(.*$/', '', $email)));
        if (in_array($email, array_map('strtolower', $foundEmails), true)) {
            $data['to_email'] = $email;
            $data['to_email_source'] = 'site';
        } elseif (in_array($email, array_map('strtolower', $knownEmails), true)) {
            $data['to_email'] = $email;
            $data['to_email_source'] = 'lead_list';
        } else {
            $data['to_email'] = null;
            $data['red_flags'][] = self::INVENTED_EMAIL_FLAG;
        }
        return $data;
    }

    /**
     * When the site publishes no email and the AI chose none, use the email
     * already saved for this site in the lead list — but only for agencies
     * worth contacting.
     */
    public static function chooseFallbackEmail(array $data, array $scrape): array {
        $known = self::extraKnownEmails($scrape);
        if ($data['to_email'] === null && empty($scrape['emails']) && $known && $data['decision'] !== 'SKIP') {
            $data['to_email'] = strtolower($known[0]);
            $data['to_email_source'] = 'lead_list';
        }
        return $data;
    }

    /** Lead-list emails that the scrape didn't already find on the site. */
    private static function extraKnownEmails(array $scrape): array {
        $site = array_map('strtolower', $scrape['emails'] ?? []);
        return array_values(array_filter(
            array_unique(array_map('strtolower', $scrape['known_emails'] ?? [])),
            fn($e) => !in_array($e, $site, true)
        ));
    }

    /** Coerces the optional fields to clean strings / nulls and the lists to string arrays. */
    private static function normalize(array $data): array {
        $str = function ($v) {
            return is_string($v) && trim($v) !== '' ? trim($v) : null;
        };
        $list = function ($v) {
            return is_array($v) ? array_values(array_filter(array_map('strval', array_filter($v, 'is_scalar')), fn($s) => trim($s) !== '')) : [];
        };
        $out = [
            'agency_name' => $str($data['agency_name'] ?? null),
            'decision' => $data['decision'],
            'score' => $data['score'],
            'reasons' => $list($data['reasons'] ?? []),
            'red_flags' => $list($data['red_flags'] ?? []),
        ];
        foreach (['contact_name', 'contact_role', 'to_email', 'other_channel', 'subject', 'body', 'follow_up_tip'] as $f) {
            $out[$f] = $str($data[$f] ?? null);
        }
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($data['ref_slug'] ?? ''))), '-');
        $out['ref_slug'] = $slug !== '' ? $slug : null;
        return $out;
    }
}
