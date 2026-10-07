<?php

/**
 * Parses a Clutch.co directory page (e.g. clutch.co/web-developers/chicago)
 * into the same result shape the *Client::textSearch() methods produce, so
 * ProspectStore can save it like any other source.
 *
 * Clutch sits behind a Cloudflare challenge that blocks server-side
 * requests, so there is no client here: the user opens the page in their
 * own browser and hands us the HTML (saved file or pasted source).
 *
 * Clutch has changed its markup over the years, so listings are located
 * by structure rather than one exact class: a listing is an element with a
 * data-clutch-pid attribute or a provider-row / provider-list-item class,
 * and must contain a link to a /profile/ page.
 */
class ClutchParser {
    /**
     * @return array{category: ?string, location: ?string, results: array<int, array<string, mixed>>}
     */
    public static function parse(string $html): array {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Without the encoding hint DOMDocument reads the page as Latin-1
        // and mangles any non-ASCII company names.
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);

        [$category, $location] = self::detectCategoryAndLocation($xpath);

        $results = [];
        foreach (self::findListings($xpath) as $item) {
            $result = self::parseListing($xpath, $item);
            // Sponsored "spotlight" slots repeat firms that also appear in the
            // regular list — keep the first occurrence of each profile.
            if ($result !== null && !isset($results[$result['place_id']])) {
                $results[$result['place_id']] = $result;
            }
        }

        return ['category' => $category, 'location' => $location, 'results' => array_values($results)];
    }

    /**
     * @return DOMElement[] Outermost listing elements only — inner elements
     *                      (buttons, links) can carry data-clutch-pid too.
     */
    private static function findListings(DOMXPath $xpath): array {
        $nodes = $xpath->query(
            '//*[@data-clutch-pid]'
            . " | //*[contains(concat(' ', normalize-space(@class), ' '), ' provider-row ')]"
            . " | //*[contains(concat(' ', normalize-space(@class), ' '), ' provider-list-item ')]"
        );

        $candidates = [];
        foreach ($nodes as $node) {
            $candidates[] = $node;
        }

        $outermost = [];
        foreach ($candidates as $node) {
            $nested = false;
            for ($p = $node->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
                if (in_array($p, $candidates, true)) {
                    $nested = true;
                    break;
                }
            }
            if (!$nested) {
                $outermost[] = $node;
            }
        }
        return $outermost;
    }

    private static function parseListing(DOMXPath $xpath, DOMElement $item): ?array {
        $profileLink = null;
        $slug = null;
        foreach ($xpath->query('.//a[contains(@href, "/profile/")]', $item) as $a) {
            if (preg_match('#/profile/([a-z0-9\-_]+)#i', $a->getAttribute('href'), $m)) {
                // The first profile link is often the logo (no text); prefer one with a name.
                if ($profileLink === null || ($slug === strtolower($m[1]) && self::text($profileLink) === '')) {
                    $profileLink = $a;
                    $slug = strtolower($m[1]);
                }
            }
        }
        if ($profileLink === null) {
            return null;
        }

        $name = self::text($profileLink);
        if ($name === '') {
            $title = $xpath->query('.//h3 | .//h2', $item)->item(0);
            $name = $title ? self::text($title) : '';
        }
        if ($name === '') {
            return null;
        }

        $locality = self::firstByClass($xpath, $item, 'locality');
        $itemText = self::text($item);

        $reviewCount = null;
        if (preg_match('/(\d[\d,]*)\s+reviews?\b/i', $itemText, $m)) {
            $reviewCount = (int) str_replace(',', '', $m[1]);
        }

        $rating = self::firstByClass($xpath, $item, 'sg-rating__number')
            ?? self::firstByClass($xpath, $item, 'rating');
        if ($rating !== null && !preg_match('/^\d(\.\d)?$/', $rating)) {
            $rating = null;
        }

        return [
            'place_id' => 'clutch:' . $slug,
            'name' => $name,
            'address' => $locality ?? '',
            'city' => $locality !== null ? trim(explode(',', $locality)[0]) : null,
            'website' => self::findWebsite($xpath, $item),
            'phone' => null, // only on profile pages, not the directory listing
            'email' => null,
            'review_count' => $reviewCount,
            // Display-only extras (no prospects columns for these).
            'rating' => $rating,
            'hourly_rate' => self::firstByClass($xpath, $item, 'hourly-rate'),
            'min_project' => self::firstByClass($xpath, $item, 'min-project-size'),
            'employees' => self::firstByClass($xpath, $item, 'employees-count'),
            'profile_url' => 'https://clutch.co/profile/' . $slug,
        ];
    }

    /**
     * Clutch wraps outbound website links in a tracking redirect
     * (r.clutch.co/redirect?...&u=<encoded target>); unwrap it. Only the link
     * marked as the website button counts — other external links in a card
     * are social profiles, and saving linkedin.com as a website would make
     * ProspectStore's domain dedup merge unrelated firms together.
     */
    private static function findWebsite(DOMXPath $xpath, DOMElement $item): ?string {
        foreach ($xpath->query('.//a[@href]', $item) as $a) {
            $class = strtolower($a->getAttribute('class') . ' ' . ($a->parentNode instanceof DOMElement ? $a->parentNode->getAttribute('class') : ''));
            if (strpos($class, 'website') === false && strtolower(self::text($a)) !== 'visit website') {
                continue;
            }
            $url = self::unwrapRedirect($a->getAttribute('href'));
            if ($url !== null) {
                return $url;
            }
        }
        return null;
    }

    private static function unwrapRedirect(string $href): ?string {
        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        if ($host === '') {
            return null; // relative link — stays on clutch.co
        }

        if ($host === 'clutch.co' || substr($host, -10) === '.clutch.co') {
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
            $target = $query['u'] ?? $query['url'] ?? null;
            if (!is_string($target) || $target === '') {
                return null;
            }
            $href = $target;
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            if ($host === '' || $host === 'clutch.co' || substr($host, -10) === '.clutch.co') {
                return null;
            }
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        // Drop Clutch's utm_* tracking query; keep the path in case the
        // firm's site lives in a subfolder.
        $path = (string) parse_url($href, PHP_URL_PATH);
        return $scheme . '://' . $host . ($path !== '' ? $path : '/');
    }

    /**
     * Reads the directory's category and location from the canonical URL
     * (clutch.co/<category>/<location>), which browsers keep when saving.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function detectCategoryAndLocation(DOMXPath $xpath): array {
        $node = $xpath->query('//link[@rel="canonical"]/@href | //meta[@property="og:url"]/@content')->item(0);
        $url = $node ? $node->nodeValue : '';
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '' || strpos($path, 'profile/') === 0) {
            return [null, null];
        }
        $parts = explode('/', $path);
        $humanize = fn(string $s) => ucwords(str_replace(['-', '_'], ' ', $s));
        return [
            $humanize($parts[0]),
            isset($parts[1]) ? $humanize($parts[1]) : null,
        ];
    }

    private static function firstByClass(DOMXPath $xpath, DOMElement $item, string $class): ?string {
        $node = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]", $item)->item(0);
        if ($node === null) {
            return null;
        }
        $text = self::text($node);
        return $text !== '' ? $text : null;
    }

    private static function text(DOMNode $node): string {
        return trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }
}
