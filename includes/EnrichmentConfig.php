<?php

/**
 * Thresholds, the domain blocklist, and default caps for the enrichment
 * pipeline (Modules A/B/C). API keys stay in config.local.php per the
 * existing convention — this file is safe to commit and edit directly.
 */
class EnrichmentConfig {
    // Directories, social platforms, and review sites are never a business's
    // own website — matched against the registrable domain, so subdomains
    // (m.yelp.com, www.facebook.com) are caught automatically. All .gov
    // domains are blocked separately in DomainTool, not listed here.
    public const BLOCKED_DOMAINS = [
        'yelp.com', 'yelp.ca', 'facebook.com', 'instagram.com', 'linkedin.com',
        'x.com', 'twitter.com', 'tiktok.com', 'youtube.com', 'pinterest.com',
        'nextdoor.com', 'bbb.org', 'yellowpages.com', 'yellowpages.ca',
        'superpages.com', 'angi.com', 'angieslist.com', 'homeadvisor.com',
        'thumbtack.com', 'houzz.com', 'porch.com', 'bark.com', 'buildzoom.com',
        'manta.com', 'mapquest.com', 'google.com', 'bing.com', 'apple.com',
        'foursquare.com', 'tripadvisor.com', 'chamberofcommerce.com',
        'bizapedia.com', 'opencorporates.com', '411.ca', 'canpages.ca',
        'wikipedia.org', 'reddit.com', 'craigslist.org', 'expertise.com',
        'birdeye.com', 'networx.com',
    ];

    // Subdomains under these ARE the business's actual website — never
    // blocked, and never grouped by registrable domain (each subdomain is
    // a distinct business).
    public const SITE_BUILDER_DOMAINS = [
        'wixsite.com', 'square.site', 'godaddysites.com', 'business.site',
    ];

    // Parked-domain-for-sale marketplaces/parking hosts (Module A4).
    public const PARKING_HOSTS = ['sedo.com', 'bodis.com', 'dan.com', 'afternic.com'];
    public const PARKED_PAGE_PHRASES = ['domain is for sale', 'buy this domain', 'this domain may be for sale'];

    // Module A4: token-similarity threshold for a "name matches but phone
    // doesn't" candidate to be worth a human's review instead of discarding.
    public const NAME_SIMILARITY_THRESHOLD = 0.85;

    // Module A5: a discovered domain accepted for more than this many
    // distinct leads is probably a franchise HQ or an aggregator missing
    // from the blocklist above, not each lead's own site.
    public const SHARED_DOMAIN_THRESHOLD = 3;

    // Module A2: search query limits.
    public const SEARCH_MAX_QUERIES_PER_LEAD = 2;
    public const SEARCH_RESULTS_PER_QUERY = 5;
    public const SEARCH_CACHE_TTL_DAYS = 30;

    // Module B2: candidate local-parts, tried in order.
    public const EMAIL_PATTERNS = ['info', 'contact', 'office'];
    public const VERIFICATION_CACHE_TTL_DAYS = 60;
    // Abstract API's free tier enforces a requests/second cap (confirmed live:
    // a second call within the same second returns HTTP 429) — PatternGuesser
    // can fire up to 3 calls back to back for one domain, so pace them instead
    // of burning quota on calls that fail and have to be retried next run.
    public const VERIFICATION_MIN_INTERVAL_SECONDS = 1.1;
    public const SEND_CATCH_ALL = false;

    // Non-functional: politeness + daily cost caps.
    public const REQUEST_CONNECT_TIMEOUT = 10;
    public const REQUEST_TOTAL_TIMEOUT = 20;
    public const REQUEST_MAX_RESPONSE_BYTES = 2 * 1024 * 1024;
    public const REQUEST_RETRIES = 2;
    public const PER_HOST_MIN_INTERVAL_SECONDS = 2;
    public const DAILY_SEARCH_CAP = 100;
    public const DAILY_VERIFICATION_CAP = 100;

    // Idempotency: re-running `enrich` skips leads enriched within this
    // many days unless --force is passed.
    public const REFRESH_AFTER_DAYS = 30;

    public const USER_AGENT = 'LeadgenEnrichmentBot/1.0';
}
