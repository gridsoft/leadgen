<?php
require_once __DIR__ . '/EmailVerifier.php';
require_once __DIR__ . '/DomainTool.php';
require_once __DIR__ . '/MxLookup.php';
require_once __DIR__ . '/VerificationCache.php';
require_once __DIR__ . '/JobQueue.php';
require_once __DIR__ . '/EnrichmentConfig.php';

/**
 * Module B2+B3: guesses info@/contact@/office@ for a domain that has no
 * published email, verifying each in order and stopping at the first
 * definitive answer.
 */
class PatternGuesser {
    /**
     * @return array{outcome: string, email: ?string, verification: ?string, confidence: ?string}
     *   outcome is one of: valid, catch_all, retry_pending, exhausted, no_mx, skipped_site_builder, no_domain
     */
    public static function guess(PDO $pdo, EmailVerifier $verifier, string $websiteUrl): array {
        $empty = ['email' => null, 'verification' => null, 'confidence' => null];

        if (DomainTool::isSiteBuilderSubdomain($websiteUrl)) {
            return ['outcome' => 'skipped_site_builder'] + $empty;
        }
        $domain = DomainTool::registrableDomain($websiteUrl);
        if ($domain === null) {
            // Not a site builder — just genuinely has no PSL-resolvable domain
            // (e.g. hosted on a bare IP). Different situation, different label.
            return ['outcome' => 'no_domain'] + $empty;
        }
        if (!MxLookup::hasMx($domain)) {
            return ['outcome' => 'no_mx'] + $empty;
        }

        foreach (EnrichmentConfig::EMAIL_PATTERNS as $localPart) {
            $candidate = "$localPart@$domain";
            $result = VerificationCache::verify($pdo, $verifier, $candidate);

            if ($result['status'] === 'valid') {
                // Role addresses (info@, contact@) are correct on purpose here — never reject for that.
                return ['outcome' => 'valid', 'email' => $candidate, 'verification' => 'valid', 'confidence' => 'medium'];
            }
            if ($result['status'] === 'catch_all') {
                // Every address at this domain "verifies" — stop spending credits, can't confirm anything.
                return ['outcome' => 'catch_all', 'email' => $candidate, 'verification' => 'catch_all', 'confidence' => 'low'];
            }
            if ($result['status'] === 'unknown') {
                JobQueue::enqueue($pdo, 'reverify_email', ['email' => $candidate], 24 * 3600);
                return ['outcome' => 'retry_pending'] + $empty;
            }
            // invalid or disposable -> try the next pattern
        }

        return ['outcome' => 'exhausted'] + $empty;
    }
}
