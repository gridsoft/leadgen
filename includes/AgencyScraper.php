<?php
require_once __DIR__ . '/AgencyUrl.php';

/**
 * Collects what the AI needs to judge one agency: the visible text of the
 * homepage plus its about / contact / careers / services pages, and the
 * emails, phones and platform found in their HTML.
 *
 * HTTP and sleeping are injectable so tests can run against saved HTML
 * without touching the network.
 */
class AgencyScraper {
    /** Link text or URL keywords per page type, matched case-insensitively. Order = priority. */
    public const PAGE_TYPES = [
        'about' => ['about', 'team', 'who-we-are', 'who we are', 'our-story', 'our story'],
        'contact' => ['contact'],
        'careers' => ['career', 'jobs', 'hiring', 'join', 'work-with-us', 'work with us'],
        'services' => ['services', 'what-we-do', 'what we do'],
    ];
    public const MAX_PAGES = 6;
    public const TEXT_LIMIT = 6000;
    public const MIN_TEXT = 300;
    public const LOW_TEXT_WARNING = 'Very little text found, site may need JavaScript';
    public const NO_HTTPS_WARNING = 'The site does not load over HTTPS (secure connection failed); it was read over plain HTTP';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
    private const TIMEOUT = 15;
    private const DELAY_MS = 1000;

    private const JUNK_EMAIL_PARTS = ['sentry', 'wixpress', 'example.com', 'example.org', 'example.net', 'wordpress.org', 'john.doe', 'user@emaildomain.com'];
    private const PLACEHOLDER_EMAIL_DOMAINS = ['domain.com', 'yourdomain.com', 'yoursite.com', 'emaildomain.com', 'email.com', 'test.com'];
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif', 'avif', 'ico', 'bmp'];

    /** @var callable(string): array{ok: bool, status: int, html: string, final_url: string, error: ?string} */
    private $fetch;
    /** @var callable(int): void */
    private $sleep;

    public function __construct(?callable $fetch = null, ?callable $sleep = null) {
        $this->fetch = $fetch ?? [self::class, 'httpGet'];
        $this->sleep = $sleep ?? function (int $ms) { usleep($ms * 1000); };
    }

    /**
     * @return array{
     *   ok: bool, error: ?string,
     *   pages: array<string, array{url: string, text: string}>,
     *   emails: string[], phones: string[], platform: string, warnings: string[]
     * } pages is keyed home / about / contact / careers / services (only those fetched).
     */
    public function scrape(string $url): array {
        $result = ['ok' => false, 'error' => null, 'pages' => [], 'emails' => [], 'phones' => [], 'platform' => 'unknown', 'warnings' => []];

        $home = ($this->fetch)($url);
        // Some sites never set up HTTPS, or it's broken (centrodev.com), or it only speaks
        // TLS versions this server's OpenSSL can't — but they work over plain HTTP. Only an
        // encryption failure is retried; a missing or unreachable site would just fail twice.
        if (!$home['ok'] && $home['status'] === 0 && stripos($url, 'https://') === 0
            && preg_match('/\b(SSL|TLS)\b/i', (string) $home['error'])) {
            $plain = ($this->fetch)('http://' . substr($url, strlen('https://')));
            if ($plain['ok']) {
                $home = $plain;
                $result['warnings'][] = self::NO_HTTPS_WARNING;
            }
        }
        if (!$home['ok']) {
            $result['error'] = 'Could not fetch the homepage: ' . ($home['error'] ?? ('HTTP ' . $home['status']));
            return $result;
        }
        // Redirects (http → https, → www) decide which host counts as "same site".
        $baseUrl = $home['final_url'] ?: $url;
        $htmls = ['home' => $home['html']];
        $result['pages']['home'] = ['url' => $baseUrl, 'text' => self::limitText(self::htmlToText($home['html']))];

        $fetched = 1;
        foreach (self::findPageLinks($home['html'], $baseUrl) as $type => $pageUrl) {
            if ($fetched >= self::MAX_PAGES) {
                break;
            }
            ($this->sleep)(self::DELAY_MS); // be polite: one request per second per site
            $page = ($this->fetch)($pageUrl);
            $fetched++;
            if (!$page['ok']) {
                $result['warnings'][] = "Could not fetch the $type page ($pageUrl): " . ($page['error'] ?? ('HTTP ' . $page['status']));
                continue;
            }
            $htmls[$type] = $page['html'];
            $result['pages'][$type] = ['url' => $page['final_url'] ?: $pageUrl, 'text' => self::limitText(self::htmlToText($page['html']))];
        }

        $emails = [];
        $phones = [];
        foreach ($htmls as $html) {
            $emails = array_merge($emails, self::extractEmails($html));
            $phones = array_merge($phones, self::extractPhones($html));
        }
        $result['emails'] = array_values(array_unique($emails));
        $result['phones'] = self::dedupePhones($phones);

        // The homepage decides; other pages only fill in when it gives no signal.
        foreach ($htmls as $html) {
            $platform = self::detectPlatform($html);
            if ($platform !== 'unknown') {
                $result['platform'] = $platform;
                break;
            }
        }

        $totalText = array_sum(array_map(fn($p) => mb_strlen($p['text']), $result['pages']));
        if ($totalText < self::MIN_TEXT) {
            $result['warnings'][] = self::LOW_TEXT_WARNING;
        }

        $result['ok'] = true;
        return $result;
    }

    /**
     * Picks at most one same-site link per page type from the homepage.
     * When several links match a type, the shallowest path wins (nav links
     * like /about beat blog posts like /blog/about-our-new-office).
     *
     * @return array<string, string> type => absolute URL, in PAGE_TYPES order
     */
    public static function findPageLinks(string $html, string $baseUrl): array {
        $xpath = self::xpath($html);
        $baseDomain = AgencyUrl::domain($baseUrl);
        $homePath = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');

        $links = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            $abs = self::resolveUrl($baseUrl, trim($a->getAttribute('href')));
            if ($abs === null || AgencyUrl::domain($abs) !== $baseDomain) {
                continue;
            }
            $path = rtrim((string) parse_url($abs, PHP_URL_PATH), '/');
            if ($path === $homePath || preg_match('/\.(pdf|jpe?g|png|gif|webp|svg|zip|docx?)$/i', $path)) {
                continue;
            }
            $links[] = [
                'url' => strtok($abs, '#'),
                'path' => strtolower(urldecode($path)),
                'text' => strtolower(trim(preg_replace('/\s+/u', ' ', $a->textContent))),
                'depth' => substr_count(trim($path, '/'), '/'),
            ];
        }

        $chosen = [];
        $used = [];
        foreach (self::PAGE_TYPES as $type => $keywords) {
            $best = null;
            foreach ($links as $link) {
                if (isset($used[$link['url']])) {
                    continue;
                }
                foreach ($keywords as $kw) {
                    if (strpos($link['path'], $kw) !== false || strpos($link['text'], $kw) !== false) {
                        if ($best === null || $link['depth'] < $best['depth']) {
                            $best = $link;
                        }
                        break;
                    }
                }
            }
            if ($best !== null) {
                $chosen[$type] = $best['url'];
                $used[$best['url']] = true;
            }
        }
        return $chosen;
    }

    /** Visible text: scripts, styles, noscript, svg and comments removed; whitespace collapsed. */
    public static function htmlToText(string $html): string {
        $xpath = self::xpath($html);
        $remove = [];
        foreach ($xpath->query('//script | //style | //noscript | //svg | //template | //iframe | //comment()') as $node) {
            $remove[] = $node;
        }
        foreach ($remove as $node) {
            $node->parentNode->removeChild($node);
        }

        // Join text nodes with spaces so "<li>About</li><li>Contact</li>" doesn't become "AboutContact".
        $nodes = $xpath->query('//body//text()');
        if ($nodes->length === 0) {
            $nodes = $xpath->query('//text()');
        }
        $parts = [];
        foreach ($nodes as $node) {
            $parts[] = $node->nodeValue;
        }
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /** @return string[] lowercase, deduplicated; mailto: addresses first */
    public static function extractEmails(string $html): array {
        $found = [];
        if (preg_match_all('/href\s*=\s*["\']\s*mailto:([^"\'?#\s>]+)/i', $html, $m)) {
            foreach ($m[1] as $addr) {
                $found[] = rawurldecode(html_entity_decode($addr, ENT_QUOTES | ENT_HTML5));
            }
        }

        // Inline JSON often escapes "@" and angle brackets (@, >), which
        // would otherwise glue "u003e" onto the start of an address.
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $text = str_ireplace(['\\u0040', '%40'], '@', $text);
        $text = preg_replace('/\\\\u[0-9a-f]{4}/i', ' ', $text);
        if (preg_match_all('/[a-z0-9][a-z0-9._%+\-]*@[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?)*\.[a-z]{2,24}/i', $text, $m)) {
            $found = array_merge($found, $m[0]);
        }

        $clean = [];
        foreach ($found as $email) {
            $email = strtolower(trim($email, " \t\n\r\0\x0B.,;:"));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && !self::isJunkEmail($email)) {
                $clean[$email] = true;
            }
        }
        return array_keys($clean);
    }

    public static function isJunkEmail(string $email): bool {
        $email = strtolower($email);
        foreach (self::JUNK_EMAIL_PARTS as $junk) {
            if (strpos($email, $junk) !== false) {
                return true;
            }
        }
        $domain = substr(strrchr($email, '@'), 1);
        $tld = substr(strrchr($domain, '.'), 1);
        // "logo@2x.png" and similar image file names that look like addresses.
        if (in_array($tld, self::IMAGE_EXTENSIONS, true)) {
            return true;
        }
        return in_array($domain, self::PLACEHOLDER_EMAIL_DOMAINS, true);
    }

    /** @return string[] phone numbers from tel: links, as written */
    public static function extractPhones(string $html): array {
        $phones = [];
        if (preg_match_all('/href\s*=\s*["\']\s*tel:([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $raw) {
                $phone = trim(preg_replace('/\s+/', ' ', rawurldecode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5))));
                if (strlen(preg_replace('/\D/', '', $phone)) >= 6) {
                    $phones[] = $phone;
                }
            }
        }
        return self::dedupePhones($phones);
    }

    /** Same number written differently ("+1 (312) 555-0100" / "+13125550100") counts once. */
    private static function dedupePhones(array $phones): array {
        $seen = [];
        foreach ($phones as $phone) {
            $key = preg_replace('/\D/', '', $phone);
            if (strlen($key) > 10 && $key[0] === '1') {
                $key = substr($key, -10); // fold the US country code
            }
            $seen[$key] ??= $phone;
        }
        return array_values($seen);
    }

    /** WordPress (with version when the generator tag has one), Wix, Squarespace, Webflow, Shopify or "unknown". */
    public static function detectPlatform(string $html): string {
        $generator = '';
        if (preg_match('/<meta[^>]+name=["\']generator["\'][^>]*>/i', $html, $tag)
            && preg_match('/content=["\']([^"\']+)["\']/i', $tag[0], $c)) {
            $generator = $c[1];
        }

        if (preg_match('/WordPress\s*([\d.]+)?/i', $generator, $wp)) {
            return 'WordPress' . (!empty($wp[1]) ? ' ' . $wp[1] : '');
        }
        $checks = [
            'Wix' => '/wixstatic\.com|wix\.com website builder/i',
            'Squarespace' => '/squarespace(-cdn)?\.com|static1\.squarespace/i',
            'Webflow' => '/data-wf-site|webflow/i',
            'Shopify' => '/cdn\.shopify\.com|shopify\.theme/i',
        ];
        foreach ($checks as $name => $pattern) {
            if (preg_match($pattern, $generator)) {
                return $name;
            }
        }
        if (preg_match('#/wp-content/|/wp-includes/#i', $html)) {
            return 'WordPress';
        }
        foreach ($checks as $name => $pattern) {
            if (preg_match($pattern, $html)) {
                return $name;
            }
        }
        return 'unknown';
    }

    public static function resolveUrl(string $base, string $href): ?string {
        if ($href === '' || $href[0] === '#' || preg_match('/^(mailto|tel|javascript|data|sms|whatsapp):/i', $href)) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        if (strpos($href, '//') === 0) {
            return $scheme . ':' . $href;
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $href)) {
            return null; // some other scheme
        }
        $origin = $scheme . '://' . parse_url($base, PHP_URL_HOST) . (parse_url($base, PHP_URL_PORT) ? ':' . parse_url($base, PHP_URL_PORT) : '');
        if ($href[0] === '/') {
            return $origin . $href;
        }
        $basePath = (string) parse_url($base, PHP_URL_PATH);
        $dir = substr($basePath, 0, (int) strrpos($basePath, '/') + 1) ?: '/';
        return $origin . $dir . $href;
    }

    private static function limitText(string $text): string {
        return mb_strlen($text) > self::TEXT_LIMIT ? rtrim(mb_substr($text, 0, self::TEXT_LIMIT)) . ' …' : $text;
    }

    private static function xpath(string $html): DOMXPath {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($doc);
    }

    /**
     * The real fetcher: browser-like User-Agent, 15 s timeout, follows redirects.
     * Only HTML responses count as a successful page.
     *
     * @return array{ok: bool, status: int, html: string, final_url: string, error: ?string}
     */
    public static function httpGet(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ],
            CURLOPT_ENCODING => '', // accept gzip/br and decode transparently
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'html' => '', 'final_url' => $url, 'error' => $error ?: 'request failed'];
        }
        if ($status >= 400) {
            return ['ok' => false, 'status' => $status, 'html' => '', 'final_url' => $finalUrl, 'error' => "HTTP $status"];
        }
        if ($contentType !== '' && stripos($contentType, 'html') === false) {
            return ['ok' => false, 'status' => $status, 'html' => '', 'final_url' => $finalUrl, 'error' => "not an HTML page ($contentType)"];
        }
        return ['ok' => true, 'status' => $status, 'html' => (string) $body, 'final_url' => $finalUrl, 'error' => null];
    }
}
