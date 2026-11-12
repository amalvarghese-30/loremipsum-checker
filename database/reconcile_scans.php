<?php
/**
 * Scan Counters Reconciliation CLI Script
 *
 * Recalculates aggregate scan counters directly from the authoritative
 * page_results and findings tables, repairing any historical drift or misattributions.
 *
 * Usage:
 *   php database/reconcile_scans.php --scan_id=8
 *   php database/reconcile_scans.php --project_id=1
 *   php database/reconcile_scans.php --all
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/database.php';
require_once $projectRoot . '/includes/QueryHelper.php';
require_once $projectRoot . '/includes/Repository/ScanRepository.php';

use LoremChecker\Database;
use LoremChecker\Repository\ScanRepository;

try {
    $pdo = Database::getInstance();
    $scanRepo = new ScanRepository($pdo);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: Unable to connect to database: " . $e->getMessage() . "\n");
    exit(1);
}

$args = array_slice($argv, 1);
$scanId    = null;
$projectId = null;
$all       = false;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--scan_id=')) {
        $scanId = (int)substr($arg, 10);
    } elseif (str_starts_with($arg, '--project_id=')) {
        $projectId = (int)substr($arg, 13);
    } elseif ($arg === '--all') {
        $all = true;
    }
}

if (!$scanId && !$projectId && !$all) {
    echo "Usage:\n";
    echo "  php database/reconcile_scans.php --scan_id=<ID>\n";
    echo "  php database/reconcile_scans.php --project_id=<ID>\n";
    echo "  php database/reconcile_scans.php --all\n";
    exit(1);
}

$scansToReconcile = [];

if ($scanId) {
    $scansToReconcile[] = $scanId;
} elseif ($projectId) {
    $stmt = $pdo->prepare('SELECT id FROM scans WHERE project_id = ? ORDER BY id ASC');
    $stmt->execute([$projectId]);
    $scansToReconcile = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $stmt = $pdo->query('SELECT id FROM scans ORDER BY id ASC');
    $scansToReconcile = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

if (empty($scansToReconcile)) {
    echo "No matching scans found for reconciliation.\n";
    exit(0);
}

echo "Starting reconciliation for " . count($scansToReconcile) . " scan(s)...\n\n";

$reconciledCount = 0;

foreach ($scansToReconcile as $sId) {
    $sId = (int)$sId;
    $before = $scanRepo->getById($sId);
    if (!$before) {
        echo "[SKIP] Scan #{$sId} not found.\n";
        continue;
    }

    echo "--------------------------------------------------------\n";
    echo "Reconciling Scan #{$sId} (Project #{$before['project_id']}, Status: {$before['status']})\n";
    echo "  BEFORE: Processed: {$before['processed_count']} | Clean: {$before['clean_count']} | Affected: {$before['affected_page_count']} | Failed: {$before['failed_count']}\n";
    echo "          Total Findings: {$before['total_findings']} | Lorem Findings: " . ($before['lorem_finding_count'] ?? 0) . " | Lorem Pages: " . ($before['lorem_page_count'] ?? 0) . "\n";

    $after = $scanRepo->reconcileCounters($sId);

    echo "  AFTER : Processed: {$after['processed_count']} | Clean: {$after['clean_count']} | Affected: {$after['affected_page_count']} | Failed: {$after['failed_count']}\n";
    echo "          Total Findings: {$after['total_findings']} | Lorem Findings: {$after['lorem_finding_count']} | Lorem Occurrences: {$after['lorem_occurrence_count']} | Lorem Pages: {$after['lorem_page_count']}\n";

    $reconciledCount++;
}

echo "\nReconciliation complete: {$reconciledCount} scan(s) updated successfully.\n";
exit(0);
