<?php

/**
 * Minimal DB-backed job queue — this project has no external queue system,
 * per the spec's own fallback instruction. Built as the mechanism only in
 * this phase; nothing enqueues real work here yet. Claiming is not
 * fully race-safe under multiple concurrent workers (a local dev tool
 * running one worker at a time doesn't need row-level locking for that);
 * revisit with SELECT ... FOR UPDATE if this ever runs with real
 * concurrency.
 */
class JobQueue {
    private const MAX_ATTEMPTS = 3;
    private const BACKOFF_SECONDS = [60, 300, 1800]; // 1m, 5m, 30m

    public static function enqueue(PDO $pdo, string $type, array $payload, ?int $delaySeconds = null): int {
        // run_after must be computed on MySQL's own clock, not PHP's — this
        // server runs PHP in UTC and MySQL several hours ahead, and
        // claimNext() compares run_after against MySQL's NOW(). Computing
        // the delay in PHP and comparing against MySQL's clock silently
        // made every delayed job claimable immediately.
        $delaySeconds = $delaySeconds ?? 0;
        $pdo->prepare(
            'INSERT INTO jobs (type, payload, run_after) VALUES (:type, :payload, DATE_ADD(NOW(), INTERVAL :delay SECOND))'
        )->execute([
            'type' => $type,
            'payload' => json_encode($payload),
            'delay' => $delaySeconds,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Claims and returns the oldest due pending job (of the given types, or
     * any type), marking it 'running'. Returns null if none is due.
     *
     * @param string[] $types
     * @return array{id: int, type: string, payload: array, attempts: int}|null
     */
    public static function claimNext(PDO $pdo, array $types = []): ?array {
        $sql = "SELECT * FROM jobs WHERE status = 'pending' AND run_after <= NOW()";
        $params = [];
        if ($types) {
            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $sql .= " AND type IN ($placeholders)";
            $params = $types;
        }
        $sql .= ' ORDER BY run_after ASC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $job = $stmt->fetch();
        if (!$job) {
            return null;
        }

        $updated = $pdo->prepare("UPDATE jobs SET status = 'running' WHERE id = :id AND status = 'pending'");
        $updated->execute(['id' => $job['id']]);
        if ($updated->rowCount() === 0) {
            return null; // another worker claimed it between our SELECT and UPDATE
        }

        return [
            'id' => (int) $job['id'],
            'type' => $job['type'],
            'payload' => json_decode($job['payload'], true) ?? [],
            'attempts' => (int) $job['attempts'],
        ];
    }

    public static function markDone(PDO $pdo, int $jobId): void {
        $pdo->prepare("UPDATE jobs SET status = 'done' WHERE id = :id")->execute(['id' => $jobId]);
    }

    public static function markFailed(PDO $pdo, int $jobId, int $attempts): void {
        $nextAttempt = $attempts + 1;
        if ($nextAttempt >= self::MAX_ATTEMPTS) {
            $pdo->prepare("UPDATE jobs SET status = 'failed', attempts = :attempts WHERE id = :id")
                ->execute(['attempts' => $nextAttempt, 'id' => $jobId]);
            return;
        }

        $backoffSteps = self::BACKOFF_SECONDS;
        $delay = $backoffSteps[$attempts] ?? end($backoffSteps);
        $pdo->prepare(
            "UPDATE jobs SET status = 'pending', attempts = :attempts, run_after = DATE_ADD(NOW(), INTERVAL :delay SECOND) WHERE id = :id"
        )->execute(['attempts' => $nextAttempt, 'delay' => $delay, 'id' => $jobId]);
    }
}
