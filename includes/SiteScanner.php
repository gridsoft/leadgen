<?php

/**
 * Lightweight signals PageSpeed Insights doesn't give us directly: whether
 * the site runs WordPress, which theme, whether a Facebook page is linked,
 * whether any booking widget is present, and any contact email we can find
 * on the homepage or an obvious contact page. These are heuristics based
 * on markup, not certainties.
 */
class SiteScanner {
    private const BOOKING_KEYWORDS = [
        'book now', 'book an appointment', 'schedule an appointment', 'schedule appointment',
        'calendly', 'acuityscheduling', 'zocdoc', 'opencare', 'book online', 'online booking',
        'setmore', 'squareup.com/appointments',
    ];

    // Platform/tooling noise that shows up as "emails" in scraped markup but
    // is never a real contact address (page builder internals, error trackers,
    // placeholder copy left in themes).
    private const EMAIL_DOMAIN_BLOCKLIST = [
        'wixpress.com', 'sentry.io', 'sentry-next.wixpress.com', 'example.com',
        'godaddy.com', 'schema.org', 'w3.org', 'test.com', 'yourdomain.com',
        'domain.com', 'email.com', 'wordpress.org', 'gmpg.org',
        'company.com', 'mysite.com', 'yoursite.com', 'website.com', 'seoclerks.com',
    ];

    private const CONTACT_LINK_PATTERN = '#href=["\']([^"\']*(?:contact|kontakt|get-in-touch)[^"\']*)["\']#i';

    // Common contact-form plugin/builder signatures — catches forms that
    // don't have an obvious "email"+"message" field pair in the raw markup
    // (e.g. a JS-rendered widget that just leaves its container div behind).
    private const FORM_PLUGIN_SIGNATURES = [
        'wpcf7-form', 'gform_wrapper', 'gform_body', 'ninja-forms', 'fluentform',
        'formidable-form', 'hs-form', 'hubspot-form', 'elementor-form', 'wix-form',
        'forminator', 'wpforms-form', 'contact-form-7',
    ];

    // Known live-chat/chatbot embed signatures (script src or widget markup).
    // This catches "some chat widget is present," not specifically "is AI" —
    // that isn't a thing markup can confirm, most of these vendors offer
    // both AI and human-staffed chat on the same embed.
    private const CHAT_WIDGET_SIGNATURES = [
        'widget.intercom.io', 'js.intercomcdn.com', 'js.driftt.com', 'widget.drift.com',
        'client.crisp.chat', 'embed.tawk.to', 'code.tidio.co', 'widget.tidiochat.com',
        'js.hs-scripts.com', 'js-na1.hs-scripts.com', 'cdn.livechatinc.com',
        'widget-mediator.zopim.com', 'static.zdassets.com', 'v2.zopim.com',
        'widget.freshchat.com', 'chatra.io', 'gorgias.chat', 'voiceflow.com',
        'landbot.io', 'chatbase.co', 'manychat.com', 'reamaze.com', 'static.olark.com',
    ];

    // Why the most recent fetch() returned null, as a human-readable sentence.
    private ?string $lastFetchError = null;

    public function scan(string $url): array {
        $page = $this->fetch($url);

        if ($page === null) {
            return [
                'fetch_ok' => false,
                'fetch_error' => $this->lastFetchError,
                'contact_page_checked' => false,
                'is_wordpress' => null,
                'theme_name' => null,
                'facebook_url' => null,
                'has_booking_signal' => null,
                'emails_found' => [],
                'phone_found' => null,
                'has_contact_form' => null,
                'has_chat_widget' => null,
            ];
        }

        $html = $page['html'];

        $isWordpress = (bool) preg_match('/wp-content|wp-json|\/wp-includes\//i', $html);

        $themeName = null;
        if (preg_match('#wp-content/themes/([a-zA-Z0-9_-]+)#', $html, $m)) {
            $themeName = $m[1];
        }

        $facebookUrl = null;
        if (preg_match('#https?://(?:www\.)?facebook\.com/[a-zA-Z0-9_.\-/]+#i', $html, $m)) {
            $facebookUrl = rtrim($m[0], '/"\'');
        }

        $lowerHtml = strtolower($html);
        $hasBooking = false;
        foreach (self::BOOKING_KEYWORDS as $kw) {
            if (strpos($lowerHtml, $kw) !== false) {
                $hasBooking = true;
                break;
            }
        }

        $emails = $this->extractEmails($html);
        $phone = $this->extractPhone($html);
        $hasContactForm = $this->detectContactForm($html);
        $hasChatWidget = $this->detectChatWidget($html);

        // No email on the homepage — try one obvious contact page before giving up.
        $contactPageChecked = false;
        if ((empty($emails) || $phone === null || !$hasContactForm) && preg_match(self::CONTACT_LINK_PATTERN, $html, $m)) {
            $contactUrl = $this->resolveUrl($m[1], $url);
            if ($contactUrl !== null) {
                $contactPage = $this->fetch($contactUrl);
                if ($contactPage !== null) {
                    $contactPageChecked = true;
                    if (empty($emails)) {
                        $emails = $this->extractEmails($contactPage['html']);
                    }
                    if ($phone === null) {
                        $phone = $this->extractPhone($contactPage['html']);
                    }
                    if (!$hasContactForm) {
                        $hasContactForm = $this->detectContactForm($contactPage['html']);
                    }
                    if (!$hasChatWidget) {
                        $hasChatWidget = $this->detectChatWidget($contactPage['html']);
                    }
                }
            }
        }

        return [
            'fetch_ok' => true,
            'fetch_error' => null,
            'contact_page_checked' => $contactPageChecked,
            'is_wordpress' => $isWordpress,
            'theme_name' => $themeName,
            'facebook_url' => $facebookUrl,
            'has_booking_signal' => $hasBooking,
            'emails_found' => $emails,
            'phone_found' => $phone,
            'has_contact_form' => $hasContactForm,
            'has_chat_widget' => $hasChatWidget,
        ];
    }

    private function fetch(string $url): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 20,
            // A regular browser identity: many sites (Cloudflare, WAFs) answer
            // unknown bot user agents with 403 before serving any page.
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ],
            CURLOPT_ENCODING => '', // accept gzip/br like a browser
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
        ]);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($html === false || $httpCode >= 400) {
            $this->lastFetchError = self::describeFetchError($errno, $httpCode);
            return null;
        }
        $this->lastFetchError = null;

        return ['html' => $html, 'http_code' => $httpCode];
    }

    private static function describeFetchError(int $curlErrno, int $httpCode): string {
        switch ($curlErrno) {
            case 6:  return "Domain doesn't exist or isn't resolving";
            case 7:  return 'Server refused the connection';
            case 28: return 'Site timed out (no response in 20s)';
            case 35: case 51: case 53: case 54: case 58: case 59: case 60: case 77: case 83:
                return 'SSL/HTTPS certificate error';
            case 47: return 'Too many redirects';
            case 52: case 56: return 'Server closed the connection without responding';
        }
        if ($curlErrno !== 0) {
            return "Couldn't load the site (network error $curlErrno)";
        }
        if ($httpCode === 403 || $httpCode === 401) {
            return "Site blocks automated visits ($httpCode)";
        }
        if ($httpCode === 429) {
            return 'Site rate-limited the scan (429), try again later';
        }
        if ($httpCode === 404 || $httpCode === 410) {
            return "Website page not found ($httpCode)";
        }
        if ($httpCode >= 500) {
            return "Website is down or erroring ($httpCode)";
        }
        return "Couldn't load the site (HTTP $httpCode)";
    }

    /**
     * @return string[]
     */
    private function extractEmails(string $html): array {
        $found = [];

        // mailto: links first — highest confidence, and cheap to pull the
        // address out even when it carries a query string (?subject=...).
        if (preg_match_all('#mailto:([^"\'?\s]+)#i', $html, $matches)) {
            foreach ($matches[1] as $addr) {
                $found[] = $addr;
            }
        }

        // Cloudflare email obfuscation: data-cfemail="hex" or
        // /cdn-cgi/l/email-protection#hex. First byte is the XOR key.
        if (preg_match_all('#(?:data-cfemail=["\']|email-protection\#)([0-9a-f]{4,})#i', $html, $matches)) {
            foreach ($matches[1] as $hex) {
                $key = hexdec(substr($hex, 0, 2));
                $decoded = '';
                for ($i = 2; $i + 1 < strlen($hex); $i += 2) {
                    $decoded .= chr(hexdec(substr($hex, $i, 2)) ^ $key);
                }
                $found[] = $decoded;
            }
        }

        // Plain-text addresses anywhere on the page.
        if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $html, $matches)) {
            foreach ($matches[0] as $addr) {
                $found[] = $addr;
            }
        }

        $clean = [];
        foreach ($found as $addr) {
            $addr = strtolower(trim($addr));

            // Retina image filenames masquerade as emails: logo@2x.png, icon@3x.jpg.
            if (preg_match('/\.(png|jpe?g|gif|svg|webp|avif|ico|bmp|tiff?)$/i', $addr)) {
                continue;
            }

            $domain = substr(strrchr($addr, '@'), 1);
            if ($domain === false) {
                continue;
            }
            // Match subdomains too (sentry.wixpress.com is under wixpress.com).
            foreach (self::EMAIL_DOMAIN_BLOCKLIST as $blocked) {
                if ($domain === $blocked || str_ends_with($domain, ".$blocked")) {
                    continue 2;
                }
            }

            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $clean[$addr] = true;
        }

        return array_slice(array_keys($clean), 0, 5);
    }

    /**
     * Public so Module A's candidate validator (WebsiteDiscovery) can reuse
     * the same tel:/JSON-LD extraction logic to check a discovered
     * candidate page for the lead's own phone number, without duplicating it.
     */
    public function extractPhone(string $html): ?string {
        // tel: links first — same idea as mailto:, highest confidence.
        if (preg_match('#href=["\']tel:([^"\']+)["\']#i', $html, $m)) {
            $clean = $this->normalizePhone($m[1]);
            if ($clean !== null) {
                return $clean;
            }
        }

        // Structured data (schema.org JSON-LD) — SEO plugins fill this in
        // even when no phone is visibly rendered on the page.
        if (preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks)) {
            foreach ($blocks[1] as $block) {
                $data = json_decode(trim($block), true);
                if ($data === null) {
                    continue;
                }
                $tel = $this->findTelephone($data);
                if ($tel !== null) {
                    $clean = $this->normalizePhone($tel);
                    if ($clean !== null) {
                        return $clean;
                    }
                }
            }
        }

        return null;
    }

    private function findTelephone($node): ?string {
        if (is_array($node)) {
            if (isset($node['telephone']) && is_string($node['telephone'])) {
                return $node['telephone'];
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $found = $this->findTelephone($value);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }
        return null;
    }

    private function normalizePhone(string $raw): ?string {
        $raw = trim(explode('?', $raw)[0]); // drop tel: query-string extras
        $digits = preg_replace('/[^\d+]/', '', $raw);
        if ($digits === null || strlen(preg_replace('/\D/', '', $digits)) < 7) {
            return null;
        }
        return $digits;
    }

    /**
     * A "contact form" here means: a form asking for an email plus a
     * free-text message, or a recognized contact-form plugin's markup.
     * This deliberately doesn't try to catch every login/search/newsletter
     * form — those don't have the email+message combination and aren't
     * what "can I reach this business" is asking about.
     */
    private function detectContactForm(string $html): bool {
        $lowerHtml = strtolower($html);
        foreach (self::FORM_PLUGIN_SIGNATURES as $sig) {
            if (strpos($lowerHtml, $sig) !== false) {
                return true;
            }
        }

        if (preg_match_all('#<form\b.*?</form>#is', $html, $forms)) {
            foreach ($forms[0] as $form) {
                $hasEmailField = (bool) preg_match('/type=["\']email["\']|name=["\'][^"\']*email[^"\']*["\']/i', $form);
                $hasMessageField = (bool) preg_match('/<textarea\b/i', $form);
                if ($hasEmailField && $hasMessageField) {
                    return true;
                }
            }
        }

        return false;
    }

    private function detectChatWidget(string $html): bool {
        $lowerHtml = strtolower($html);
        foreach (self::CHAT_WIDGET_SIGNATURES as $sig) {
            if (strpos($lowerHtml, $sig) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Public so WebsiteDiscovery can resolve links found on candidate pages
     * with the same logic used for the existing contact-page fallback.
     */
    public function resolveUrl(string $href, string $base): ?string {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $root = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $href[0] === '/' ? $root . $href : $root . '/' . ltrim($href, './');
    }
}
