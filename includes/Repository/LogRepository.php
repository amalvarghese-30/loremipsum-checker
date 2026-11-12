<?php
/**
 * Log Repository
 *
 * Persists structured log messages to the database with optional metadata.
 */

namespace LoremChecker\Repository;

use LoremChecker\QueryHelper;
use PDO;

class LogRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function log(
        int $scanId,
        int $projectId,
        string $level,
        string $message,
        string $eventType = 'general',
        ?string $url = null,
        ?array $metadata = null
    ): void {
        $stmt = QueryHelper::prepare($this->db, '
            INSERT INTO scan_logs (
                scan_id, project_id, event_type, log_level, message,
                url, metadata_json, created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, NOW(3)
            )
        ');

        $metaJson = $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null;
        $stmt->execute([
            $scanId,
            $projectId,
            $eventType,
            strtoupper($level),
            $message,
            $url,
            $metaJson
        ]);
    }

    public function getLogs(int $scanId, int $limit = 50, int $offset = 0, ?string $level = null): array
    {
        $sql = 'SELECT * FROM scan_logs WHERE scan_id = ?';
        $params = [$scanId];

        if ($level !== null) {
            $sql .= ' AND log_level = ?';
            $params[] = strtoupper($level);
        }

        $sql .= ' ORDER BY id DESC LIMIT ? OFFSET ?';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return array_reverse($rows); // Return in chronological order
    }
}
