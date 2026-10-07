<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/EnrichmentConfig.php';

use Pdp\Rules;

/**
 * Public-suffix-aware domain handling for Module A/B: turning an arbitrary
 * discovered URL into the correct registrable domain (e.g. "shop.example.co.uk"
 * -> "example.co.uk", not the naive "co.uk"), and checking it against the
 * outreach blocklist / site-builder allowlist. Distinct from
 * ProspectStore::normalizeDomain(), which does simpler www-stripping for
 * matching two already-known URLs against each other, not parsing arbitrary
 * search results.
 */
class DomainTool {
    private static ?Rules $rules = null;

    private static function rules(): Rules {
        if (self::$rules === null) {
            self::$rules = Rules::fromPath(__DIR__ . '/public_suffix_list.dat');
        }
        return self::$rules;
    }

    /**
     * The registrable domain (a.k.a. "eTLD+1") for a URL or bare host, or
     * null if it can't be parsed (e.g. a bare IP address).
     */
    public static function registrableDomain(string $urlOrHost): ?string {
        $host = self::extractHost($urlOrHost);
        if ($host === null) {
            return null;
        }
        $resolved = self::rules()->resolve($host);
        return $resolved->registrableDomain()->value();
    }

    /**
     * True if this host is a subdomain under a site-builder platform (e.g.
     * "mybiz.wixsite.com") — the subdomain itself IS the business's site,
     * so the registrable domain ("wixsite.com") is meaningless for blocking
     * or grouping purposes here.
     */
    public static function isSiteBuilderSubdomain(string $urlOrHost): bool {
        $host = self::extractHost($urlOrHost);
        if ($host === null) {
            return false;
        }
        $host = strtolower($host);
        foreach (EnrichmentConfig::SITE_BUILDER_DOMAINS as $builderDomain) {
            if ($host === $builderDomain || substr($host, -strlen('.' . $builderDomain)) === '.' . $builderDomain) {
                return true;
            }
        }
        return false;
    }

    /**
     * True if the registrable domain (or, for site builders, the full host)
     * is on the outreach blocklist — directories, social platforms, etc.
     * that are never a business's own website.
     */
    public static function isBlocked(string $urlOrHost): bool {
        $host = self::extractHost($urlOrHost);
        if ($host === null) {
            return true; // unparseable — never accept as a discovered website
        }
        $host = strtolower($host);
        if (substr($host, -4) === '.gov') {
            return true;
        }
        if (self::isSiteBuilderSubdomain($host)) {
            return false; // explicitly allowed even though the registrable domain looks like a platform
        }
        $registrable = self::registrableDomain($host);
        if ($registrable === null) {
            // A parseable host (e.g. a bare IP, or a single-label host like
            // "localhost") that the public suffix list can't resolve to a
            // registrable domain isn't the same as a known-bad directory —
            // let it through to actual candidate validation rather than
            // silently rejecting it the same way we'd reject Yelp.
            return false;
        }
        return in_array($registrable, EnrichmentConfig::BLOCKED_DOMAINS, true);
    }

    /**
     * The value to key discovery/grouping on: the full host for site
     * builders (each subdomain is a distinct business), otherwise the
     * registrable domain.
     */
    public static function groupingKey(string $urlOrHost): ?string {
        $host = self::extractHost($urlOrHost);
        if ($host === null) {
            return null;
        }
        if (self::isSiteBuilderSubdomain($host)) {
            return strtolower($host);
        }
        // Fall back to the raw host (e.g. a bare IP) when the PSL can't
        // resolve a registrable domain, so candidate dedup/grouping still
        // works instead of silently dropping these hosts from consideration.
        return self::registrableDomain($host) ?? strtolower($host);
    }

    private static function extractHost(string $urlOrHost): ?string {
        $host = parse_url($urlOrHost, PHP_URL_HOST);
        if ($host === null && strpos($urlOrHost, '/') === false) {
            $host = parse_url('http://' . $urlOrHost, PHP_URL_HOST);
        }
        return $host !== false && $host !== '' ? $host : null;
    }
}
