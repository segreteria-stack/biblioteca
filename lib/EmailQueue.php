<?php
declare(strict_types=1);

final class EmailQueue
{
    private \PDO $db;
    private string $queueTable;
    private string $logTable;

    public function __construct(\PDO $db, string $queueTable = 'email_queue', string $logTable = 'email_log')
    {
        $this->db = $db;
        $this->queueTable = $queueTable;
        $this->logTable = $logTable;
    }

    public function enqueue(
        string $to,
        string $subject,
        string $template,
        array $data,
        int $priority = 5,
        ?string $scheduledAt = null,
        int $maxAttempts = 5
    ): int {
        $to = trim($to);
        if ($to === '') return 0;

        $scheduledAt = $scheduledAt ?: date('Y-m-d H:i:s');

        $sql = "INSERT INTO {$this->queueTable}
                (to_email, subject, template, data_json, priority, status, attempts, max_attempts, scheduled_at, created_at)
                VALUES (?, ?, ?, ?, ?, 'queued', 0, ?, ?, NOW())";

        $st = $this->db->prepare($sql);
        $st->execute([
            $to,
            $subject,
            $template,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $priority,
            $maxAttempts,
            $scheduledAt,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Prende un batch di email pronte e le “blocca” (status=sending, locked_at=NOW()) in transazione.
     */
    public function claimBatch(int $limit = 20, int $lockTtlSeconds = 600): array
    {
        $limit = max(1, min(200, $limit));
        $now = date('Y-m-d H:i:s');

        // scarta lock vecchi oltre TTL
        $lockCutoff = date('Y-m-d H:i:s', time() - $lockTtlSeconds);

        $this->db->beginTransaction();
        try {
            $sql = "SELECT id, to_email, subject, template, data_json, attempts, max_attempts
                    FROM {$this->queueTable}
                    WHERE status='queued'
                      AND scheduled_at <= ?
                      AND (locked_at IS NULL OR locked_at < ?)
                    ORDER BY priority ASC, scheduled_at ASC, id ASC
                    LIMIT {$limit}
                    FOR UPDATE";

            $st = $this->db->prepare($sql);
            $st->execute([$now, $lockCutoff]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

            if (!$rows) {
                $this->db->commit();
                return [];
            }

            $ids = array_map(static fn($r) => (int)$r['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $upd = $this->db->prepare(
                "UPDATE {$this->queueTable}
                 SET status='sending', locked_at=NOW(), updated_at=NOW()
                 WHERE id IN ($placeholders)"
            );
            $upd->execute($ids);

            $this->db->commit();

            // decodifica data_json
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['attempts'] = (int)$r['attempts'];
                $r['max_attempts'] = (int)$r['max_attempts'];
                $r['data'] = $this->safeJsonDecode((string)($r['data_json'] ?? ''));
            }
            unset($r);

            return $rows;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function markSent(int $id): void
    {
        $st = $this->db->prepare(
            "UPDATE {$this->queueTable}
             SET status='sent', sent_at=NOW(), locked_at=NULL, updated_at=NOW()
             WHERE id=?"
        );
        $st->execute([$id]);
    }

    public function markFailed(int $id, int $attempts, int $maxAttempts, string $error): void
    {
        $attempts = $attempts + 1;
        $status = ($attempts >= $maxAttempts) ? 'dead' : 'failed';

        $st = $this->db->prepare(
            "UPDATE {$this->queueTable}
             SET status=?, attempts=?, last_error=?, locked_at=NULL, updated_at=NOW()
             WHERE id=?"
        );
        $st->execute([$status, $attempts, $error, $id]);
    }

    public function logResult(?int $queueId, string $to, string $subject, string $template, string $status, ?string $errorMsg): void
    {
        $st = $this->db->prepare(
            "INSERT INTO {$this->logTable}
             (queue_id, to_email, subject, template, status, error_msg, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        $st->execute([$queueId, $to, $subject, $template, $status, $errorMsg]);
    }

    private function safeJsonDecode(string $json): array
    {
        if ($json === '') return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
