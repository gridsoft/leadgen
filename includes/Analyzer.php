<?php
require_once __DIR__ . '/PageSpeedClient.php';
require_once __DIR__ . '/SiteScanner.php';
require_once __DIR__ . '/Scorer.php';
require_once __DIR__ . '/ContactStatus.php';

/**
 * Runs the full per-prospect analysis (site scan + PageSpeed + opportunity
 * scoring) and writes the results, shared by the single-prospect web page
 * (analyze.php) and the bulk CLI tool (batch_analyze.php) so both use
 * identical logic instead of two copies drifting apart.
 */
class Analyzer {
    /**
     * @param array<string, mixed> $prospect A full row from the prospects table.
     * @return array{ok: bool, opportunity_level: ?string, contact_gap_level: ?string, warnings: string[]}
     */
    public static function run(PDO $pdo, array $prospect): array {
        if (empty($prospect['website'])) {
            return ['ok' => false, 'opportunity_level' => null, 'contact_gap_level' => null, 'warnings' => ['No website on file — nothing to analyze.']];
        }

        $id = (int) $prospect['id'];
        $warnings = [];

        // Site scan doesn't need an API key, always attempt it.
        $scan = (new SiteScanner())->scan($prospect['website']);
        if (!$scan['fetch_ok']) {
            $warnings[] = "Couldn't fetch the site directly (it may block bots or require JS) — WordPress/theme/booking signals are unavailable.";
        }

        // Performance score needs the Google API key.
        $mobilePerf = null;
        $desktopPerf = null;
        try {
            $client = new PageSpeedClient();
            $mobilePerf = $client->analyze($prospect['website'], 'mobile');
            $desktopPerf = $client->analyze($prospect['website'], 'desktop');
        } catch (Throwable $e) {
            $warnings[] = 'PageSpeed data unavailable: ' . $e->getMessage();
        }

        $analysis = [
            'fetch_ok' => $scan['fetch_ok'],
            'is_wordpress' => $scan['is_wordpress'],
            'theme_name' => $scan['theme_name'],
            'facebook_url' => $scan['facebook_url'],
            'has_booking_signal' => $scan['has_booking_signal'],
            'mobile_performance_score' => $mobilePerf['performance_score'] ?? null,
            'desktop_performance_score' => $desktopPerf['performance_score'] ?? null,
            'total_byte_weight' => $mobilePerf['total_byte_weight'] ?? null,
            'image_byte_weight' => $mobilePerf['image_byte_weight'] ?? null,
            'mobile_lcp_ms' => $mobilePerf['largest_contentful_paint_ms'] ?? null,
            'desktop_lcp_ms' => $desktopPerf['largest_contentful_paint_ms'] ?? null,
            'desktop_total_byte_weight' => $desktopPerf['total_byte_weight'] ?? null,
            'desktop_image_byte_weight' => $desktopPerf['image_byte_weight'] ?? null,
            'emails_found' => $scan['emails_found'] ?? [],
            'phone_found' => $scan['phone_found'] ?? null,
            'has_contact_form' => $scan['has_contact_form'] ?? null,
            'has_chat_widget' => $scan['has_chat_widget'] ?? null,
        ];

        $scorer = new Scorer();
        $scoring = $scorer->score($analysis, $prospect);
        $contactGap = $scorer->scoreContactGap($analysis);

        // PDO's default emulated prepares cast PHP false to '' (not '0') when
        // building the query, which MySQL rejects for TINYINT columns. Send ints.
        $toTinyint = function ($value) {
            return $value === null ? null : (int) $value;
        };

        $insert = $pdo->prepare(
            'INSERT INTO analyses (
                prospect_id, fetch_ok, is_wordpress, theme_name, mobile_performance_score,
                desktop_performance_score, total_byte_weight, image_byte_weight, mobile_lcp_ms, desktop_lcp_ms,
                desktop_total_byte_weight, desktop_image_byte_weight, has_booking_signal,
                facebook_url, emails_found, phone_found, has_contact_form, has_chat_widget,
                opportunity_level, opportunity_points, opportunity_reasons,
                contact_gap_level, contact_gap_points, contact_gap_reasons, error_message
            ) VALUES (
                :prospect_id, :fetch_ok, :is_wordpress, :theme_name, :mobile_performance_score,
                :desktop_performance_score, :total_byte_weight, :image_byte_weight, :mobile_lcp_ms, :desktop_lcp_ms,
                :desktop_total_byte_weight, :desktop_image_byte_weight, :has_booking_signal,
                :facebook_url, :emails_found, :phone_found, :has_contact_form, :has_chat_widget,
                :opportunity_level, :opportunity_points, :opportunity_reasons,
                :contact_gap_level, :contact_gap_points, :contact_gap_reasons, :error_message
            )'
        );
        $insert->execute([
            'prospect_id' => $id,
            'fetch_ok' => $toTinyint($analysis['fetch_ok']),
            'is_wordpress' => $toTinyint($analysis['is_wordpress']),
            'theme_name' => $analysis['theme_name'],
            'mobile_performance_score' => $analysis['mobile_performance_score'],
            'desktop_performance_score' => $analysis['desktop_performance_score'],
            'total_byte_weight' => $analysis['total_byte_weight'],
            'image_byte_weight' => $analysis['image_byte_weight'],
            'mobile_lcp_ms' => $analysis['mobile_lcp_ms'],
            'desktop_lcp_ms' => $analysis['desktop_lcp_ms'],
            'desktop_total_byte_weight' => $analysis['desktop_total_byte_weight'],
            'desktop_image_byte_weight' => $analysis['desktop_image_byte_weight'],
            'has_booking_signal' => $toTinyint($analysis['has_booking_signal']),
            'facebook_url' => $analysis['facebook_url'],
            'emails_found' => implode(', ', $analysis['emails_found']) ?: null,
            'phone_found' => $analysis['phone_found'],
            'has_contact_form' => $toTinyint($analysis['has_contact_form']),
            'has_chat_widget' => $toTinyint($analysis['has_chat_widget']),
            'opportunity_level' => $scoring['level'],
            'opportunity_points' => $scoring['points'],
            'opportunity_reasons' => implode("\n", $scoring['reasons']),
            'contact_gap_level' => $contactGap['level'],
            'contact_gap_points' => $contactGap['points'],
            'contact_gap_reasons' => implode("\n", $contactGap['reasons']),
            'error_message' => $warnings ? implode(' ', $warnings) : null,
        ]);

        // Backfill prospect-level shortcuts so the dashboard doesn't need to join analyses.
        if ($analysis['facebook_url'] && empty($prospect['facebook_url'])) {
            $pdo->prepare('UPDATE prospects SET facebook_url = :fb WHERE id = :id')
                ->execute(['fb' => $analysis['facebook_url'], 'id' => $id]);
        }
        if (!empty($analysis['emails_found'])) {
            $pdo->prepare('UPDATE prospects SET contact_email = :email WHERE id = :id')
                ->execute(['email' => implode(', ', $analysis['emails_found']), 'id' => $id]);
        }
        if (!empty($analysis['phone_found']) && empty($prospect['phone'])) {
            $pdo->prepare('UPDATE prospects SET phone = :phone WHERE id = :id')
                ->execute(['phone' => $analysis['phone_found'], 'id' => $id]);
        }

        // The scan may have found an email/phone the search source didn't have — recompute status.
        $newEmail = !empty($analysis['emails_found']) ? implode(', ', $analysis['emails_found']) : $prospect['contact_email'];
        $newPhone = $prospect['phone'] ?: $analysis['phone_found'];
        $newStatus = ContactStatus::compute($prospect['website'], $newPhone, $newEmail);
        $pdo->prepare('UPDATE prospects SET contact_status = :status WHERE id = :id')
            ->execute(['status' => $newStatus, 'id' => $id]);

        return [
            'ok' => true,
            'opportunity_level' => $scoring['level'],
            'contact_gap_level' => $contactGap['level'],
            'warnings' => $warnings,
        ];
    }
}
