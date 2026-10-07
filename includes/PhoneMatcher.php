<?php
require_once __DIR__ . '/../vendor/autoload.php';

use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\NumberParseException;

/**
 * Phone normalization and matching for Module A4 (does a discovered site's
 * phone number match the lead's phone number?) and Module A1 (is the lead's
 * own phone valid at all). NANP-focused (US/CA leads only, per the spec),
 * but not restricted to it — a default region is just a parsing hint for
 * numbers written without a country code.
 */
class PhoneMatcher {
    private static ?PhoneNumberUtil $util = null;

    private static function util(): PhoneNumberUtil {
        if (self::$util === null) {
            self::$util = PhoneNumberUtil::getInstance();
        }
        return self::$util;
    }

    /**
     * E.164 form (+1XXXXXXXXXX), or null if the number can't be parsed as valid.
     */
    public static function normalize(?string $raw, string $defaultRegion = 'US'): ?string {
        if (!$raw) {
            return null;
        }
        try {
            $parsed = self::util()->parse($raw, $defaultRegion);
        } catch (NumberParseException $e) {
            return null;
        }
        if (!self::util()->isValidNumber($parsed)) {
            return null;
        }
        return self::util()->format($parsed, PhoneNumberFormat::E164);
    }

    /**
     * The matching key used throughout Module A: the bare national
     * significant number (10 digits for NANP), ignoring formatting,
     * country-code prefix, and extensions.
     */
    public static function nationalTenDigit(?string $raw, string $defaultRegion = 'US'): ?string {
        if (!$raw) {
            return null;
        }
        try {
            $parsed = self::util()->parse($raw, $defaultRegion);
        } catch (NumberParseException $e) {
            return null;
        }
        if (!self::util()->isValidNumber($parsed)) {
            return null;
        }
        return (string) $parsed->getNationalNumber();
    }

    public static function matches(?string $a, ?string $b, string $defaultRegion = 'US'): bool {
        $na = self::nationalTenDigit($a, $defaultRegion);
        $nb = self::nationalTenDigit($b, $defaultRegion);
        return $na !== null && $nb !== null && $na === $nb;
    }
}
