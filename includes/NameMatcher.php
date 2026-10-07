<?php

/**
 * Module A4's "strong name match" check: lowercase, strip punctuation,
 * drop legal-entity/filler words, collapse whitespace, then compare as
 * token sets (Jaccard similarity) rather than raw strings — "ABC Dental
 * Clinic" and "ABC Dental" should read as a strong match even though
 * they're not character-identical.
 */
class NameMatcher {
    private const STOPWORDS = ['llc', 'inc', 'co', 'corp', 'ltd', 'the', 'and'];

    public static function normalize(string $name): string {
        $name = strtolower($name);
        $name = str_replace('&', ' and ', $name);
        $name = preg_replace('/[^a-z0-9\s]/', ' ', $name); // strip punctuation
        $tokens = preg_split('/\s+/', trim($name));
        $tokens = array_filter($tokens, fn($t) => $t !== '' && !in_array($t, self::STOPWORDS, true));
        return implode(' ', $tokens);
    }

    /**
     * Jaccard similarity (intersection over union) of the two names' token sets, 0.0-1.0.
     */
    public static function similarity(string $a, string $b): float {
        $tokensA = array_unique(explode(' ', self::normalize($a)));
        $tokensB = array_unique(explode(' ', self::normalize($b)));
        $tokensA = array_filter($tokensA, fn($t) => $t !== '');
        $tokensB = array_filter($tokensB, fn($t) => $t !== '');

        if (!$tokensA || !$tokensB) {
            return 0.0;
        }

        $intersection = count(array_intersect($tokensA, $tokensB));
        $union = count(array_unique(array_merge($tokensA, $tokensB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }
}
