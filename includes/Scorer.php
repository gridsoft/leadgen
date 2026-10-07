<?php

/**
 * Turns raw analysis signals into an opportunity level. This is a simple
 * point-based heuristic, not a validated model — tune the thresholds as
 * you see how real outreach performs.
 */
class Scorer {
    public function score(array $analysis, array $prospect): array {
        $points = 0;
        $reasons = [];

        $mobileScore = $analysis['mobile_performance_score'];
        if ($mobileScore !== null) {
            if ($mobileScore < 50) {
                $points += 3;
                $reasons[] = "Mobile performance is poor ({$mobileScore}/100)";
            } elseif ($mobileScore < 70) {
                $points += 1;
                $reasons[] = "Mobile performance is mediocre ({$mobileScore}/100)";
            } else {
                $reasons[] = "Mobile performance is already decent ({$mobileScore}/100)";
            }
        }

        if ($analysis['is_wordpress']) {
            $points += 1;
            $label = $analysis['theme_name']
                ? "running the \"{$analysis['theme_name']}\" WordPress theme"
                : 'on WordPress';
            $reasons[] = "Site is $label";
        }

        if ($analysis['has_booking_signal'] === false) {
            $points += 2;
            $reasons[] = 'No online booking detected';
        } elseif ($analysis['has_booking_signal'] === true) {
            $reasons[] = 'Online booking already present';
        }

        if ($analysis['image_byte_weight'] !== null && $analysis['image_byte_weight'] > 1_500_000) {
            $points += 1;
            $mb = round($analysis['image_byte_weight'] / 1_000_000, 1);
            $reasons[] = "Images alone are {$mb}MB, likely unoptimized";
        }

        $reviewCount = (int) ($prospect['review_count'] ?? 0);
        $established = $reviewCount >= 50;
        if ($established) {
            $reasons[] = "Established business ({$reviewCount} reviews) — credible target";
        } else {
            $reasons[] = "Limited review history ({$reviewCount} reviews) — weaker proof of demand";
        }

        $emailsFound = $analysis['emails_found'] ?? [];
        $hasContactForm = $analysis['has_contact_form'] ?? null;
        if (!empty($emailsFound)) {
            $reasons[] = 'Contact email found: ' . implode(', ', $emailsFound);
        } elseif ($hasContactForm === true) {
            $reasons[] = 'No email address published, but the site has a contact form — reachable that way';
        } else {
            $reasons[] = 'No email address and no contact form found on site — outreach will need another channel';
        }

        if ($points >= 5 && $established) {
            $level = 'HIGH';
        } elseif ($points >= 3) {
            $level = 'MEDIUM';
        } else {
            $level = 'LOW';
        }

        if (!$established && $level === 'HIGH') {
            $level = 'MEDIUM';
        }

        return [
            'level' => $level,
            'points' => $points,
            'reasons' => $reasons,
        ];
    }

    /**
     * Separate axis from score(): that method asks "would a redesign/
     * performance pitch land" (poor Lighthouse score, dated WordPress theme,
     * no booking widget, heavy images) — a well-built, fast site with a
     * contact form and a chat widget can still score HIGH there, because
     * none of those signals measure reachability. This asks the other
     * question instead: "can this business's own customers actually reach
     * them" — no published email, no contact form, no chat widget is the
     * strongest version of that pitch; having two or three of those already
     * means there's little to offer on this axis specifically, regardless
     * of how the site performs technically.
     */
    public function scoreContactGap(array $analysis): array {
        // The site couldn't be fetched at all (bot-blocked, JS-only, timed
        // out) — email/form/chat-widget detection never ran, so there's no
        // signal here, not a confirmed absence. Scoring this HIGH would
        // read as "definitely hard to reach" when it actually means
        // "unknown," and unknown must never look like the strongest case.
        if ($analysis['fetch_ok'] === false) {
            return ['level' => null, 'points' => null, 'reasons' => ["Site couldn't be fetched — contact channels unknown"]];
        }

        $hasEmail = !empty($analysis['emails_found']);
        $hasForm = $analysis['has_contact_form'] === true;
        $hasChat = $analysis['has_chat_widget'] === true;

        $channels = ($hasEmail ? 1 : 0) + ($hasForm ? 1 : 0) + ($hasChat ? 1 : 0);

        $reasons = [];
        $reasons[] = $hasEmail ? 'Published email found' : 'No published email found';
        $reasons[] = $hasForm ? 'Contact form present' : 'No contact form detected';
        $reasons[] = $hasChat ? 'Chat widget present' : 'No chat widget detected';

        if ($channels === 0) {
            $level = 'HIGH';
        } elseif ($channels === 1) {
            $level = 'MEDIUM';
        } else {
            $level = 'LOW';
        }

        return [
            'level' => $level,
            'points' => $channels,
            'reasons' => $reasons,
        ];
    }
}
