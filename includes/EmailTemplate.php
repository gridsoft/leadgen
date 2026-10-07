<?php

/**
 * Builds an outreach email from real findings — no LLM call, just
 * conditional text referencing the specific numbers this prospect's
 * analysis produced. Always read the output before sending; it's a
 * strong first draft, not a finished email.
 */
class EmailTemplate {
    public function generate(array $prospect, array $analysis): array {
        $name = $prospect['business_name'];

        $painPoints = [];
        if ($analysis['mobile_performance_score'] !== null && $analysis['mobile_performance_score'] < 70) {
            $painPoints[] = "your site scores {$analysis['mobile_performance_score']}/100 on Google's mobile speed test";
        }
        if ($analysis['has_booking_signal'] === false) {
            $painPoints[] = "there's no way to book an appointment online — every lead has to call during business hours";
        }
        if ($analysis['image_byte_weight'] !== null && $analysis['image_byte_weight'] > 1_500_000) {
            $mb = round($analysis['image_byte_weight'] / 1_000_000, 1);
            $painPoints[] = "{$mb}MB of images are likely slowing down the homepage";
        }
        if (empty($painPoints) && $analysis['is_wordpress']) {
            $painPoints[] = "the WordPress theme looks dated compared to newer competitors";
        }
        if (empty($painPoints)) {
            $painPoints[] = 'a few things on the site that could be tightened up';
        }

        $painText = $this->joinNatural($painPoints);

        $credibilityLine = '';
        $reviewCount = (int) ($prospect['review_count'] ?? 0);
        if ($reviewCount >= 50) {
            $credibilityLine = "With {$reviewCount} reviews, {$name} clearly has real demand. ";
        }

        $cityPart = $prospect['city'] ? " in {$prospect['city']}" : '';

        $subject = "Quick note on {$name}'s website";

        $body = "Hi,\n\n"
            . "I came across {$name} while looking at businesses{$cityPart}. "
            . "{$credibilityLine}I checked your site and noticed {$painText}.\n\n"
            . "That combination usually means you're losing customers who'd convert if the site were "
            . "faster and easier to book with — especially on mobile, where most local searches happen.\n\n"
            . "I help businesses like yours fix exactly this: faster load times, a modern design, and "
            . "online booking that captures leads even after hours. Happy to send over a short breakdown "
            . "of what I found on your site specifically, no obligation.\n\n"
            . "Worth a quick look?\n\n"
            . "Best,\n[Your name]";

        return ['subject' => $subject, 'body' => $body];
    }

    private function joinNatural(array $items): string {
        if (count($items) === 1) {
            return $items[0];
        }
        $last = array_pop($items);
        return implode(', ', $items) . ', and ' . $last;
    }
}
