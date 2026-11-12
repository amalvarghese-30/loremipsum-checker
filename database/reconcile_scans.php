<?php
/**
 * Scan Counters Reconciliation CLI Script
 *
 * Recalculates aggregate scan counters directly from the authoritative
 * page_results and findings tables, repairing any historical drift or misattributions.
 *
 * Usage:
 *   php database/reconcile_scans.php --dry-run --scan_id=8
 *   php database/reconcile_scans.php --apply --scan_id=8
 *   php database/reconcile_scans.php --apply --project_id=1
 *   php database/reconcile_scans.php --dry-run --all
 *   php database/reconcile_scans.php --apply --all
 *   php database/reconcile_scans.php --apply --scan_id=8 --force-active
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
$scanId      = null;
$projectId   = null;
$all         = false;
$dryRun      = false;
$apply       = false;
$forceActive = false;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--scan_id=')) {
        $scanId = (int)substr($arg, 10);
    } elseif (str_starts_with($arg, '--project_id=')) {
        $projectId = (int)substr($arg, 13);
    } elseif ($arg === '--all') {
        $all = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--force-active') {
        $forceActive = true;
    }
}

// 1. Validate mode selection
if ($dryRun && $apply) {
    fwrite(STDERR, "ERROR: Cannot specify both --dry-run and --apply. Choose one.\n");
    exit(1);
}

if (!$dryRun && !$apply) {
    echo "ERROR: Either --dry-run or --apply must be explicitly provided.\n\n";
    echo "Usage:\n";
    echo "  php database/reconcile_scans.php --dry-run --scan_id=<ID>\n";
    echo "  php database/reconcile_scans.php --apply --scan_id=<ID> [--force-active]\n";
    echo "  php database/reconcile_scans.php --dry-run --project_id=<ID>\n";
    echo "  php database/reconcile_scans.php --apply --project_id=<ID>\n";
    echo "  php database/reconcile_scans.php --dry-run --all\n";
    echo "  php database/reconcile_scans.php --apply --all\n";
    exit(1);
}

// 2. Validate target selection
if (!$scanId && !$projectId && !$all) {
    fwrite(STDERR, "ERROR: Target scan(s) must be specified using --scan_id=<ID>, --project_id=<ID>, or --all.\n");
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

$modeLabel = $apply ? "APPLY (Committing updates to database)" : "DRY RUN (Simulating changes, no database writes)";
echo "========================================================================\n";
echo " SCAN COUNTERS RECONCILIATION — " . $modeLabel . "\n";
echo " Total target scans: " . count($scansToReconcile) . "\n";
echo "========================================================================\n\n";

$activeStatuses = ['initializing', 'discovering', 'scanning', 'pause_requested'];
$successCount = 0;
$skippedCount = 0;
$invariantFailureCount = 0;

foreach ($scansToReconcile as $sId) {
    $sId = (int)$sId;
    $before = $scanRepo->getById($sId);
    if (!$before) {
        echo "[SKIP] Scan #{$sId} not found.\n\n";
        $skippedCount++;
        continue;
    }

    $currentStatus = (string)($before['status'] ?? 'unknown');
    if (in_array($currentStatus, $activeStatuses, true) && !$forceActive) {
        echo "[WARN] Scan #{$sId} is active (status: '{$currentStatus}'). Skipping to avoid race condition.\n";
        echo "       Pass --force-active to override this guard.\n\n";
        $skippedCount++;
        if ($scanId && count($scansToReconcile) === 1) {
            exit(2);
        }
        continue;
    }

    try {
        $result = $scanRepo->reconcileCounters($sId, $apply);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[ERROR] Scan #{$sId} reconciliation threw an exception: " . $e->getMessage() . "\n\n");
        $invariantFailureCount++;
        continue;
    }

    $diag = $result['diagnostics'] ?? [];
    $invariantPassed = !empty($diag['invariant_passed']);
    if (!$invariantPassed) {
        $invariantFailureCount++;
    }

    echo "------------------------------------------------------------------------\n";
    echo "Scan #{$sId} | Project #{$before['project_id']} | Status: {$currentStatus}\n";
    echo "------------------------------------------------------------------------\n";
    printf("%-26s | %-13s | %-16s | %-10s\n", "Counter Field", "Current Value", "Calculated Value", "Difference");
    echo "---------------------------+---------------+------------------+-----------\n";

    $fields = [
        'processed_count'        => 'Processed Pages',
        'clean_count'            => 'Clean Pages',
        'affected_page_count'    => 'Affected Pages',
        'failed_count'           => 'Failed Pages',
        'total_findings'         => 'Total Findings',
        'lorem_finding_count'    => 'Lorem Findings',
        'lorem_occurrence_count' => 'Lorem Occurrences',
        'lorem_page_count'       => 'Lorem Pages',
    ];

    foreach ($fields as $key => $label) {
        $curVal  = (int)($before[$key] ?? 0);
        $calcVal = (int)($result[$key] ?? 0);
        $diff    = $calcVal - $curVal;
        $diffStr = $diff === 0 ? "0" : ($diff > 0 ? "+{$diff}" : "{$diff}");
        printf("%-26s | %-13d | %-16d | %-10s\n", $label, $curVal, $calcVal, $diffStr);
    }

    echo "\nDiagnostics:\n";
    echo "  - Duplicate page results found:        " . ($diag['duplicate_pages_count'] ?? 0) . "\n";
    echo "  - Unclassified page results:           " . ($diag['unclassified_pages_count'] ?? 0) . "\n";
    echo "  - has_lorem_ipsum discrepancies:       " . ($diag['lorem_flag_discrepancy_count'] ?? 0) . ($apply ? " (repaired)" : " (pending repair)") . "\n";
    echo "  - Invariant check (P = C + A + F):     " . ($invariantPassed ? "[PASS]" : "[FAIL]") . "\n";
    echo "  - Status:                              " . ($apply ? "[APPLIED]" : "[DRY-RUN SIMULATED]") . "\n\n";

    $successCount++;
}

echo "========================================================================\n";
echo "SUMMARY:\n";
echo "  Total Scans Processed: {$successCount}\n";
echo "  Skipped Scans:         {$skippedCount}\n";
echo "  Invariant Failures:    {$invariantFailureCount}\n";
echo "========================================================================\n";

if ($invariantFailureCount > 0) {
    exit(2);
}

exit(0);
