<?php
/**
 * Rules Controller
 *
 * Provides REST endpoints for custom placeholder detection rules:
 * - list: returns all detection rules (builtin + custom)
 * - get: returns single rule
 * - create: adds a new custom detection rule
 * - update: updates custom detection rule
 * - toggle: enables or disables a rule
 * - delete: deletes custom detection rule
 */

namespace LoremChecker\Api;

use LoremChecker\Repository\RuleRepository;
use PDO;

class RulesController
{
    private PDO            $db;
    private RuleRepository $ruleRepo;

    public function __construct(PDO $db)
    {
        $this->db       = $db;
        $this->ruleRepo = new RuleRepository($db);
    }

    public function list(): void
    {
        $rules = $this->ruleRepo->listAll();
        Response::success(['rules' => $rules]);
    }

    public function get(): void
    {
        $id = RequestValidator::validateId($_GET['id'] ?? null, 'rule_id');
        $rule = $this->ruleRepo->getById($id);

        if (!$rule) {
            Response::error('Rule not found.', 'NOT_FOUND', 404);
        }

        Response::success(['rule' => $rule]);
    }

    public function create(): void
    {
        $name = trim($_POST['rule_name'] ?? '');
        $pattern = trim($_POST['pattern'] ?? '');
        $category = trim($_POST['detection_category'] ?? 'placeholder');
        $severity = trim($_POST['severity'] ?? 'high');
        $confidence = trim($_POST['confidence'] ?? 'high');
        $sources = trim($_POST['source_types'] ?? 'html,javascript,css,json,text');
        $caseSensitive = !empty($_POST['case_sensitive']);

        if ($name === '') {
            Response::error('Rule name is required.');
        }
        if ($pattern === '') {
            Response::error('Pattern is required.');
        }

        // Validate regular expression safely
        if (@preg_match($pattern, '') === false) {
            // Auto-format as delimiter regex if raw string was provided
            $escaped = preg_quote($pattern, '/');
            $pattern = '/' . $escaped . '/' . ($caseSensitive ? '' : 'i');
        }

        $ruleId = $this->ruleRepo->addRule([
            'rule_name'          => $name,
            'pattern'            => $pattern,
            'detection_category' => $category,
            'enabled'            => 1,
            'case_sensitive'     => $caseSensitive ? 1 : 0,
            'severity'           => in_array($severity, ['critical', 'high', 'medium', 'low'], true) ? $severity : 'high',
            'confidence'         => in_array($confidence, ['high', 'medium', 'low'], true) ? $confidence : 'high',
            'source_types'       => $sources,
            'is_builtin'         => 0,
        ]);

        Response::success(['rule_id' => $ruleId], 'Rule created successfully', 201);
    }

    public function update(): void
    {
        $id = RequestValidator::validateId($_POST['id'] ?? null, 'rule_id');
        $rule = $this->ruleRepo->getById($id);

        if (!$rule) {
            Response::error('Rule not found.', 'NOT_FOUND', 404);
        }
        if (!empty($rule['is_builtin'])) {
            Response::error('Built-in rules cannot be modified.', 'FORBIDDEN', 403);
        }

        $name = trim($_POST['rule_name'] ?? $rule['rule_name']);
        $pattern = trim($_POST['pattern'] ?? $rule['pattern']);

        if (@preg_match($pattern, '') === false) {
            Response::error('Invalid regular expression pattern.');
        }

        $this->ruleRepo->updateRule($id, [
            'rule_name'          => $name,
            'pattern'            => $pattern,
            'detection_category' => trim($_POST['detection_category'] ?? $rule['detection_category']),
            'enabled'            => isset($_POST['enabled']) ? (!empty($_POST['enabled']) ? 1 : 0) : $rule['enabled'],
            'case_sensitive'     => isset($_POST['case_sensitive']) ? (!empty($_POST['case_sensitive']) ? 1 : 0) : $rule['case_sensitive'],
            'severity'           => $_POST['severity'] ?? $rule['severity'],
            'confidence'         => $_POST['confidence'] ?? $rule['confidence'],
            'source_types'       => $_POST['source_types'] ?? $rule['source_types'],
        ]);

        Response::success(['message' => 'Rule updated successfully']);
    }

    public function toggle(): void
    {
        $id = RequestValidator::validateId($_POST['id'] ?? null, 'rule_id');
        $enabled = !empty($_POST['enabled']);

        $this->ruleRepo->toggleRule($id, $enabled);
        Response::success(['message' => 'Rule status updated']);
    }

    public function delete(): void
    {
        $id = RequestValidator::validateId($_POST['id'] ?? null, 'rule_id');
        $rule = $this->ruleRepo->getById($id);

        if (!$rule) {
            Response::error('Rule not found.', 'NOT_FOUND', 404);
        }
        if (!empty($rule['is_builtin'])) {
            Response::error('Built-in rules cannot be deleted.', 'FORBIDDEN', 403);
        }

        $this->ruleRepo->deleteRule($id);
        Response::success(['message' => 'Rule deleted successfully']);
    }
}
