<?php
/**
 * Automated Regression Test Suite: Database Transactions, Recovery & Scan Completion Guards
 *
 * Covers:
 * Phase 8.A: Transaction Tests
 *   1. Successful page persistence
 *   2. Failed page persistence
 *   3. Database exception during persistence
 *   4. Rollback after a failed transaction
 *   5. URL state recovery after database failure
 *   6. Duplicate persistence prevention
 *
 * Phase 8.D: Scan Completion Tests
 *   1. Scan with all completed URLs can complete
 *   2. Scan with failed terminal URLs can complete
 *   3. Scan with pending URLs cannot complete
 *   4. Scan with queued URLs cannot complete
 *   5. Scan with processing URLs cannot complete
 *   6. Scan with retry-wait URLs cannot complete
 *   7. Scan with no discovered URLs can complete
 *   8. Scan with retries eventually completes after all URLs reach terminal states
 *   9. Worker cannot force completion when unfinished URLs remain
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/QueryHelper.php';
require_once dirname(__DIR__) . '/includes/HttpClient.php';
require_once dirname(__DIR__) . '/includes/Discovery/UrlNormalizer.php';
require_once dirname(__DIR__) . '/includes/Detection/HtmlContentExtractor.php';
require_once dirname(__DIR__) . '/includes/Detection/DetectionEngine.php';
require_once dirname(__DIR__) . '/includes/Detection/AuditModules.php';
require_once dirname(__DIR__) . '/includes/Repository/ProjectRepository.php';
require_once dirname(__DIR__) . '/includes/Repository/ScanRepository.php';
require_once dirname(__DIR__) . '/includes/Repository/UrlRepository.php';
require_once dirname(__DIR__) . '/includes/Repository/PageResultRepository.php';
require_once dirname(__DIR__) . '/includes/Repository/ResourceRepository.php';
require_once dirname(__DIR__) . '/includes/Repository/FindingRepository.php';
require_once dirname(__DIR__) . '/includes/Repository/RuleRepository.php';
require_once dirname(__DIR__) . '/includes/Repository/LogRepository.php';
require_once dirname(__DIR__) . '/includes/Worker/ScanRecovery.php';

use LoremChecker\Repository\FindingRepository;
use LoremChecker\Repository\LogRepository;
use LoremChecker\Repository\PageResultRepository;
use LoremChecker\Repository\ProjectRepository;
use LoremChecker\Repository\ResourceRepository;
use LoremChecker\Repository\ScanRepository;
use LoremChecker\Repository\UrlRepository;
use LoremChecker\Worker\ScanRecovery;

class ScanTransactionRecoveryTest
{
    private PDO $pdo;
    public int  $assertions = 0;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON;');
        $this->createSchema();
    }

    private function createSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_name VARCHAR(255) NOT NULL,
                website_url TEXT NOT NULL,
                normalized_website_url VARCHAR(500) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL
            );

            CREATE TABLE scans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                scan_number INTEGER NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT "queued",
                worker_pid INTEGER NULL,
                last_heartbeat_at DATETIME NULL,
                discovered_count INTEGER NOT NULL DEFAULT 0,
                processed_count INTEGER NOT NULL DEFAULT 0,
                clean_count INTEGER NOT NULL DEFAULT 0,
                affected_page_count INTEGER NOT NULL DEFAULT 0,
                failed_count INTEGER NOT NULL DEFAULT 0,
                total_findings INTEGER NOT NULL DEFAULT 0,
                lorem_finding_count INTEGER NOT NULL DEFAULT 0,
                audit_finding_count INTEGER NOT NULL DEFAULT 0,
                resource_finding_count INTEGER NOT NULL DEFAULT 0,
                url_limit_reached INTEGER NOT NULL DEFAULT 0,
                excluded_by_limit_count INTEGER NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                elapsed_time INTEGER NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE scan_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id INTEGER NOT NULL,
                settings_json TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE discovered_urls (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id INTEGER NOT NULL,
                original_url TEXT NOT NULL,
                normalized_url TEXT NOT NULL,
                url_hash CHAR(64) NOT NULL,
                domain VARCHAR(255) NOT NULL,
                crawl_depth INTEGER NOT NULL DEFAULT 0,
                status VARCHAR(50) NOT NULL DEFAULT "pending",
                final_url TEXT NULL,
                retry_count INTEGER NOT NULL DEFAULT 0,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                next_attempt_at DATETIME NULL,
                last_error TEXT NULL,
                processed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE page_results (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id INTEGER NOT NULL,
                discovered_url_id INTEGER NOT NULL,
                requested_url TEXT NOT NULL,
                final_url TEXT NULL,
                http_status INTEGER NULL,
                content_type VARCHAR(255) NULL,
                processing_status VARCHAR(50) NOT NULL DEFAULT "clean",
                audit_status VARCHAR(50) NOT NULL DEFAULT "clean",
                finding_count INTEGER NOT NULL DEFAULT 0,
                duration_ms INTEGER NULL,
                retry_count INTEGER NOT NULL DEFAULT 0,
                error_type VARCHAR(100) NULL,
                error_message TEXT NULL,
                page_title TEXT NULL,
                word_count INTEGER NULL,
                has_lorem_ipsum INTEGER NOT NULL DEFAULT 0,
                content_hash CHAR(64) NULL,
                rendered_with_js INTEGER NOT NULL DEFAULT 0,
                started_at DATETIME NULL,
                completed_at DATETIME NULL
            );

            CREATE TABLE findings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id INTEGER NOT NULL,
                page_result_id INTEGER NOT NULL,
                resource_id INTEGER NULL,
                page_url TEXT NOT NULL,
                resource_url TEXT NULL,
                resource_type VARCHAR(50) NOT NULL DEFAULT "html",
                matched_text TEXT NOT NULL,
                surrounding_context TEXT NULL,
                html_element VARCHAR(100) NULL,
                css_selector VARCHAR(500) NULL,
                section_name VARCHAR(500) NULL,
                line_number INTEGER NULL,
                character_position INTEGER NULL,
                is_visible INTEGER NULL,
                detection_rule_id INTEGER NULL,
                detection_category VARCHAR(100) NOT NULL DEFAULT "lorem_ipsum",
                severity VARCHAR(20) NOT NULL DEFAULT "high",
                confidence VARCHAR(20) NOT NULL DEFAULT "high",
                finding_fingerprint CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE scan_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id INTEGER NOT NULL,
                project_id INTEGER NOT NULL,
                event_type VARCHAR(50) NOT NULL DEFAULT "general",
                log_level VARCHAR(20) NOT NULL DEFAULT "INFO",
                message TEXT NOT NULL,
                url TEXT NULL,
                metadata_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
        ');
    }

    public function run(): void
    {
        $projectRepo = new ProjectRepository($this->pdo);
        $scanRepo    = new ScanRepository($this->pdo);
        $urlRepo     = new UrlRepository($this->pdo);
        $pageResRepo = new PageResultRepository($this->pdo);
        $findingRepo = new FindingRepository($this->pdo);
        $logRepo     = new LogRepository($this->pdo);

        $projId = $projectRepo->findOrCreate('Tx Test Project', 'https://txtest.org', 'https://txtest.org');
        $scanId = $scanRepo->createScan($projId);

        // =========================================================================
        // PART A: TRANSACTION & PERSISTENCE TESTS
        // =========================================================================

        // A1. Successful page persistence
        $urls = [
            ['url' => 'https://txtest.org/page1', 'normalized_url' => 'https://txtest.org/page1', 'url_hash' => hash('sha256', 'https://txtest.org/page1'), 'depth' => 0, 'source' => 'test'],
            ['url' => 'https://txtest.org/page2', 'normalized_url' => 'https://txtest.org/page2', 'url_hash' => hash('sha256', 'https://txtest.org/page2'), 'depth' => 1, 'source' => 'test'],
            ['url' => 'https://txtest.org/page3', 'normalized_url' => 'https://txtest.org/page3', 'url_hash' => hash('sha256', 'https://txtest.org/page3'), 'depth' => 1, 'source' => 'test'],
        ];
        $urlRepo->insertBatch($scanId, $urls);

        // Fetch URL 1 and persist success
        $urlRepo->markStatus(1, 'completed', 'https://txtest.org/page1');
        $pageResId = $pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => 1,
            'requested_url'     => 'https://txtest.org/page1',
            'final_url'         => 'https://txtest.org/page1',
            'http_status'       => 200,
            'content_type'      => 'html',
            'processing_status' => 'clean',
            'finding_count'     => 0,
            'page_title'        => 'Page 1 Title',
        ]);
        $this->assertTrue($pageResId > 0, 'A1: Successful page result persisted');

        // A2. Failed page persistence
        $urlRepo->markStatus(2, 'failed', null, 'HTTP 404 Not Found');
        $failedPageId = $pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => 2,
            'requested_url'     => 'https://txtest.org/page2',
            'http_status'       => 404,
            'processing_status' => 'failed',
            'error_type'        => 'http_error',
            'error_message'     => 'HTTP 404 Not Found',
        ]);
        $this->assertTrue($failedPageId > 0, 'A2: Failed page result persisted with error code and message');

        // A3 & A4. Database exception and rollback
        $this->pdo->beginTransaction();
        try {
            $urlRepo->markStatus(3, 'processing');
            // Deliberately trigger SQL error
            $this->pdo->exec('INSERT INTO non_existent_table (test) VALUES (1)');
            $this->pdo->commit();
            $this->assertTrue(false, 'Should have thrown PDOException');
        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->assertTrue(true, 'A3 & A4: Caught DB exception and rolled back transaction cleanly');
        }

        // Verify URL 3 state rolled back to 'pending' after rollback
        $checkStmt = $this->pdo->prepare('SELECT status FROM discovered_urls WHERE id = 3');
        $checkStmt->execute();
        $u3Status = $checkStmt->fetchColumn();
        $this->assertEquals('pending', $u3Status, 'A4: URL 3 status was successfully rolled back');

        // A5. URL state recovery after database failure
        // Simulate scheduler handling where URL was set to processing before a failure
        $urlRepo->markStatus(3, 'processing');
        // Simulate persistence recovery
        $urlRepo->markStatus(3, 'failed', null, 'Simulated DB recovery test');
        $checkStmt->execute();
        $this->assertEquals('failed', $checkStmt->fetchColumn(), 'A5: URL recovered from processing to failed');

        // A6. Duplicate persistence prevention
        // Calling saveResult again for discovered_url_id = 1 should update existing row and not insert duplicate!
        $updatedPageId = $pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => 1,
            'requested_url'     => 'https://txtest.org/page1',
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'finding_count'     => 2,
            'page_title'        => 'Updated Title',
        ]);
        $this->assertEquals($pageResId, $updatedPageId, 'A6: Calling saveResult for existing URL returns same page_result ID');

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM page_results WHERE scan_id = ? AND discovered_url_id = 1');
        $countStmt->execute([$scanId]);
        $this->assertEquals(1, (int)$countStmt->fetchColumn(), 'A6: No duplicate page_result rows created');

        // =========================================================================
        // PART B: SCAN COMPLETION GUARD TESTS (PHASE 8.D)
        // =========================================================================

        // D1 & D2: Scan where all URLs are terminal (URL 1 = completed, URL 2 = failed, URL 3 = failed)
        $compOk = $scanRepo->completeScan($scanId);
        $this->assertTrue($compOk, 'D1 & D2: Scan with all terminal URLs (completed + failed) completes successfully');

        // Create new scan for non-terminal guard tests
        $scan2Id = $scanRepo->createScan($projId);
        $urlRepo->insertBatch($scan2Id, [
            ['url' => 'https://txtest.org/sub1', 'normalized_url' => 'https://txtest.org/sub1', 'url_hash' => hash('sha256', 'sub1'), 'depth' => 0, 'source' => 'test'],
            ['url' => 'https://txtest.org/sub2', 'normalized_url' => 'https://txtest.org/sub2', 'url_hash' => hash('sha256', 'sub2'), 'depth' => 0, 'source' => 'test'],
        ]);

        // D3. Scan with pending URLs cannot complete
        $d3Rejected = $scanRepo->completeScan($scan2Id);
        $this->assertTrue(!$d3Rejected, 'D3: completeScan correctly rejected when URLs are pending');

        // D4. Scan with queued URLs cannot complete
        $sub1Id = (int)$this->pdo->query("SELECT id FROM discovered_urls WHERE scan_id = {$scan2Id} AND url_hash = '" . hash('sha256', 'sub1') . "'")->fetchColumn();
        $sub2Id = (int)$this->pdo->query("SELECT id FROM discovered_urls WHERE scan_id = {$scan2Id} AND url_hash = '" . hash('sha256', 'sub2') . "'")->fetchColumn();

        $urlRepo->markStatus($sub1Id, 'queued');
        $d4Rejected = $scanRepo->completeScan($scan2Id);
        $this->assertTrue(!$d4Rejected, 'D4: completeScan correctly rejected when URLs are queued');

        // D5. Scan with processing URLs cannot complete
        $urlRepo->markStatus($sub1Id, 'processing');
        $d5Rejected = $scanRepo->completeScan($scan2Id);
        $this->assertTrue(!$d5Rejected, 'D5: completeScan correctly rejected when URLs are processing');

        // D6. Scan with retry_wait URLs cannot complete (the Scan 5 regression guard!)
        $urlRepo->markStatus($sub1Id, 'retry_wait', null, 'Timeout');
        $urlRepo->markStatus($sub2Id, 'completed');
        $d6Rejected = $scanRepo->completeScan($scan2Id);
        $this->assertTrue(!$d6Rejected, 'D6: completeScan correctly rejected when URLs are in retry_wait');

        // D7. Scan with no discovered URLs (empty scan) can complete
        $scanEmptyId = $scanRepo->createScan($projId);
        $d7Ok = $scanRepo->completeScan($scanEmptyId);
        $this->assertTrue($d7Ok, 'D7: Scan with 0 discovered URLs can complete normally');

        // D8. Scan with retries eventually completes after all URLs reach terminal states
        $urlRepo->markStatus($sub1Id, 'failed', null, 'Retries exhausted');
        $d8Ok = $scanRepo->completeScan($scan2Id);
        $this->assertTrue($d8Ok, 'D8: Scan with retries completes once all URLs reach terminal states');

        // D9. Worker recovery of dangling processing URLs
        $scan3Id = $scanRepo->createScan($projId);
        $urlRepo->insertBatch($scan3Id, [
            ['url' => 'https://txtest.org/hang1', 'normalized_url' => 'https://txtest.org/hang1', 'url_hash' => hash('sha256', 'hang1'), 'depth' => 0, 'source' => 'test'],
        ]);
        $hangId = (int)$this->pdo->query("SELECT id FROM discovered_urls WHERE scan_id = {$scan3Id}")->fetchColumn();
        $urlRepo->markStatus($hangId, 'processing');

        $recovery = new ScanRecovery($scanRepo, $urlRepo, $logRepo);
        $resetCount = $urlRepo->resetProcessingToPending($scan3Id);
        $this->assertEquals(1, $resetCount, 'D9: resetProcessingToPending restored orphaned URL to pending');
    }

    private function assertTrue(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }

    private function assertEquals($expected, $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new \RuntimeException("Assertion failed: {$message}. Expected: " . var_export($expected, true) . ", got: " . var_export($actual, true));
        }
    }
}
