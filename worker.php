<?php
/**
 * Background Worker Entrypoint
 *
 * Usage:
 *   php worker.php <scan_id_or_project_id>
 */

declare(strict_types=1);

// Error logging setup
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_time_limit(0);
ini_set('memory_limit', '512M');

// Autoload / includes
define('BASE_DIR', __DIR__);
require_once BASE_DIR . '/includes/database.php';
require_once BASE_DIR . '/includes/HttpClient.php';
require_once BASE_DIR . '/includes/Discovery/UrlNormalizer.php';
require_once BASE_DIR . '/includes/Discovery/DiscoveryManager.php';
require_once BASE_DIR . '/includes/Discovery/SitemapDiscovery.php';
require_once BASE_DIR . '/includes/Discovery/RobotsDiscovery.php';
require_once BASE_DIR . '/includes/Discovery/LinkHeaderDiscovery.php';
require_once BASE_DIR . '/includes/Discovery/FeedDiscovery.php';
require_once BASE_DIR . '/includes/Discovery/HtmlSitemapDiscovery.php';
require_once BASE_DIR . '/includes/Discovery/WordPressDiscovery.php';
require_once BASE_DIR . '/includes/Discovery/InternalCrawler.php';
require_once BASE_DIR . '/includes/Repository/ProjectRepository.php';
require_once BASE_DIR . '/includes/Repository/ScanRepository.php';
require_once BASE_DIR . '/includes/Repository/UrlRepository.php';
require_once BASE_DIR . '/includes/Repository/PageResultRepository.php';
require_once BASE_DIR . '/includes/Repository/ResourceRepository.php';
require_once BASE_DIR . '/includes/Repository/FindingRepository.php';
require_once BASE_DIR . '/includes/Repository/LogRepository.php';
require_once BASE_DIR . '/includes/Repository/RuleRepository.php';
require_once BASE_DIR . '/includes/Detection/HtmlContentExtractor.php';
require_once BASE_DIR . '/includes/Detection/DetectionEngine.php';
require_once BASE_DIR . '/includes/Detection/AuditModules.php';
require_once BASE_DIR . '/includes/Worker/ScanRecovery.php';
require_once BASE_DIR . '/includes/Worker/ScanScheduler.php';
require_once BASE_DIR . '/includes/Worker/ScanWorker.php';

use LoremChecker\Database;
use LoremChecker\Worker\ScanWorker;

$config = include BASE_DIR . '/config.php';

$targetArg = $argv[1] ?? null;
if (!$targetArg) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] Error: No scan ID or project ID provided.\n");
    exit(1);
}

try {
    $db = Database::getInstance();
} catch (Exception $e) {
    fwrite(STDERR, "Database connection error: " . $e->getMessage() . "\n");
    exit(1);
}

// Check if numeric scan ID or legacy string project_id
$scanId = null;
if (is_numeric($targetArg)) {
    $scanId = (int)$targetArg;
} else {
    // Resolve legacy project string (e.g. project_698746c10e5e3_1770473153)
    $stmt = $db->prepare('SELECT id FROM projects WHERE project_name = ? OR website_url LIKE ? LIMIT 1');
    $stmt->execute([$targetArg, "%{$targetArg}%"]);
    $projId = $stmt->fetchColumn();

    if ($projId) {
        $scanStmt = $db->prepare('SELECT id FROM scans WHERE project_id = ? ORDER BY id DESC LIMIT 1');
        $scanStmt->execute([$projId]);
        $scanId = (int)$scanStmt->fetchColumn();
    }
}

if (!$scanId) {
    fwrite(STDERR, "Unable to resolve target '{$targetArg}' to a valid scan record.\n");
    exit(1);
}

$worker = new ScanWorker($db, $config);
$worker->execute($scanId);
