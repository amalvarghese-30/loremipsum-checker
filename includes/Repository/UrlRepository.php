<?php
/**
 * URL Repository
 *
 * Handles discovered URLs, queue management, status transitions,
 * and discovery source lineage.
 */

namespace LoremChecker\Repository;

use LoremChecker\QueryHelper;
use PDO;

class UrlRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Batch insert discovered URLs.
     * Uses INSERT IGNORE based on (scan_id, url_hash) unique key.
     * Also records discovery sources.
     *
     * @return int Number of newly inserted URLs
     */
    public function insertBatch(int $scanId, array $records): int
    {
        if (empty($records)) {
            return 0;
        }

        $insertedCount = 0;
        $urlInsertStmt = QueryHelper::prepare($this->db, '
            INSERT IGNORE INTO discovered_urls (
                scan_id, original_url, normalized_url, url_hash, domain,
                discovery_source, crawl_depth, status, discovered_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, "pending", NOW())
        ');

        $sourceInsertStmt = QueryHelper::prepare($this->db, '
            INSERT INTO url_discovery_sources (
                discovered_url_id, scan_id, source_type, source_url, crawl_depth, discovered_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ');

        $findIdStmt = $this->db->prepare('SELECT id FROM discovered_urls WHERE scan_id = ? AND url_hash = ?');

        foreach ($records as $r) {
            $domain = parse_url($r['url'], PHP_URL_HOST) ?: '';
            $urlInsertStmt->execute([
                $scanId,
                $r['url'],
                $r['normalized_url'],
                $r['url_hash'],
                $domain,
                $r['source'] ?? 'unknown',
                (int)($r['depth'] ?? 0)
            ]);

            $rowCount = $urlInsertStmt->rowCount();
            $urlId = null;

            if ($rowCount > 0) {
                $urlId = (int)$this->db->lastInsertId();
                $insertedCount++;
            } else {
                // Find existing URL ID to log secondary discovery source
                $findIdStmt->execute([$scanId, $r['url_hash']]);
                $urlId = (int)$findIdStmt->fetchColumn();
            }

            if ($urlId) {
                $sourceUrl = $r['metadata']['sitemap_url'] ?? $r['metadata']['found_on'] ?? null;
                $sourceInsertStmt->execute([
                    $urlId,
                    $scanId,
                    $r['source'] ?? 'unknown',
                    $sourceUrl,
                    (int)($r['depth'] ?? 0)
                ]);

                // Record any extra sources tracked during merge
                if (!empty($r['extra_sources'])) {
                    foreach ($r['extra_sources'] as $extraSrc) {
                        $sourceInsertStmt->execute([
                            $urlId,
                            $scanId,
                            $extraSrc,
                            null,
                            (int)($r['depth'] ?? 0)
                        ]);
                    }
                }
            }
        }

        return $insertedCount;
    }

    /**
     * Insert a single discovered URL and record its primary source.
     *
     * @return int The discovered_urls.id
     */
    public function insertDiscovered(int $scanId, string $originalUrl, string $normalizedUrl, int $depth = 0, string $source = 'unknown', ?string $sourceUrl = null): int
    {
        $hash = hash('sha256', $normalizedUrl);
        $records = [
            [
                'url'            => $originalUrl,
                'normalized_url' => $normalizedUrl,
                'url_hash'       => $hash,
                'source'         => $source,
                'depth'          => $depth,
                'metadata'       => ['found_on' => $sourceUrl],
            ]
        ];

        $this->insertBatch($scanId, $records);

        $findStmt = $this->db->prepare('SELECT id FROM discovered_urls WHERE scan_id = ? AND url_hash = ? ORDER BY id DESC LIMIT 1');
        $findStmt->execute([$scanId, $hash]);
        return (int)$findStmt->fetchColumn();
    }

    /**
     * Fetch a batch of pending/retry URLs for processing.
     * Selects only records whose next_attempt_at is NULL or in the past.
     */
    public function fetchPendingBatch(int $scanId, int $limit = 50): array
    {
        $nowExpr = QueryHelper::isSqlite($this->db) ? "datetime('now')" : "NOW()";
        $stmt = $this->db->prepare("
            SELECT id, original_url, normalized_url, domain, crawl_depth, retry_count, attempt_count
            FROM discovered_urls
            WHERE scan_id = ?
              AND (
                status IN ('pending', 'queued')
                OR (status = 'retry_wait' AND (next_attempt_at IS NULL OR next_attempt_at <= {$nowExpr}))
              )
            ORDER BY crawl_depth ASC, id ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $scanId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function markStatus(int $urlId, string $status, ?string $finalUrl = null, ?string $lastError = null): bool
    {
        $stmt = QueryHelper::prepare($this->db, '
            UPDATE discovered_urls
            SET status = ?,
                final_url = COALESCE(?, final_url),
                last_error = ?,
                processed_at = CASE
                    WHEN ? IN (\'completed\', \'failed\') THEN NOW()
                    ELSE processed_at
                END
            WHERE id = ?
        ');
        return $stmt->execute([$status, $finalUrl, $lastError, $status, $urlId]);
    }

    public function scheduleRetry(int $urlId, string $lastError, int $delaySeconds = 5): bool
    {
        if (QueryHelper::isSqlite($this->db)) {
            $stmt = $this->db->prepare('
                UPDATE discovered_urls
                SET retry_count = retry_count + 1,
                    attempt_count = attempt_count + 1,
                    status = "retry_wait",
                    last_error = ?,
                    next_attempt_at = datetime("now", "+" || ? || " seconds")
                WHERE id = ?
            ');
        } else {
            $stmt = $this->db->prepare('
                UPDATE discovered_urls
                SET retry_count = retry_count + 1,
                    attempt_count = attempt_count + 1,
                    status = "retry_wait",
                    last_error = ?,
                    next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                WHERE id = ?
            ');
        }
        return $stmt->execute([$lastError, $delaySeconds, $urlId]);
    }

    public function incrementRetry(int $urlId, string $lastError): bool
    {
        return $this->scheduleRetry($urlId, $lastError, 5);
    }

    public function resetProcessingToPending(int $scanId): int
    {
        $stmt = $this->db->prepare('
            UPDATE discovered_urls
            SET status = "pending"
            WHERE scan_id = ? AND status = "processing"
        ');
        $stmt->execute([$scanId]);
        return $stmt->rowCount();
    }

    public function getCountsByStatus(int $scanId): array
    {
        $stmt = $this->db->prepare('
            SELECT status, COUNT(*) as cnt
            FROM discovered_urls
            WHERE scan_id = ?
            GROUP BY status
        ');
        $stmt->execute([$scanId]);
        $rows = $stmt->fetchAll();
        $counts = [];
        foreach ($rows as $r) {
            $counts[$r['status']] = (int)$r['cnt'];
        }
        return $counts;
    }

    public function getPaginated(int $scanId, int $page = 1, int $pageSize = 50, ?string $status = null): array
    {
        $offset = ($page - 1) * $pageSize;
        $sql = 'SELECT * FROM discovered_urls WHERE scan_id = ?';
        $params = [$scanId];

        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY id ASC LIMIT ? OFFSET ?';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val);
        }
        $stmt->bindValue(count($params) + 1, $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
