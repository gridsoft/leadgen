<?php

/**
 * Writes one row per enrichment decision, so any lead's final status is
 * traceable back to why (spec requirement: "every decision is written to
 * enrichment_log with a reason").
 */
class EnrichmentLog {
    // Match db/schema.sql's column widths. Truncating defensively here
    // matters because callers sometimes pass a raw exception message or a
    // full URL as the reason — MySQL strict mode aborts the whole INSERT
    // on overflow rather than silently truncating, so an oversized value
    // would otherwise crash the enrichment run it was trying to record.
    private const STEP_MAX = 50;
    private const RESULT_MAX = 50;
    private const REASON_MAX = 100;

    public static function record(PDO $pdo, int $prospectId, string $step, string $result, ?string $reason = null): void {
        $pdo->prepare(
            'INSERT INTO enrichment_log (prospect_id, step, result, reason) VALUES (:prospect_id, :step, :result, :reason)'
        )->execute([
            'prospect_id' => $prospectId,
            'step' => substr($step, 0, self::STEP_MAX),
            'result' => substr($result, 0, self::RESULT_MAX),
            'reason' => $reason !== null ? substr($reason, 0, self::REASON_MAX) : null,
        ]);
    }
}
