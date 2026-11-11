<?php
/**
 * Detection Rule Repository
 *
 * Manages placeholder and Lorem Ipsum detection rules.
 */

namespace LoremChecker\Repository;

use PDO;

class RuleRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getActiveRules(): array
    {
        $stmt = $this->db->query('
            SELECT * FROM detection_rules
            WHERE enabled = 1
            ORDER BY is_builtin DESC, id ASC
        ');
        return $stmt->fetchAll();
    }

    public function listAll(): array
    {
        $stmt = $this->db->query('
            SELECT * FROM detection_rules
            ORDER BY is_builtin DESC, id ASC
        ');
        return $stmt->fetchAll();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM detection_rules WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateRule(int $id, array $rule): bool
    {
        $stmt = $this->db->prepare('
            UPDATE detection_rules
            SET rule_name = ?,
                pattern = ?,
                detection_category = ?,
                enabled = ?,
                case_sensitive = ?,
                severity = ?,
                confidence = ?,
                source_types = ?,
                updated_at = NOW()
            WHERE id = ? AND is_builtin = 0
        ');

        return $stmt->execute([
            $rule['rule_name'],
            $rule['pattern'],
            $rule['detection_category'] ?? 'placeholder',
            !empty($rule['enabled']) ? 1 : 0,
            !empty($rule['case_sensitive']) ? 1 : 0,
            $rule['severity'] ?? 'high',
            $rule['confidence'] ?? 'high',
            $rule['source_types'] ?? 'html,javascript,css,json,text',
            $id
        ]);
    }

    public function toggleRule(int $id, bool $enabled): bool
    {
        $stmt = $this->db->prepare('UPDATE detection_rules SET enabled = ?, updated_at = NOW() WHERE id = ?');
        return $stmt->execute([$enabled ? 1 : 0, $id]);
    }

    public function deleteRule(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM detection_rules WHERE id = ? AND is_builtin = 0');
        return $stmt->execute([$id]);
    }

    public function addRule(array $rule): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO detection_rules (
                rule_name, pattern, detection_category, enabled,
                case_sensitive, severity, confidence, source_types,
                is_builtin, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, NOW(), NOW()
            )
        ');

        $stmt->execute([
            $rule['rule_name'],
            $rule['pattern'],
            $rule['detection_category'] ?? 'placeholder',
            !empty($rule['enabled']) ? 1 : 0,
            !empty($rule['case_sensitive']) ? 1 : 0,
            $rule['severity'] ?? 'high',
            $rule['confidence'] ?? 'high',
            $rule['source_types'] ?? 'html,javascript,css,json,text',
            !empty($rule['is_builtin']) ? 1 : 0
        ]);

        return (int)$this->db->lastInsertId();
    }
}
