<?php
require_once __DIR__ . '/../includes/AgencyScraper.php';

/** A fetcher that serves fixtures by URL and records what was requested. */
function fake_fetcher(array $pages, array &$requested): callable {
    return function (string $url) use ($pages, &$requested) {
        $requested[] = $url;
        if (!isset($pages[$url])) {
            return ['ok' => false, 'status' => 404, 'html' => '', 'final_url' => $url, 'error' => 'HTTP 404'];
        }
        return ['ok' => true, 'status' => 200, 'html' => $pages[$url], 'final_url' => $url, 'error' => null];
    };
}

const SITE = 'https://www.brightlinedigital.com';

return [
    'a site whose HTTPS fails is read over plain HTTP, with a warning' => function () {
        $requested = [];
        $plain = fake_fetcher(['http://centrodev.com' => fixture('wp_home.html')], $requested);
        $fetch = function (string $url) use ($plain) {
            if (strpos($url, 'https://') === 0) {
                return ['ok' => false, 'status' => 0, 'html' => '', 'final_url' => $url, 'error' => 'TLS connect error: error:14094410:SSL routines:ssl3_read_bytes:sslv3 alert handshake failure'];
            }
            return $plain($url);
        };
        $r = (new AgencyScraper($fetch, function () {}))->scrape('https://centrodev.com');
        assert_true($r['ok'], 'read over HTTP');
        assert_same('http://centrodev.com', $r['pages']['home']['url']);
        assert_contains(AgencyScraper::NO_HTTPS_WARNING, implode(' ', $r['warnings']));
    },
    'other homepage failures are not retried over HTTP' => function () {
        $requested = [];
        $fetch = function (string $url) use (&$requested) {
            $requested[] = $url;
            return ['ok' => false, 'status' => 0, 'html' => '', 'final_url' => $url, 'error' => 'Could not resolve host: gone.invalid'];
        };
        $r = (new AgencyScraper($fetch, function () {}))->scrape('https://gone.invalid');
        assert_same(false, $r['ok']);
        assert_same(['https://gone.invalid'], $requested, 'one request only');
    },
    'extracts emails from mailto links and text, dropping junk' => function () {
        $emails = AgencyScraper::extractEmails(fixture('wp_home.html'));
        assert_same(['hello@brightlinedigital.com'], $emails, 'mailto (with ?subject) and JSON-escaped address collapse to one');
        foreach (['john.doe@gmail.com', 'user@emaildomain.com', 'sentry', 'wixpress', 'wordpress.org', 'example.com', '.png'] as $junk) {
            assert_not_contains($junk, implode(' ', $emails), "junk leaked: $junk");
        }
    },
    'decodes HTML-entity-obfuscated addresses' => function () {
        assert_same(['maria@brightlinedigital.com', 'sam@brightlinedigital.com'], AgencyScraper::extractEmails(fixture('wp_about.html')));
    },
    'junk filter: image names and known placeholders' => function () {
        foreach (['logo@2x.png', 'icon@3x.webp', 'john.doe@gmail.com', 'user@emaildomain.com', 'a1b2@o1.ingest.sentry.io',
                  'x@sentry.wixpress.com', 'me@example.com', 'you@yourdomain.com', 'wp@wordpress.org'] as $junk) {
            assert_true(AgencyScraper::isJunkEmail($junk), "should be junk: $junk");
        }
        assert_true(!AgencyScraper::isJunkEmail('hello@brightlinedigital.com'), 'a real address is kept');
    },
    'extracts and dedupes phones from tel: links' => function () {
        assert_same(['+1 (512) 555-0100'], AgencyScraper::extractPhones(fixture('wp_home.html')));
        assert_same(['+15125550100', '+1-512-555-0199'], AgencyScraper::extractPhones(fixture('wp_contact.html')));
    },
    'detects platforms' => function () {
        assert_same('WordPress 6.9', AgencyScraper::detectPlatform(fixture('wp_home.html')), 'generator tag with version');
        assert_same('WordPress', AgencyScraper::detectPlatform(fixture('wp_about.html')), 'wp-content without a generator tag');
        assert_same('Wix', AgencyScraper::detectPlatform('<img src="https://static.wixstatic.com/media/a.jpg">'));
        assert_same('Squarespace', AgencyScraper::detectPlatform('<script src="https://static1.squarespace.com/x.js"></script>'));
        assert_same('Webflow', AgencyScraper::detectPlatform('<html data-wf-site="123">'));
        assert_same('Shopify', AgencyScraper::detectPlatform('<link href="https://cdn.shopify.com/s/x.css">'));
        assert_same('unknown', AgencyScraper::detectPlatform('<html><body>plain</body></html>'));
    },
    'html-to-text removes scripts, styles, noscript, svg and comments' => function () {
        $text = AgencyScraper::htmlToText(fixture('wp_home.html'));
        foreach (['script-text-should-not-appear', 'hidden-style-text', 'comment-text-should-not-appear',
                  'svg-text-should-not-appear', 'noscript-text-should-not-appear', 'dataLayer'] as $hidden) {
            assert_not_contains($hidden, $text);
        }
        assert_contains('WordPress websites that grow small businesses', $text);
        assert_contains('About Services', $text, 'adjacent nav items are separated by spaces');
        assert_true(!preg_match('/\s{2,}/', $text), 'whitespace is collapsed');
    },
    'finds one link per page type, preferring shallow nav links' => function () {
        $links = AgencyScraper::findPageLinks(fixture('wp_home.html'), SITE);
        assert_same([
            'about' => SITE . '/about-us/',
            'contact' => SITE . '/contact/',
            'careers' => SITE . '/careers',
            'services' => SITE . '/services/',
        ], $links);
    },
    'scrape(): WordPress site end to end with a fake fetcher' => function () {
        $requested = [];
        $sleeps = [];
        $scraper = new AgencyScraper(fake_fetcher([
            SITE => fixture('wp_home.html'),
            SITE . '/about-us/' => fixture('wp_about.html'),
            SITE . '/contact/' => fixture('wp_contact.html'),
            // careers and services 404
        ], $requested), function (int $ms) use (&$sleeps) { $sleeps[] = $ms; });

        $r = $scraper->scrape(SITE);
        assert_true($r['ok']);
        assert_same(['home', 'about', 'contact'], array_keys($r['pages']), 'about and contact found; missing pages skipped');
        assert_same('WordPress 6.9', $r['platform']);
        assert_same(['hello@brightlinedigital.com', 'maria@brightlinedigital.com', 'sam@brightlinedigital.com'], $r['emails']);
        assert_same(['+1 (512) 555-0100', '+1-512-555-0199'], $r['phones'], 'same number in two formats counts once');
        assert_same(5, count($requested), 'home + 4 page types, never more than 6');
        assert_same([1000, 1000, 1000, 1000], $sleeps, 'waits ~1 s between requests to the site');
        assert_same(2, count($r['warnings']), 'one warning per page that failed to load');
        assert_contains('careers', $r['warnings'][0]);
    },
    'scrape(): homepage failure stops before any other request' => function () {
        $requested = [];
        $r = (new AgencyScraper(fake_fetcher([], $requested), function () {}))->scrape('https://down.example-agency.net');
        assert_same(false, $r['ok']);
        assert_contains('HTTP 404', $r['error']);
        assert_same(1, count($requested));
    },
    'scrape(): very little text adds the JavaScript warning' => function () {
        $requested = [];
        $html = '<html><body><div id="root"></div><script>render()</script></body></html>';
        $r = (new AgencyScraper(fake_fetcher(['https://spa-agency.com' => $html], $requested), function () {}))->scrape('https://spa-agency.com');
        assert_true($r['ok']);
        assert_same([AgencyScraper::LOW_TEXT_WARNING], $r['warnings']);
    },
    'page text is capped at about 6,000 characters' => function () {
        $requested = [];
        $html = '<html><body><p>' . str_repeat('word ', 3000) . '</p></body></html>';
        $r = (new AgencyScraper(fake_fetcher(['https://long-agency.com' => $html], $requested), function () {}))->scrape('https://long-agency.com');
        assert_true(mb_strlen($r['pages']['home']['text']) <= AgencyScraper::TEXT_LIMIT + 2);
    },
];
