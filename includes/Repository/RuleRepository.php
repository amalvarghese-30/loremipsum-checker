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
