<?php

/**
 * Provider-agnostic contract for Module B3 — lets a different verifier
 * (ZeroBounce, NeverBounce, etc.) drop in later without touching
 * PatternGuesser or Enricher.
 */
interface EmailVerifier {
    /**
     * @return array{status: string, raw_response: string} status is one of:
     *   valid, invalid, catch_all, unknown, disposable
     */
    public function verify(string $email): array;
}
