<?php

/**
 * Module B1: does this domain even accept mail? Skips pattern-guessing
 * entirely (and the API credits that would cost) when there's no MX
 * record to deliver to.
 */
class MxLookup {
    public static function hasMx(string $domain): bool {
        return checkdnsrr($domain, 'MX');
    }
}
