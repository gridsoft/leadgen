<?php
require_once __DIR__ . '/PhoneMatcher.php';
require_once __DIR__ . '/SiteScanner.php';
require_once __DIR__ . '/Classifier.php';
require_once __DIR__ . '/EnrichmentLog.php';
require_once __DIR__ . '/VerificationCache.php';
require_once __DIR__ . '/PatternGuesser.php';
require_once __DIR__ . '/AbstractApiVerifier.php';
require_once __DIR__ . '/WebsiteDiscovery.php';
require_once __DIR__ . '/GoogleProgrammableSearch.php';
require_once __DIR__ . '/DomainTool.php';
require_once __DIR__ . '/EnrichmentConfig.php';
require_once __DIR__ . '/../config.php';

/**
 * Orchestrates one lead through the spec's "Overall flow per lead":
 * dataset email -> known/discovered website's Step 2 scan -> Module B
 * pattern guessing -> Module C classification, each step falling through
 * to the next on failure rather than dead-ending. Every external
 * capability (site scan doesn't need a key; search needs
 * has_google_search_key(); verification needs has_abstractapi_key())
 * degrades gracefully to a `needs_review`/pending state when its key
 * isn't configured — same pattern as every other integration in this
 * project. No lead reaches `email_ready` without a real `valid`
 * verification result.
 */
class Enricher {
    /**
     * @param array<string, mixed> $prospect A full row from the prospects table.
     * @return array{status: string, reason: ?string}
     */
    public static function run(PDO $pdo, array $prospect): array {
        $id = (int) $prospect['id'];

        $phoneE164 = PhoneMatcher::normalize($prospect['phone']);
        $phoneNat10 = PhoneMatcher::nationalTenDigit($prospect['phone']);
        EnrichmentLog::record(
            $pdo, $id, 'phone_normalize',
            $phoneNat10 !== null ? 'valid' : 'invalid',
            $phoneNat10 !== null ? null : 'no_valid_phone'
        );
        $prospect['phone_national10'] = $phoneNat10; // WebsiteDiscovery needs this on the array it's given

        $isSuppressed = self::isSuppressed($pdo, $prospect);
        $verifier = has_abstractapi_key() ? new AbstractApiVerifier($pdo) : null;

        $email = null;
        $emailSource = null;
        $emailVerification = null;
        $emailConfidence = null;
        $pendingReason = null;
        $terminalReason = null;
        $discoveredWebsite = null;
        $discoverySource = null;
        $discoveryEvidenceUrl = null;

        // Step 1: an email already on file (dataset, manual entry, or a prior scan).
        if (!empty($prospect['contact_email'])) {
            $candidate = trim(explode(',', $prospect['contact_email'])[0]);
            EnrichmentLog::record($pdo, $id, 'dataset_email_check', 'found');

            if ($verifier === null) {
                $pendingReason = 'verification_pending';
            } else {
                $outcome = self::verifyAndLog($pdo, $verifier, $id, $candidate, 'dataset');
                if ($outcome['resolved']) {
                    [$email, $emailSource, $emailVerification, $emailConfidence] =
                        [$candidate, 'dataset', $outcome['status'], $outcome['confidence']];
                } elseif ($outcome['status'] === 'unknown') {
                    $pendingReason = 'verification_retry_pending';
                }
                // invalid/disposable: dataset email didn't pan out — fall through below.
            }
        }

        // Step 2: a website — known already, or found now by Module A.
        if ($email === null && $pendingReason === null) {
            $website = $prospect['website'];

            if (empty($website)) {
                if (has_google_search_key()) {
                    try {
                        $search = new GoogleProgrammableSearch($pdo);
                        $discovery = WebsiteDiscovery::discover($pdo, $search, $prospect);
                    } catch (Throwable $e) {
                        // A transient search failure shouldn't hard-fail this lead —
                        // same defensive pattern as verifyAndLog(). Stays retriable.
                        $discovery = ['outcome' => 'error', 'website' => $e->getMessage(), 'evidence_url' => null, 'discovery_source' => null];
                    }
                    EnrichmentLog::record(
                        $pdo, $id, 'website_discovery', $discovery['outcome'],
                        $discovery['website'] ?? $discovery['evidence_url']
                    );

                    if ($discovery['outcome'] === 'error') {
                        $pendingReason = 'no_site_found';
                    } elseif ($discovery['outcome'] === 'accepted') {
                        $chainReason = self::checkChainGuard($pdo, $id, $discovery['website']);
                        if ($chainReason !== null) {
                            $pendingReason = $chainReason; // 'shared_domain' — flagged for review, not trusted
                        } else {
                            $website = $discovery['website'];
                            $discoveredWebsite = $website;
                            $discoverySource = $discovery['discovery_source'];
                            $discoveryEvidenceUrl = $discovery['evidence_url'];
                        }
                    } elseif ($discovery['outcome'] === 'name_match_only') {
                        $pendingReason = 'name_match_only';
                        $discoveryEvidenceUrl = $discovery['evidence_url'];
                    } elseif ($discovery['outcome'] === 'nothing_found') {
                        $terminalReason = 'no_site_found';
                    }
                    // 'no_valid_phone': leave both reasons null — Classifier's own
                    // no-phone-and-no-email rule already excludes this lead.
                } else {
                    $pendingReason = 'no_site_found'; // no search key configured yet
                    EnrichmentLog::record($pdo, $id, 'website_check', 'not_found', 'no_site_found');
                }
            }

            if (!empty($website) && $email === null && $pendingReason === null) {
                $result = self::scanKnownWebsite($pdo, $id, $website, $verifier);
                $email = $result['email'];
                $emailSource = $result['email_source'];
                $emailVerification = $result['email_verification'];
                $emailConfidence = $result['email_confidence'];
                $pendingReason = $result['pending_reason'];
                $terminalReason = $result['terminal_reason'];
            }
        }

        $classification = Classifier::classify([
            'phone_national10' => $phoneNat10,
            'email' => $email,
            'email_verification' => $emailVerification,
            'country' => $prospect['country'],
            'is_suppressed' => $isSuppressed,
            'pending_reason' => $pendingReason,
            'terminal_reason' => $terminalReason,
        ]);

        $update = $pdo->prepare(
            'UPDATE prospects SET phone_e164 = :phone_e164, phone_national10 = :phone_national10,
                outreach_status = :outreach_status, status_reason = :status_reason,
                email_source = :email_source, email_verification = :email_verification,
                email_confidence = :email_confidence, enriched_at = NOW()
                ' . ($discoveredWebsite !== null ? ', website = :website, website_domain = :website_domain,
                website_discovered = 1, discovery_source = :discovery_source,
                discovery_evidence_url = :discovery_evidence_url' : '') . '
             WHERE id = :id'
        );
        $params = [
            'phone_e164' => $phoneE164,
            'phone_national10' => $phoneNat10,
            'outreach_status' => $classification['status'],
            'status_reason' => $classification['reason'],
            'email_source' => $emailSource,
            'email_verification' => $emailVerification,
            'email_confidence' => $emailConfidence,
            'id' => $id,
        ];
        if ($discoveredWebsite !== null) {
            $params['website'] = $discoveredWebsite;
            $params['website_domain'] = DomainTool::groupingKey($discoveredWebsite);
            $params['discovery_source'] = $discoverySource;
            $params['discovery_evidence_url'] = $discoveryEvidenceUrl;
        }
        $update->execute($params);

        if ($email && $email !== $prospect['contact_email']) {
            $pdo->prepare('UPDATE prospects SET contact_email = :email WHERE id = :id')
                ->execute(['email' => $email, 'id' => $id]);
        }

        EnrichmentLog::record($pdo, $id, 'classify', $classification['status'], $classification['reason']);

        return $classification;
    }

    /**
     * Step 2 extractor, then Module B pattern guessing if that finds nothing —
     * shared by a pre-existing website and one Module A just discovered.
     *
     * @return array{email: ?string, email_source: ?string, email_verification: ?string,
     *   email_confidence: ?string, pending_reason: ?string, terminal_reason: ?string}
     */
    private static function scanKnownWebsite(PDO $pdo, int $id, string $website, ?EmailVerifier $verifier): array {
        $out = ['email' => null, 'email_source' => null, 'email_verification' => null,
            'email_confidence' => null, 'pending_reason' => null, 'terminal_reason' => null];

        $scan = (new SiteScanner())->scan($website);

        if (!empty($scan['emails_found'])) {
            $candidate = $scan['emails_found'][0];
            EnrichmentLog::record($pdo, $id, 'site_email_extract', 'found');

            if ($verifier === null) {
                $out['pending_reason'] = 'verification_pending';
                return $out;
            }
            $outcome = self::verifyAndLog($pdo, $verifier, $id, $candidate, 'website');
            if ($outcome['resolved']) {
                $out['email'] = $candidate;
                $out['email_source'] = 'website';
                $out['email_verification'] = $outcome['status'];
                $out['email_confidence'] = $outcome['confidence'];
            } elseif ($outcome['status'] === 'unknown') {
                $out['pending_reason'] = 'verification_retry_pending';
            } else {
                $out['terminal_reason'] = 'all_patterns_invalid';
            }
            return $out;
        }

        EnrichmentLog::record($pdo, $id, 'site_email_extract', 'not_found');

        if ($verifier === null) {
            $out['pending_reason'] = 'pattern_pending';
            return $out;
        }

        $guess = PatternGuesser::guess($pdo, $verifier, $website);
        EnrichmentLog::record($pdo, $id, 'pattern_guess', $guess['outcome'], $guess['email'] ? "tried {$guess['email']}" : null);

        if (in_array($guess['outcome'], ['valid', 'catch_all'], true)) {
            $out['email'] = $guess['email'];
            $out['email_source'] = 'pattern';
            $out['email_verification'] = $guess['verification'];
            $out['email_confidence'] = $guess['confidence'];
        } elseif ($guess['outcome'] === 'retry_pending') {
            $out['pending_reason'] = 'verification_retry_pending';
        } elseif ($guess['outcome'] === 'no_mx') {
            $out['terminal_reason'] = 'no_mx';
        } elseif ($guess['outcome'] === 'skipped_site_builder') {
            $out['terminal_reason'] = 'site_builder_domain';
        } elseif ($guess['outcome'] === 'no_domain') {
            $out['terminal_reason'] = 'no_domain';
        } else {
            $out['terminal_reason'] = 'all_patterns_invalid';
        }
        return $out;
    }

    /**
     * Module A5: if this discovered domain is already trusted for more
     * than SHARED_DOMAIN_THRESHOLD other leads, it's probably a franchise
     * HQ or an aggregator missing from the blocklist — flag all of them
     * (including this one) for review instead of trusting the acceptance.
     *
     * @return ?string 'shared_domain' if the guard fired, else null
     */
    private static function checkChainGuard(PDO $pdo, int $currentId, string $website): ?string {
        $key = DomainTool::groupingKey($website);
        if ($key === null) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT id FROM prospects WHERE website_discovered = 1 AND website_domain = :key AND id != :id"
        );
        $stmt->execute(['key' => $key, 'id' => $currentId]);
        $otherIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($otherIds) < EnrichmentConfig::SHARED_DOMAIN_THRESHOLD) {
            return null;
        }

        // Re-flag the existing leads too — their earlier acceptance is now suspect.
        foreach ($otherIds as $otherId) {
            $pdo->prepare(
                "UPDATE prospects SET outreach_status = 'needs_review', status_reason = 'shared_domain' WHERE id = :id"
            )->execute(['id' => $otherId]);
            EnrichmentLog::record($pdo, (int) $otherId, 'chain_guard', 'shared_domain', $website);
        }

        return 'shared_domain';
    }

    /**
     * @return array{resolved: bool, status: string, confidence: ?string}
     */
    private static function verifyAndLog(PDO $pdo, EmailVerifier $verifier, int $prospectId, string $email, string $step): array {
        try {
            $result = VerificationCache::verify($pdo, $verifier, $email);
        } catch (Throwable $e) {
            EnrichmentLog::record($pdo, $prospectId, "{$step}_email_verify", 'error', $e->getMessage());
            return ['resolved' => false, 'status' => 'unknown', 'confidence' => null];
        }

        EnrichmentLog::record($pdo, $prospectId, "{$step}_email_verify", $result['status']);

        $confidence = $result['status'] === 'valid' ? 'high' : ($result['status'] === 'catch_all' ? 'low' : null);
        $resolved = in_array($result['status'], ['valid', 'catch_all'], true);

        return ['resolved' => $resolved, 'status' => $result['status'], 'confidence' => $confidence];
    }

    private static function isSuppressed(PDO $pdo, array $prospect): bool {
        if (!empty($prospect['contact_email'])) {
            $stmt = $pdo->prepare("SELECT 1 FROM suppression_list WHERE type = 'email' AND value = :v LIMIT 1");
            $stmt->execute(['v' => $prospect['contact_email']]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }
        if (!empty($prospect['website_domain'])) {
            $stmt = $pdo->prepare("SELECT 1 FROM suppression_list WHERE type = 'domain' AND value = :v LIMIT 1");
            $stmt->execute(['v' => $prospect['website_domain']]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }
        return false;
    }
}
