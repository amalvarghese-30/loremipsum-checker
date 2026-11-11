<?php
/**
 * Project Repository
 *
 * Handles database operations for website projects.
 * A project represents a website domain / audit target (not just a single scan).
 */

namespace LoremChecker\Repository;

use PDO;

class ProjectRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new project or return existing project ID for the normalized website URL.
     */
    public function findOrCreate(string $projectName, string $websiteUrl, string $normalizedUrl): int
    {
        $stmt = $this->db->prepare('SELECT id, project_name FROM projects WHERE normalized_website_url = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$normalizedUrl]);
        $row = $stmt->fetch();

        if ($row) {
            // Update name if changed
            if (!empty($projectName) && $projectName !== $row['project_name']) {
                $upd = $this->db->prepare('UPDATE projects SET project_name = ?, updated_at = NOW() WHERE id = ?');
                $upd->execute([$projectName, $row['id']]);
            }
            return (int)$row['id'];
        }

        $stmt = $this->db->prepare('
            INSERT INTO projects (project_name, website_url, normalized_website_url, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
        ');
        $stmt->execute([$projectName, $websiteUrl, $normalizedUrl]);
        return (int)$this->db->lastInsertId();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM projects WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listProjects(bool $includeDeleted = false): array
    {
        $sql = '
            SELECT p.*,
                   s.id as latest_scan_id,
                   s.status as latest_scan_status,
                   s.discovered_count,
                   s.processed_count,
                   s.clean_count,
                   s.affected_page_count,
                   s.failed_count,
                   s.total_findings,
                   s.created_at as latest_scan_at
            FROM projects p
            LEFT JOIN (
                SELECT s1.*
                FROM scans s1
                INNER JOIN (
                    SELECT project_id, MAX(id) as max_id
                    FROM scans
                    GROUP BY project_id
                ) s2 ON s1.id = s2.max_id
            ) s ON p.id = s.project_id
        ';

        if (!$includeDeleted) {
            $sql .= ' WHERE p.deleted_at IS NULL';
        }

        $sql .= ' ORDER BY p.updated_at DESC';

        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    public function softDelete(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE projects SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?');
        return $stmt->execute([$id]);
    }
}
