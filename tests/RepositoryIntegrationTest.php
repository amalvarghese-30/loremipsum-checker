<?php
/**
 * Database Repository & Integration Test (SQLite & MySQL compatible)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/QueryHelper.php';
require_once dirname(__DIR__) . '/includes/Repository/ProjectRepository.php';
require_once dirname(__DIR__) . '/includes/Repository/ScanRepository.php';
require_once dirname(__DIR__) . '/includes/Repository/UrlRepository.php';
require_once dirname(__DIR__) . '/includes/Repository/PageResultRepository.php';
require_once dirname(__DIR__) . '/includes/Repository/ResourceRepository.php';
require_once dirname(__DIR__) . '/includes/Repository/FindingRepository.php';
require_once dirname(__DIR__) . '/includes/Repository/RuleRepository.php';
require_once dirname(__DIR__) . '/includes/Repository/LogRepository.php';

use LoremChecker\Repository\FindingRepository;
use LoremChecker\Repository\LogRepository;
use LoremChecker\Repository\PageResultRepository;
use LoremChecker\Repository\ProjectRepository;
use LoremChecker\Repository\ResourceRepository;
use LoremChecker\Repository\RuleRepository;
use LoremChecker\Repository\ScanRepository;
use LoremChecker\Repository\UrlRepository;

class RepositoryIntegrationTest
{
    public int $assertions = 0;
    private PDO $pdo;

    public function __construct()
    {
        // Use an in-memory SQLite database to test all repositories and SQL queries
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->setupSchema();
    }

    private function setupSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_name VARCHAR(255) NOT NULL,
                website_url TEXT NOT NULL,
                normalized_website_url VARCHAR(2048) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL
            );

            CREATE TABLE scans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                scan_number INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT "queued",
                started_at DATETIME NULL,
                paused_at DATETIME NULL,
                completed_at DATETIME NULL,
                last_heartbeat_at DATETIME NULL,
                worker_pid INTEGER NULL,
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
                elapsed_time INTEGER NULL,
                error_message TEXT NULL,
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
                normalized_url VARCHAR(2048) NOT NULL,
                domain VARCHAR(255) NULL,
                canonical_url TEXT NULL,
                final_url TEXT NULL,
                url_hash CHAR(64) NOT NULL,
                discovery_source VARCHAR(100) NOT NULL DEFAULT "unknown",
                crawl_depth INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT "pending",
                retry_count INTEGER NOT NULL DEFAULT 0,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                next_attempt_at DATETIME NULL,
                discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME NULL,
                last_error TEXT NULL
            );

            CREATE TABLE url_discovery_sources (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                discovered_url_id INTEGER NOT NULL,
                scan_id INTEGER NOT NULL,
                source_type VARCHAR(100) NOT NULL,
                source_url TEXT NULL,
                crawl_depth INTEGER NOT NULL DEFAULT 0,
                discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE page_results (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id INTEGER NOT NULL,
                discovered_url_id INTEGER NOT NULL,
                requested_url TEXT NOT NULL,
                final_url TEXT NULL,
                http_status INTEGER NULL,
                content_type VARCHAR(255) NULL,
                processing_status TEXT NOT NULL DEFAULT "clean",
                audit_status TEXT NOT NULL DEFAULT "clean",
                finding_count INTEGER NOT NULL DEFAULT 0,
                duration_ms INTEGER NULL,
                retry_count INTEGER NOT NULL DEFAULT 0,
                error_type VARCHAR(100) NULL,
                error_message TEXT NULL,
                page_title VARCHAR(1000) NULL,
                word_count INTEGER NULL,
                has_lorem_ipsum INTEGER NOT NULL DEFAULT 0,
                content_hash CHAR(64) NULL,
                rendered_with_js INTEGER NOT NULL DEFAULT 0,
                started_at DATETIME NULL,
                completed_at DATETIME NULL
            );

            CREATE TABLE scan_resources (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id INTEGER NOT NULL,
                page_result_id INTEGER NULL,
                resource_url TEXT NOT NULL,
                normalized_resource_url VARCHAR(2048) NOT NULL,
                resource_hash CHAR(64) NOT NULL,
                resource_type TEXT NOT NULL DEFAULT "other",
                content_type VARCHAR(255) NULL,
                http_status INTEGER NULL,
                processing_status TEXT NOT NULL DEFAULT "pending",
                response_size INTEGER NULL,
                duration_ms INTEGER NULL,
                finding_count INTEGER NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                fetched_at DATETIME NULL
            );

            CREATE TABLE page_resource_relations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id INTEGER NOT NULL,
                page_result_id INTEGER NOT NULL,
                resource_id INTEGER NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE findings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id INTEGER NOT NULL,
                page_result_id INTEGER NOT NULL,
                resource_id INTEGER NULL,
                page_url TEXT NOT NULL,
                resource_url TEXT NULL,
                resource_type TEXT NOT NULL DEFAULT "html",
                matched_text TEXT NOT NULL,
                surrounding_context TEXT NULL,
                html_element VARCHAR(255) NULL,
                css_selector VARCHAR(500) NULL,
                section_name VARCHAR(255) NULL,
                line_number INTEGER NULL,
                character_position INTEGER NULL,
                is_visible INTEGER NOT NULL DEFAULT 1,
                detection_rule_id INTEGER NULL,
                detection_category VARCHAR(100) NOT NULL DEFAULT "lorem_ipsum",
                severity TEXT NOT NULL DEFAULT "high",
                confidence TEXT NOT NULL DEFAULT "high",
                finding_fingerprint CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE detection_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                rule_name VARCHAR(255) NOT NULL,
                pattern TEXT NOT NULL,
                detection_category VARCHAR(100) NOT NULL DEFAULT "placeholder",
                enabled INTEGER NOT NULL DEFAULT 1,
                case_sensitive INTEGER NOT NULL DEFAULT 0,
                severity TEXT NOT NULL DEFAULT "high",
                confidence TEXT NOT NULL DEFAULT "high",
                source_types VARCHAR(255) NOT NULL DEFAULT "html,javascript,css,json,text",
                is_builtin INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE scan_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id INTEGER NOT NULL,
                project_id INTEGER NOT NULL,
                event_type VARCHAR(100) NOT NULL DEFAULT "general",
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
        $projectRepo  = new ProjectRepository($this->pdo);
        $scanRepo     = new ScanRepository($this->pdo);
        $urlRepo      = new UrlRepository($this->pdo);
        $pageResRepo  = new PageResultRepository($this->pdo);
        $resourceRepo = new ResourceRepository($this->pdo);
        $findingRepo  = new FindingRepository($this->pdo);
        $ruleRepo     = new RuleRepository($this->pdo);
        $logRepo      = new LogRepository($this->pdo);

        // 1. Project creation
        $projId = $projectRepo->findOrCreate('Test Project', 'https://example.com', 'https://example.com/');
        $this->assertTrue($projId > 0, 'Project created');

        // 2. Scan creation with settings snapshot
        $scanId = $scanRepo->createScan($projId, ['max_urls' => 200, 'concurrency' => 8]);
        $this->assertTrue($scanId > 0, 'Scan created');
        $settings = $scanRepo->getSettings($scanId);
        $this->assertEquals(200, $settings['max_urls'] ?? null, 'Scan settings snapshot saved');

        // 3. Batch URL insertion
        $inserted = $urlRepo->insertBatch($scanId, [
            [
                'url'            => 'https://example.com/page1',
                'normalized_url' => 'https://example.com/page1',
                'url_hash'       => hash('sha256', 'https://example.com/page1'),
                'source'         => 'sitemap',
                'depth'          => 1,
            ],
            [
                'url'            => 'https://example.com/page2',
                'normalized_url' => 'https://example.com/page2',
                'url_hash'       => hash('sha256', 'https://example.com/page2'),
                'source'         => 'internal_crawler',
                'depth'          => 2,
            ]
        ]);
        $this->assertEquals(2, $inserted, 'Inserted 2 URLs into queue');

        // 4. Page results and findings
        $pageId = $pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => 1,
            'requested_url'     => 'https://example.com/page1',
            'final_url'         => 'https://example.com/page1',
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'finding_count'     => 1,
            'has_lorem_ipsum'   => 1,
        ]);
        $this->assertTrue($pageId > 0, 'Page result saved');

        $findingCount = $findingRepo->insertBatch($scanId, $pageId, null, [
            [
                'page_url'            => 'https://example.com/page1',
                'matched_text'        => 'Lorem ipsum dolor sit amet',
                'surrounding_context' => '... Lorem ipsum dolor sit amet ...',
                'detection_category'  => 'lorem_ipsum',
                'severity'            => 'high',
                'confidence'          => 'high',
                'finding_fingerprint' => hash('sha256', 'test_finding_1'),
            ]
        ]);
        $this->assertEquals(1, $findingCount, 'Finding inserted');

        // 5. Shared resource many-to-many relationship
        $res = $resourceRepo->findOrCreate($scanId, $pageId, 'https://example.com/app.js', 'https://example.com/app.js', 'javascript');
        $this->assertTrue($res['id'] > 0, 'Resource registered and linked to page');

        // 6. Custom rules repository
        $ruleId = $ruleRepo->addRule([
            'rule_name' => 'Custom Placeholder',
            'pattern'   => '/__PLACEHOLDER__/i',
            'severity'  => 'high',
            'confidence'=> 'high',
        ]);
        $this->assertTrue($ruleId > 0, 'Custom rule added');

        $rules = $ruleRepo->getActiveRules();
        $this->assertTrue(count($rules) >= 1, 'Active rules queried successfully');

        // 7. Test Heartbeat & Status update (NOW() compatibility)
        $hbOk = $scanRepo->updateHeartbeat($scanId, 1234);
        $this->assertTrue($hbOk, 'Heartbeat updated using QueryHelper NOW()');

        $stOk = $scanRepo->updateStatus($scanId, 'scanning');
        $this->assertTrue($stOk, 'Scan status updated');

        // 8. Test Retry Scheduling (DATE_ADD compatibility)
        $retryOk = $urlRepo->scheduleRetry(1, 'Temporary Timeout', 10);
        $this->assertTrue($retryOk, 'URL retry scheduled with future timestamp');

        // 9. Test Stale Scans Detection (DATE_SUB compatibility)
        $staleScans = $scanRepo->getStaleScans(300);
        $this->assertTrue(is_array($staleScans), 'Stale scans queried successfully');

        // 10. Test Scan Completion Guard (Phase 6 requirement)
        // With URL 1 in retry_wait and URL 2 in pending, completion MUST be rejected by DB guard!
        $compRejected = $scanRepo->completeScan($scanId);
        $this->assertTrue(!$compRejected, 'Scan completion correctly rejected when unfinished URLs remain in queue');

        // 11. Test URL status transitions (pending -> processing -> completed/failed)
        // 11a. Transition to 'processing' -> processed_at must remain NULL
        $urlRepo->markStatus(2, 'processing');
        $checkStmt = $this->pdo->prepare('SELECT status, processed_at FROM discovered_urls WHERE id = 2');
        $checkStmt->execute();
        $u2 = $checkStmt->fetch();
        $this->assertEquals('processing', $u2['status'], 'Status transitioned to processing');
        $this->assertTrue($u2['processed_at'] === null, 'processed_at remains NULL during processing');

        // 11b. Transition to 'completed' -> processed_at must receive a valid timestamp
        $urlRepo->markStatus(2, 'completed', 'https://example.com/page2');
        $checkStmt->execute();
        $u2Completed = $checkStmt->fetch();
        $this->assertEquals('completed', $u2Completed['status'], 'Status transitioned to completed');
        $this->assertTrue(!empty($u2Completed['processed_at']), 'processed_at populated on completed');

        // 11c. Transition to 'failed' -> processed_at must receive a valid timestamp
        $urlRepo->markStatus(1, 'failed', null, 'Fatal 500 error');
        $checkStmt1 = $this->pdo->prepare('SELECT status, processed_at FROM discovered_urls WHERE id = 1');
        $checkStmt1->execute();
        $u1Failed = $checkStmt1->fetch();
        $this->assertEquals('failed', $u1Failed['status'], 'Status transitioned to failed');
        $this->assertTrue(!empty($u1Failed['processed_at']), 'processed_at populated on failed');

        // 11d. Updating existing completed URL to another status -> processed_at remains unchanged
        $prevTime = $u2Completed['processed_at'];
        $urlRepo->markStatus(2, 'needs_review');
        $checkStmt->execute();
        $u2Review = $checkStmt->fetch();
        $this->assertEquals('needs_review', $u2Review['status'], 'Status transitioned to needs_review');
        $this->assertEquals($prevTime, $u2Review['processed_at'], 'processed_at preserved when status is non-terminal');

        // Set URL 2 back to terminal 'completed'
        $urlRepo->markStatus(2, 'completed');

        // 11e. Now that all URLs are in terminal states (completed & failed), completeScan MUST succeed!
        $compOk = $scanRepo->completeScan($scanId);
        $this->assertTrue($compOk, 'Scan completed and elapsed time calculated after all URLs reached terminal state');

        // 12. Test Logging with NOW(3)
        $logRepo->log($scanId, $projId, 'INFO', 'Test log message', 'test');
        $logs = $logRepo->getLogs($scanId);
        $this->assertTrue(count($logs) >= 1, 'Log entry persisted with NOW(3) compatibility');
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
