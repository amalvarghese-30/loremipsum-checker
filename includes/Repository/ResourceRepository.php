<?php
/**
 * Resource Repository
 *
 * Tracks external JavaScript, CSS, JSON, and XML resources.
 * Ensures identical resources are not repeatedly fetched within the same scan.
 */

namespace LoremChecker\Repository;

use PDO;

class ResourceRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Find existing resource or register a new one for this scan.
     *
     * @return array [id => int, is_new => bool]
     */
    public function findOrCreate(
        int $scanId,
        ?int $pageResultId,
        string $resourceUrl,
        string $normalizedUrl,
        string $resourceType
    ): array {
        $hash = hash('sha256', $normalizedUrl);

        $checkStmt = $this->db->prepare('SELECT id, processing_status FROM scan_resources WHERE scan_id = ? AND resource_hash = ?');
        $checkStmt->execute([$scanId, $hash]);
        $row = $checkStmt->fetch();

        if ($row) {
            return ['id' => (int)$row['id'], 'is_new' => false, 'status' => $row['processing_status']];
        }

        $insertStmt = $this->db->prepare('
            INSERT INTO scan_resources (
                scan_id, page_result_id, resource_url, normalized_resource_url,
                resource_hash, resource_type, processing_status
            ) VALUES (?, ?, ?, ?, ?, ?, "pending")
        ');

        $insertStmt->execute([
            $scanId,
            $pageResultId,
            $resourceUrl,
            $normalizedUrl,
            $hash,
            $resourceType
        ]);

        return ['id' => (int)$this->db->lastInsertId(), 'is_new' => true, 'status' => 'pending'];
    }

    public function markCompleted(int $resourceId, array $data): bool
    {
        $stmt = $this->db->prepare('
            UPDATE scan_resources
            SET content_type = ?,
                http_status = ?,
                processing_status = "completed",
                response_size = ?,
                duration_ms = ?,
                finding_count = ?,
                fetched_at = NOW()
            WHERE id = ?
        ');

        return $stmt->execute([
            $data['content_type'] ?? null,
            $data['http_status'] ?? null,
            $data['response_size'] ?? null,
            $data['duration_ms'] ?? null,
            (int)($data['finding_count'] ?? 0),
            $resourceId
        ]);
    }

    public function markFailed(int $resourceId, string $error): bool
    {
        $stmt = $this->db->prepare('
            UPDATE scan_resources
            SET processing_status = "failed",
                error_message = ?,
                fetched_at = NOW()
            WHERE id = ?
        ');
        return $stmt->execute([$error, $resourceId]);
    }

    public function listForScan(int $scanId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM scan_resources WHERE scan_id = ? ORDER BY id ASC');
        $stmt->execute([$scanId]);
        return $stmt->fetchAll();
    }
}
