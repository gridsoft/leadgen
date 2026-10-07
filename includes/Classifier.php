<?php

/**
 * Module C: turns a lead's current known state into a final outreach_status
 * + status_reason. Pure decision table — it doesn't do any discovery or
 * verification itself, just decides given what's already known. This
 * separation is deliberate: Classifier is fully testable with synthetic
 * inputs even before Module A/B exist to produce real ones (e.g. the CASL
 * rule can be proven correct today with a hand-built "valid email, country
 * CA" input, with no live verifier required).
 */
class Classifier {
    public const STATUS_EMAIL_READY = 'email_ready';
    public const STATUS_PHONE_ONLY = 'phone_only';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_EXCLUDED = 'excluded';

    /**
     * @param array{
     *   phone_national10: ?string,
     *   email: ?string,
     *   email_verification: ?string,
     *   country: ?string,
     *   is_suppressed: bool,
     *   is_closed: bool,
     *   pending_reason: ?string,
     *   terminal_reason: ?string,
     *   casl_enabled: bool
     * } $input
     * @return array{status: string, reason: ?string}
     */
    public static function classify(array $input): array {
        $input += [
            'is_closed' => false,
            'pending_reason' => null,
            'terminal_reason' => null,
            'casl_enabled' => true,
        ];

        if ($input['is_suppressed']) {
            return ['status' => self::STATUS_EXCLUDED, 'reason' => 'suppressed'];
        }
        if ($input['is_closed']) {
            return ['status' => self::STATUS_EXCLUDED, 'reason' => 'business_closed'];
        }

        $hasValidPhone = !empty($input['phone_national10']);
        $hasValidEmail = !empty($input['email']) && $input['email_verification'] === 'valid';
        $isCatchAll = $input['email_verification'] === 'catch_all';

        if ($hasValidEmail) {
            if ($input['casl_enabled'] && $input['country'] === 'CA') {
                return ['status' => self::STATUS_NEEDS_REVIEW, 'reason' => 'casl_check'];
            }
            return ['status' => self::STATUS_EMAIL_READY, 'reason' => null];
        }

        if (!$hasValidPhone && empty($input['email'])) {
            return ['status' => self::STATUS_EXCLUDED, 'reason' => 'no_valid_phone'];
        }

        if ($isCatchAll) {
            return ['status' => self::STATUS_NEEDS_REVIEW, 'reason' => 'catch_all'];
        }

        // Still waiting on a step that hasn't run yet (discovery, pattern
        // verification, a retry) — not a final answer either way.
        if ($input['pending_reason'] !== null) {
            return ['status' => self::STATUS_NEEDS_REVIEW, 'reason' => $input['pending_reason']];
        }

        if ($hasValidPhone) {
            return ['status' => self::STATUS_PHONE_ONLY, 'reason' => $input['terminal_reason']];
        }

        return ['status' => self::STATUS_EXCLUDED, 'reason' => 'no_valid_phone'];
    }
}
