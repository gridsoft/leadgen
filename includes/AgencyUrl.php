<?php

/**
 * URL handling for Agency Outreach: one canonical form per agency site, so
 * pasting the same agency twice (with tracking tags, http vs https, a
 * trailing slash, www or not) finds the existing record instead of adding
 * a duplicate. Duplicates are detected by domain(), which is unique in the
 * agencies table.
 */
class AgencyUrl {
    /** Query parameters that only track the click and never change the page. */
    private const TRACKING_PARAMS = [
        'gclid', 'gbraid', 'wbraid', 'dclid', 'fbclid', 'msclkid', 'yclid', 'twclid', 'li_fat_id',
        'igshid', 'mc_cid', 'mc_eid', '_ga', '_gl', '_hsenc', '_hsmi', 'hsctatracking', 'mkt_tok',
        'ref', 'ref_src', 'srsltid', 'trk',
    ];

    /**
     * Canonical form: https, lowercase host, no tracking parameters, no
     * fragment, no trailing slash. Returns null for input that isn't a
     * usable website address.
     */
    public static function normalize(string $input): ?string {
        $input = trim($input);
        if ($input === '' || preg_match('/\s/', $input)) {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $input)) {
            $input = 'https://' . ltrim($input, '/');
        }

        $parts = parse_url($input);
        // A username/password part means this wasn't a website address ("mailto:hi@agency.com" parses that way).
        if ($parts === false || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = rtrim(strtolower($parts['host']), '.');
        // Needs a dot and a letter TLD — rejects "localhost", bare words and IPs.
        if (!preg_match('/^[a-z0-9\-]+(\.[a-z0-9\-]+)*\.[a-z]{2,}$/', $host)) {
            return null;
        }

        $url = 'https://' . $host;
        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            $url .= ':' . (int) $parts['port'];
        }
        $url .= rtrim($parts['path'] ?? '', '/');

        $query = self::stripTrackingParams($parts['query'] ?? '');
        if ($query !== '') {
            $url .= '?' . $query;
        }
        return $url;
    }

    /** The duplicate key: host without a leading "www.". */
    public static function domain(string $normalizedUrl): string {
        $host = strtolower((string) parse_url($normalizedUrl, PHP_URL_HOST));
        return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
    }

    /**
     * Drops utm_* and known click-tracking parameters. Works on the raw
     * query string (not parse_str) so remaining parameters keep their exact
     * spelling and order.
     */
    private static function stripTrackingParams(string $query): string {
        if ($query === '') {
            return '';
        }
        $kept = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = strtolower(urldecode(explode('=', $pair, 2)[0]));
            if (strpos($name, 'utm_') === 0 || in_array($name, self::TRACKING_PARAMS, true)) {
                continue;
            }
            $kept[] = $pair;
        }
        return implode('&', $kept);
    }
}
