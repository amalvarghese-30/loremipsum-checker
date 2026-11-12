<?php
/**
 * Detection Rule Repository
 *
 * Manages placeholder and Lorem Ipsum detection rules.
 */

namespace LoremChecker\Repository;

use LoremChecker\QueryHelper;
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
        $rules = $stmt ? $stmt->fetchAll() : [];
        if (!empty($rules)) {
            return $rules;
        }

        // Auto-seed built-in rules if detection_rules table has zero rows
        $this->seedBuiltinRules();
        $retryStmt = $this->db->query('
            SELECT * FROM detection_rules
            WHERE enabled = 1
            ORDER BY is_builtin DESC, id ASC
        ');
        return $retryStmt ? $retryStmt->fetchAll() : [];
    }

    public function seedBuiltinRules(): void
    {
        try {
            $countStmt = $this->db->query('SELECT COUNT(*) FROM detection_rules');
            if ($countStmt && (int)$countStmt->fetchColumn() > 0) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        $defaults = [
            ['Lorem Ipsum Classic',       '/\blorem\s+ipsum\b/i',                 'lorem_ipsum', 'high',   'high'],
            ['Dolor Sit Amet',            '/\bdolor\s+sit\s+amet\b/i',           'lorem_ipsum', 'high',   'high'],
            ['Consectetur Adipiscing',    '/\bconsectetur\s+adipiscing\b/i',      'lorem_ipsum', 'high',   'high'],
            ['Sed Do Eiusmod',            '/\bsed\s+do\s+eiusmod\b/i',            'lorem_ipsum', 'high',   'high'],
            ['Ut Labore Et Dolore',       '/\but\s+labore\s+et\s+dolore\b/i',     'lorem_ipsum', 'high',   'high'],
            ['Magna Aliqua',              '/\bmagna\s+aliqua\b/i',                'lorem_ipsum', 'high',   'high'],
            ['Duis Aute Irure',           '/\bduis\s+aute\s+irure\b/i',           'lorem_ipsum', 'high',   'high'],
            ['Excepteur Sint',            '/\bexcepteur\s+sint\b/i',              'lorem_ipsum', 'high',   'high'],
            ['Nulla Pariatur',            '/\bnulla\s+pariatur\b/i',              'lorem_ipsum', 'high',   'high'],
            ['Quis Nostrud Exercitation', '/\bquis\s+nostrud\s+exercitation\b/i', 'lorem_ipsum', 'high',   'high'],
            ['Your Content Goes Here',    '/\byour\s+content\s+goes\s+here\b/i',  'placeholder', 'high',   'high'],
            ['Insert Text Here',          '/\binsert\s+text\s+here\b/i',         'placeholder', 'high',   'high'],
            ['Sample Text',               '/\bsample\s+text\b/i',                 'placeholder', 'medium', 'medium'],
            ['Placeholder Text',          '/\bplaceholder\s+text\b/i',            'placeholder', 'medium', 'medium'],
            ['Developer TODO Marker',     '/\b(?:TODO|FIXME|XXX)\b/',             'developer_mock', 'medium', 'high'],
        ];

        try {
            $ins = QueryHelper::prepare($this->db, '
                INSERT INTO detection_rules (rule_name, pattern, detection_category, enabled, case_sensitive, severity, confidence, source_types, is_builtin, created_at, updated_at)
                VALUES (?, ?, ?, 1, 0, ?, ?, "html,javascript,css,json,text", 1, NOW(), NOW())
            ');

            foreach ($defaults as $r) {
                $ins->execute([$r[0], $r[1], $r[2], $r[3], $r[4]]);
            }
        } catch (\Throwable $te) {}
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
        $stmt = QueryHelper::prepare($this->db, '
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
        $stmt = QueryHelper::prepare($this->db, 'UPDATE detection_rules SET enabled = ?, updated_at = NOW() WHERE id = ?');
        return $stmt->execute([$enabled ? 1 : 0, $id]);
    }

    public function deleteRule(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM detection_rules WHERE id = ? AND is_builtin = 0');
        return $stmt->execute([$id]);
    }

    public function addRule(array $rule): int
    {
        $stmt = QueryHelper::prepare($this->db, '
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
