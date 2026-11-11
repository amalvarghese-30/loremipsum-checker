<?php
/**
 * Legacy JSON Data Migration Tool
 *
 * Imports existing project JSON files from data/projects/*.json,
 * associated URL lists, results, and logs into MySQL.
 *
 * Requirements satisfied:
 * - Idempotent (safe to run multiple times without duplicating data)
 * - Dry-run mode (--dry-run)
 * - Automated backup of data/ directory before migrating
 * - Never deletes original JSON files
 * - Detailed migration summary report
 *
 * Usage:
 *   php database/migrate_legacy_json.php
 *   php database/migrate_legacy_json.php --dry-run
 */

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));
define('DATA_DIR', BASE_DIR . '/data');
define('PROJECTS_DIR', DATA_DIR . '/projects');
define('LOGS_DIR', DATA_DIR . '/logs');
define('SITEMAPS_DIR', DATA_DIR . '/sitemaps');

require_once BASE_DIR . '/includes/database.php';
require_once BASE_DIR . '/includes/Discovery/UrlNormalizer.php';

use LoremChecker\Database;
use LoremChecker\Discovery\UrlNormalizer;

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);

echo "========================================================\n";
echo "   LOREM IPSUM CHECKER - LEGACY JSON DATA MIGRATION     \n";
echo "========================================================\n\n";

if ($dryRun) {
    echo "[DRY-RUN MODE] No database changes will be committed.\n\n";
}

if (!is_dir(PROJECTS_DIR)) {
    echo "No projects directory found at " . PROJECTS_DIR . ". Nothing to migrate.\n";
    exit(0);
}

$projectFiles = glob(PROJECTS_DIR . '/*.json');
if (empty($projectFiles)) {
    echo "No project JSON files found in " . PROJECTS_DIR . ".\n";
    exit(0);
}

echo "Found " . count($projectFiles) . " project file(s) to process.\n";

// Backup data directory before proceeding (if not in dry-run)
if (!$dryRun) {
    $backupDir = DATA_DIR . '/backup_' . date('Ymd_His');
    echo "Creating backup of data/ at {$backupDir}... ";
    backupDirectory(DATA_DIR, $backupDir, ['backup_']);
    echo "✓ Done.\n\n";
}

$normalizer = new UrlNormalizer();

try {
    $pdo = Database::getInstance();
} catch (Exception $e) {
    echo "Error connecting to database: " . $e->getMessage() . "\n";
    echo "Please ensure migrations are applied before running this tool: php database/migrate.php\n";
    exit(1);
}

$stats = [
    'projects_imported' => 0,
    'projects_skipped'  => 0,
    'scans_imported'     => 0,
    'urls_imported'      => 0,
    'results_imported'   => 0,
    'logs_imported'      => 0,
    'errors'             => [],
];

foreach ($projectFiles as $file) {
    $filename = basename($file);
    echo "Processing {$filename}... ";

    $raw = @file_get_contents($file);
    $data = @json_decode($raw, true);

    if (!is_array($data) || empty($data['id']) || empty($data['website_url'])) {
        echo "SKIPPED (Malformed JSON or missing website_url)\n";
        $stats['projects_skipped']++;
        $stats['errors'][] = "{$filename}: Malformed JSON";
        continue;
    }

    $projectName   = $data['project_name'] ?? 'Imported Project';
    $websiteUrl    = $data['website_url'];
    $normalizedUrl = $normalizer->normalize($websiteUrl) ?: $websiteUrl;
    $legacyId      = $data['id'];
    $createdAt     = $data['created_at'] ?? date('Y-m-d H:i:s');
    $updatedAt     = $data['updated_at'] ?? $createdAt;
    $status        = $data['status'] ?? 'completed';

    // Map legacy status to enum
    $statusMap = [
        'initializing'        => 'initializing',
        'detecting_sitemap'   => 'discovering',
        'generating_sitemap'  => 'discovering',
        'extracting'          => 'discovering',
        'checking'            => 'scanning',
        'paused'              => 'paused',
        'completed'           => 'completed',
        'failed'              => 'failed',
    ];
    $scanStatus = $statusMap[$status] ?? 'completed';

    if ($dryRun) {
        echo "[DRY-RUN] Would import project '{$projectName}' ({$websiteUrl})\n";
        $stats['projects_imported']++;
        continue;
    }

    $pdo->beginTransaction();

    try {
        // 1. Find or create project
        $findProj = $pdo->prepare('SELECT id FROM projects WHERE normalized_website_url = ? LIMIT 1');
        $findProj->execute([$normalizedUrl]);
        $projectId = $findProj->fetchColumn();

        if (!$projectId) {
            $insertProj = $pdo->prepare('
                INSERT INTO projects (project_name, website_url, normalized_website_url, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?)
            ');
            $insertProj->execute([$projectName, $websiteUrl, $normalizedUrl, $createdAt, $updatedAt]);
            $projectId = (int)$pdo->lastInsertId();
            $stats['projects_imported']++;
        } else {
            $projectId = (int)$projectId;
            $stats['projects_skipped']++; // Project already existed
        }

        // 2. Check if a scan with this legacy ID / timestamp already exists
        $findScan = $pdo->prepare('SELECT id FROM scans WHERE project_id = ? AND created_at = ? LIMIT 1');
        $findScan->execute([$projectId, $createdAt]);
        $scanId = $findScan->fetchColumn();

        if ($scanId) {
            echo "SKIPPED (Scan already imported)\n";
            $pdo->commit();
            continue;
        }

        // 3. Create scan record
        $prog = $data['progress'] ?? [];
        $totalUrls   = (int)($prog['total_urls'] ?? count($data['results'] ?? []));
        $processed   = (int)($prog['processed'] ?? $totalUrls);
        $clean       = (int)($prog['clean'] ?? 0);
        $loremFound  = (int)($prog['lorem_found'] ?? 0);
        $errors      = (int)($prog['errors'] ?? 0);

        $insertScan = $pdo->prepare('
            INSERT INTO scans (
                project_id, scan_number, status, started_at, completed_at,
                discovered_count, processed_count, clean_count, affected_page_count,
                failed_count, total_findings, created_at, updated_at
            ) VALUES (
                ?, 1, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?
            )
        ');
        $insertScan->execute([
            $projectId,
            $scanStatus,
            $createdAt,
            $updatedAt,
            $totalUrls,
            $processed,
            $clean,
            $loremFound,
            $errors,
            $loremFound,
            $createdAt,
            $updatedAt
        ]);
        $scanId = (int)$pdo->lastInsertId();
        $stats['scans_imported']++;

        // 4. Import discovered URLs from data/sitemaps/{projectId}_urls.json if exists
        $urlsFile = SITEMAPS_DIR . '/' . $legacyId . '_urls.json';
        $discoveredUrlIds = [];

        $urlInsertStmt = $pdo->prepare('
            INSERT IGNORE INTO discovered_urls (
                scan_id, original_url, normalized_url, url_hash,
                discovery_source, crawl_depth, status, discovered_at
            ) VALUES (?, ?, ?, ?, ?, 0, "completed", ?)
        ');

        if (file_exists($urlsFile)) {
            $urlList = @json_decode(file_get_contents($urlsFile), true);
            if (is_array($urlList)) {
                foreach ($urlList as $url) {
                    $norm = $normalizer->normalize($url, $websiteUrl) ?: $url;
                    $hash = $normalizer->getUrlHash($norm);
                    $urlInsertStmt->execute([
                        $scanId,
                        $url,
                        $norm,
                        $hash,
                        !empty($data['sitemap_used']) ? 'sitemap_xml' : 'crawler',
                        $createdAt
                    ]);
                    $stats['urls_imported']++;
                }
            }
        }

        // 5. Import results
        $results = $data['results'] ?? [];
        if (!empty($results)) {
            $pageResStmt = $pdo->prepare('
                INSERT INTO page_results (
                    scan_id, discovered_url_id, requested_url, final_url,
                    http_status, content_type, processing_status, finding_count,
                    has_lorem_ipsum, started_at, completed_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, "html", ?, ?,
                    ?, ?, ?
                )
            ');

            $findingStmt = $pdo->prepare('
                INSERT INTO findings (
                    scan_id, page_result_id, page_url, matched_text,
                    detection_category, severity, confidence, finding_fingerprint, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    "lorem_ipsum", "high", "high", ?, ?
                )
            ');

            foreach ($results as $res) {
                $pageUrl = $res['url'] ?? '';
                if (empty($pageUrl)) {
                    continue;
                }

                $norm = $normalizer->normalize($pageUrl, $websiteUrl) ?: $pageUrl;
                $hash = $normalizer->getUrlHash($norm);

                // Ensure a discovered_url record exists for this result
                $urlInsertStmt->execute([
                    $scanId,
                    $pageUrl,
                    $norm,
                    $hash,
                    'imported',
                    $createdAt
                ]);

                $findDurl = $pdo->prepare('SELECT id FROM discovered_urls WHERE scan_id = ? AND url_hash = ? LIMIT 1');
                $findDurl->execute([$scanId, $hash]);
                $durlId = (int)$findDurl->fetchColumn();

                $pStatus = 'clean';
                $hasLorem = 0;
                $findingCount = 0;
                $httpCode = 200;

                if (($res['status'] ?? '') === 'lorem_found') {
                    $pStatus = 'findings_detected';
                    $hasLorem = 1;
                    $findingCount = (int)($res['occurrences'] ?? 1);
                } elseif (($res['status'] ?? '') === 'cloudflare_blocked') {
                    $pStatus = 'cloudflare_blocked';
                    $httpCode = 403;
                } elseif (($res['status'] ?? '') === 'error') {
                    $pStatus = 'failed';
                    $httpCode = 500;
                }

                $checkedAt = $res['checked_at'] ?? $updatedAt;

                $pageResStmt->execute([
                    $scanId,
                    $durlId,
                    $pageUrl,
                    $pageUrl,
                    $httpCode,
                    $pStatus,
                    $findingCount,
                    $hasLorem,
                    $checkedAt,
                    $checkedAt
                ]);
                $pageResId = (int)$pdo->lastInsertId();
                $stats['results_imported']++;

                if ($hasLorem) {
                    $matchedText = $res['details'] ?? 'Lorem Ipsum match';
                    $fingerprint = hash('sha256', $norm . 'html' . strtolower($matchedText));
                    $findingStmt->execute([
                        $scanId,
                        $pageResId,
                        $pageUrl,
                        $matchedText,
                        $fingerprint,
                        $checkedAt
                    ]);
                }
            }
        }

        // 6. Import logs from data/logs/{projectId}.log if present
        $logFile = LOGS_DIR . '/' . $legacyId . '.log';
        if (file_exists($logFile)) {
            $logLines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!empty($logLines)) {
                $logStmt = $pdo->prepare('
                    INSERT INTO scan_logs (
                        scan_id, project_id, log_level, message, created_at
                    ) VALUES (?, ?, ?, ?, ?)
                ');

                foreach ($logLines as $line) {
                    if (preg_match('/\[(.*?)\]\s*\[(.*?)\]\s*(.*)/', $line, $m)) {
                        $logStmt->execute([
                            $scanId,
                            $projectId,
                            strtoupper(trim($m[2])),
                            trim($m[3]),
                            trim($m[1])
                        ]);
                        $stats['logs_imported']++;
                    }
                }
            }
        }

        $pdo->commit();
        echo "✓ IMPORTED (Scan ID: {$scanId})\n";

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "✗ FAILED: " . $e->getMessage() . "\n";
        $stats['errors'][] = "{$filename}: " . $e->getMessage();
    }
}

echo "\n========================================================\n";
echo "                   MIGRATION SUMMARY                    \n";
echo "========================================================\n";
echo "Projects Created:  " . $stats['projects_imported'] . "\n";
echo "Projects Reused:   " . $stats['projects_skipped'] . "\n";
echo "Scans Created:     " . $stats['scans_imported'] . "\n";
echo "URLs Inserted:     " . $stats['urls_imported'] . "\n";
echo "Results Inserted:  " . $stats['results_imported'] . "\n";
echo "Logs Inserted:     " . $stats['logs_imported'] . "\n";
if (!empty($stats['errors'])) {
    echo "\nErrors / Warnings (" . count($stats['errors']) . "):\n";
    foreach ($stats['errors'] as $err) {
        echo " - {$err}\n";
    }
}
echo "========================================================\n";

function backupDirectory(string $source, string $dest, array $excludePrefixes = []): void
{
    if (!is_dir($dest)) {
        @mkdir($dest, 0755, true);
    }

    $dir = opendir($source);
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        foreach ($excludePrefixes as $prefix) {
            if (str_starts_with($file, $prefix)) {
                continue 2;
            }
        }

        $srcPath  = $source . '/' . $file;
        $destPath = $dest . '/' . $file;

        if (is_dir($srcPath)) {
            backupDirectory($srcPath, $destPath, $excludePrefixes);
        } else {
            @copy($srcPath, $destPath);
        }
    }
    closedir($dir);
}
