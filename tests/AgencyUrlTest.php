<?php
require_once __DIR__ . '/../includes/AgencyUrl.php';

return [
    'strips utm_* and click-tracking parameters, keeps real ones' => function () {
        assert_same(
            'https://agency.com/work?page=2',
            AgencyUrl::normalize('https://agency.com/work/?utm_source=clutch&utm_medium=referral&page=2&gclid=abc123&fbclid=x')
        );
        assert_same('https://agency.com', AgencyUrl::normalize('https://agency.com/?utm_campaign=spring'));
    },
    'forces https, adds a missing scheme, lowercases the host' => function () {
        assert_same('https://agency.com', AgencyUrl::normalize('http://agency.com'));
        assert_same('https://www.agency.co.uk', AgencyUrl::normalize('www.Agency.CO.uk'));
        assert_same('https://agency.com/Team', AgencyUrl::normalize('  HTTP://AGENCY.com/Team  '), 'path case is kept');
    },
    'removes trailing slashes and the fragment' => function () {
        assert_same('https://agency.com', AgencyUrl::normalize('https://agency.com/'));
        assert_same('https://agency.com/about', AgencyUrl::normalize('https://agency.com/about///#team'));
    },
    'rejects things that are not website addresses' => function () {
        foreach (['', 'not a url', 'localhost', 'ftp://agency.com', 'mailto:hi@agency.com', 'https://', '192.168.0.1'] as $bad) {
            assert_same(null, AgencyUrl::normalize($bad), "should reject: $bad");
        }
    },
    'duplicate detection: variants of one site share a domain key' => function () {
        $variants = [
            'https://www.agency.com/?utm_source=x',
            'http://agency.com/',
            'agency.com',
            'https://WWW.AGENCY.COM',
        ];
        $domains = array_unique(array_map(fn($u) => AgencyUrl::domain(AgencyUrl::normalize($u)), $variants));
        assert_same(['agency.com'], array_values($domains));
    },
    'duplicate detection: different sites stay different' => function () {
        assert_true(AgencyUrl::domain(AgencyUrl::normalize('agency.com')) !== AgencyUrl::domain(AgencyUrl::normalize('agency.co')));
        assert_same('blog.agency.com', AgencyUrl::domain(AgencyUrl::normalize('https://blog.agency.com')), 'only www. is folded');
    },
];
