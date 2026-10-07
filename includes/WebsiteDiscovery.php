<?php
require_once __DIR__ . '/SearchProvider.php';
require_once __DIR__ . '/SearchCache.php';
require_once __DIR__ . '/DomainTool.php';
require_once __DIR__ . '/PhoneMatcher.php';
require_once __DIR__ . '/NameMatcher.php';
require_once __DIR__ . '/SiteScanner.php';
require_once __DIR__ . '/EnrichmentConfig.php';

/**
 * Module A: finds a lead's website when none is on file. Two searches
 * (business name + city, then the phone number if the first finds
 * nothing usable), filtered against the blocklist, each surviving
 * candidate fetched and checked for the lead's own phone number before
 * being trusted — this is what keeps a directory listing or an
 * unrelated business from being accepted as "the" website.
 */
class WebsiteDiscovery {
    /**
     * @param array<string, mixed> $prospect Must have phone_national10 already set (Module A1's precondition).
     * @return array{outcome: string, website: ?string, evidence_url: ?string, discovery_source: ?string}
     */
    public static function discover(PDO $pdo, SearchProvider $search, array $prospect): array {
        $empty = ['website' => null, 'evidence_url' => null, 'discovery_source' => null];

        if (empty($prospect['phone_national10'])) {
            return ['outcome' => 'no_valid_phone'] + $empty;
        }

        $cityPart = $prospect['city'] ? ' ' . $prospect['city'] : '';
        $regionPart = $prospect['region'] ? ' ' . $prospect['region'] : '';
        $nameQuery = '"' . $prospect['business_name'] . '"' . $cityPart . $regionPart;

        $bestNameMatch = null; // ['url' => ..., 'similarity' => ...] — kept only if nothing gets a phone match

        foreach ([$nameQuery, self::phoneQuery($prospect['phone_national10'])] as $queryIndex => $query) {
            $results = SearchCache::search($pdo, $search, $query, EnrichmentConfig::SEARCH_RESULTS_PER_QUERY);
            $candidates = self::filterCandidates($results);

            foreach ($candidates as $candidateUrl) {
                $validated = self::validateCandidate($prospect, $candidateUrl);

                if ($validated['status'] === 'accepted') {
                    return [
                        'outcome' => 'accepted',
                        'website' => $candidateUrl,
                        'evidence_url' => $validated['evidence_url'],
                        'discovery_source' => $queryIndex === 0 ? 'search_name' : 'search_phone',
                    ];
                }
                if ($validated['status'] === 'name_match_only') {
                    if ($bestNameMatch === null || $validated['similarity'] > $bestNameMatch['similarity']) {
                        $bestNameMatch = ['url' => $candidateUrl, 'similarity' => $validated['similarity']];
                    }
                }
            }

            if ($queryIndex + 1 >= EnrichmentConfig::SEARCH_MAX_QUERIES_PER_LEAD) {
                break;
            }
        }

        if ($bestNameMatch !== null) {
            return [
                'outcome' => 'name_match_only',
                'website' => null,
                'evidence_url' => $bestNameMatch['url'],
                'discovery_source' => 'search',
            ];
        }

        return ['outcome' => 'nothing_found'] + $empty;
    }

    private static function phoneQuery(string $nationalTen): string {
        $formatted = sprintf(
            '(%s) %s-%s',
            substr($nationalTen, 0, 3), substr($nationalTen, 3, 3), substr($nationalTen, 6, 4)
        );
        return '"' . $formatted . '"';
    }

    /**
     * @param array<int, array{url: string}> $results
     * @return string[] deduped, blocklist-filtered candidate URLs
     */
    private static function filterCandidates(array $results): array {
        $seen = [];
        $candidates = [];
        foreach ($results as $r) {
            $url = $r['url'] ?? '';
            if ($url === '' || DomainTool::isBlocked($url)) {
                continue;
            }
            $key = DomainTool::groupingKey($url);
            if ($key === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $candidates[] = $url;
        }
        return $candidates;
    }

    /**
     * @return array{status: string, evidence_url: ?string, similarity: ?float}
     */
    private static function validateCandidate(array $prospect, string $url): array {
        $page = self::fetchCapped($url);
        if ($page === null || self::isParked($page['html'], $url)) {
            return ['status' => 'rejected', 'evidence_url' => null, 'similarity' => null];
        }

        $scanner = new SiteScanner();
        $pagesToCheck = [['url' => $url, 'html' => $page['html']]];

        foreach (self::findLinkedPages($page['html'], $url, ['contact', 'about', 'location']) as $linkedUrl) {
            if (count($pagesToCheck) > 2) {
                break;
            }
            $linkedPage = self::fetchCapped($linkedUrl);
            if ($linkedPage !== null) {
                $pagesToCheck[] = ['url' => $linkedUrl, 'html' => $linkedPage['html']];
            }
        }

        foreach ($pagesToCheck as $p) {
            $foundPhone = $scanner->extractPhone($p['html']);
            if ($foundPhone !== null && PhoneMatcher::nationalTenDigit($foundPhone) === $prospect['phone_national10']) {
                return ['status' => 'accepted', 'evidence_url' => $p['url'], 'similarity' => null];
            }
        }

        $similarity = NameMatcher::similarity($prospect['business_name'], self::guessNameFromPage($page['html']));
        $cityOnPage = $prospect['city'] && stripos($page['html'], $prospect['city']) !== false;
        if ($similarity >= EnrichmentConfig::NAME_SIMILARITY_THRESHOLD && $cityOnPage) {
            return ['status' => 'name_match_only', 'evidence_url' => $url, 'similarity' => $similarity];
        }

        return ['status' => 'rejected', 'evidence_url' => null, 'similarity' => null];
    }

    /**
     * Best-effort business name for the page: the <title> tag, since we
     * have no other reliable field to compare against without a full
     * schema.org Organization parse.
     */
    private static function guessNameFromPage(string $html): string {
        return preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? html_entity_decode(trim($m[1])) : '';
    }

    private static function isParked(string $html, string $url): bool {
        $lower = strtolower($html);
        foreach (EnrichmentConfig::PARKED_PAGE_PHRASES as $phrase) {
            if (strpos($lower, $phrase) !== false) {
                return true;
            }
        }
        $domain = DomainTool::registrableDomain($url);
        if ($domain !== null && in_array($domain, EnrichmentConfig::PARKING_HOSTS, true)) {
            return true;
        }
        // Near-empty page: strip tags, collapse whitespace, see what's left.
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        return strlen($text) < 40;
    }

    /**
     * @param string[] $keywords
     * @return string[] up to a few resolved absolute URLs whose link text or href contains any keyword
     */
    private static function findLinkedPages(string $html, string $baseUrl, array $keywords): array {
        $pattern = '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is';
        if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $scanner = new SiteScanner();
        $found = [];
        foreach ($matches as $m) {
            $href = $m[1];
            $text = strip_tags($m[2]);
            $haystack = strtolower($href . ' ' . $text);
            foreach ($keywords as $kw) {
                if (strpos($haystack, $kw) !== false) {
                    $resolved = $scanner->resolveUrl($href, $baseUrl);
                    if ($resolved !== null) {
                        $found[$resolved] = true;
                    }
                    break;
                }
            }
        }
        return array_keys($found);
    }

    private static function fetchCapped(string $url): ?array {
        $maxBytes = EnrichmentConfig::REQUEST_MAX_RESPONSE_BYTES;
        $downloaded = '';
        $exceeded = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => EnrichmentConfig::REQUEST_TOTAL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => EnrichmentConfig::REQUEST_CONNECT_TIMEOUT,
            CURLOPT_USERAGENT => EnrichmentConfig::USER_AGENT,
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$downloaded, &$exceeded, $maxBytes) {
                $downloaded .= $chunk;
                if (strlen($downloaded) > $maxBytes) {
                    $exceeded = true;
                    return 0; // aborts the transfer
                }
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($exceeded || $httpCode >= 400 || $downloaded === '') {
            return null;
        }

        return ['html' => $downloaded, 'http_code' => $httpCode];
    }
}
