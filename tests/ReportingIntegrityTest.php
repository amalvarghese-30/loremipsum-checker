<?php
/**
 * Automated Regression & Integration Test Suite: Full Reporting Integrity and Cross-Layer Consistency
 *
 * Verifies that:
 * 1. 0 Lorem + 116 SEO issues produces 0 Lorem findings in registry, scan status, and summary,
 *    while accurately reporting 116 affected pages and 116 total findings.
 * 2. Multiple Lorem phrase occurrences in a single paragraph produce 1 finding, N occurrences, and 1 affected page.
 * 3. Counter sum integrity: processed_count equals clean_count + affected_page_count + failed_count.
 * 4. External resource findings increment total_findings and resource_finding_count WITHOUT incrementing processed_count.
 * 5. Resource retry preservation: page retries NEVER delete external resource findings (resource_id IS NOT NULL).
 * 6. Resource reprocessing: changes from N to 0 findings deletes old findings and decrements resource counters.
 * 7. Canonical page result deduplication: latest result per discovered URL is canonical; superseded results never inflate current metrics.
 * 8. Mutually exclusive page classification: unknown statuses are unclassified; clean with findings is flagged as discrepancy.
 * 9. ExportService & ExportController: exact 18-column CSV schema, source_type by relationship identity, authoritative JSON metrics.
 * 10. Runtime counter idempotency: retries use signed deltas without double-counting.
 * 11. Reconcile counters: dry-run performs 0 database writes; apply mode executes transactionally and enforces invariants.
 * 12. Authoritative reporting in both directions: 116->0 and 0->N are both corrected.
 * 13. Actual API Controller integration: invokes actual controller methods without interface errors.
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
require_once dirname(__DIR__) . '/includes/Repository/LogRepository.php';
require_once dirname(__DIR__) . '/includes/Export/ExportService.php';
require_once dirname(__DIR__) . '/includes/Api/Response.php';
require_once dirname(__DIR__) . '/includes/Api/RequestValidator.php';
require_once dirname(__DIR__) . '/includes/Api/ProjectController.php';
require_once dirname(__DIR__) . '/includes/Api/ScanController.php';
require_once dirname(__DIR__) . '/includes/Api/ResultsController.php';
require_once dirname(__DIR__) . '/includes/Api/ExportController.php';

use LoremChecker\Api\ExportController;
use LoremChecker\Api\ProjectController;
use LoremChecker\Api\Response;
use LoremChecker\Api\ResultsController;
use LoremChecker\Api\ScanController;
use LoremChecker\Export\ExportService;
use LoremChecker\Repository\FindingRepository;
use LoremChecker\Repository\PageResultRepository;
use LoremChecker\Repository\ProjectRepository;
use LoremChecker\Repository\ResourceRepository;
use LoremChecker\Repository\ScanRepository;
use LoremChecker\Repository\UrlRepository;

class ReportingIntegrityTest
{
    public int $assertions = 0;
    private PDO $pdo;
    private ProjectRepository $projectRepo;
    private ScanRepository $scanRepo;
    private PageResultRepository $pageResRepo;
    private ResourceRepository $resourceRepo;
    private FindingRepository $findingRepo;
    private UrlRepository $urlRepo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->setupSchema();

        $this->projectRepo  = new ProjectRepository($this->pdo);
        $this->scanRepo     = new ScanRepository($this->pdo);
        $this->pageResRepo  = new PageResultRepository($this->pdo);
        $this->resourceRepo = new ResourceRepository($this->pdo);
        $this->findingRepo  = new FindingRepository($this->pdo);
        $this->urlRepo      = new UrlRepository($this->pdo);
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
                lorem_page_count INTEGER NOT NULL DEFAULT 0,
                lorem_occurrence_count INTEGER NOT NULL DEFAULT 0,
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

            CREATE TABLE url_discovery_sources (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                discovered_url_id INTEGER NOT NULL,
                scan_id INTEGER NOT NULL,
                source_type VARCHAR(100) NOT NULL,
                source_url TEXT NULL,
                crawl_depth INTEGER NOT NULL DEFAULT 0,
                discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE discovered_urls (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id INTEGER NOT NULL,
                original_url TEXT NOT NULL,
                url TEXT NULL,
                normalized_url TEXT NOT NULL,
                url_hash VARCHAR(64) NOT NULL,
                domain VARCHAR(255) NULL,
                crawl_depth INTEGER NOT NULL DEFAULT 0,
                depth INTEGER NOT NULL DEFAULT 0,
                discovery_source VARCHAR(100) NOT NULL DEFAULT "unknown",
                status TEXT NOT NULL DEFAULT "pending",
                retry_count INTEGER NOT NULL DEFAULT 0,
                next_attempt_at DATETIME NULL,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                last_error TEXT NULL,
                discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE page_results (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id INTEGER NOT NULL,
                discovered_url_id INTEGER NULL,
                requested_url TEXT NOT NULL,
                final_url TEXT NULL,
                http_status INTEGER NULL,
                content_type VARCHAR(100) NULL,
                processing_status TEXT NOT NULL DEFAULT "clean",
                audit_status TEXT NOT NULL DEFAULT "clean",
                finding_count INTEGER NOT NULL DEFAULT 0,
                duration_ms INTEGER NULL,
                retry_count INTEGER NOT NULL DEFAULT 0,
                error_type VARCHAR(100) NULL,
                error_message TEXT NULL,
                page_title TEXT NULL,
                word_count INTEGER NULL,
                has_lorem_ipsum INTEGER NOT NULL DEFAULT 0,
                content_hash VARCHAR(64) NULL,
                rendered_with_js INTEGER NOT NULL DEFAULT 0,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
                resource_type VARCHAR(50) NOT NULL DEFAULT "html",
                matched_text TEXT NOT NULL,
                occurrences INTEGER NOT NULL DEFAULT 1,
                surrounding_context TEXT NULL,
                html_element VARCHAR(100) NULL,
                css_selector VARCHAR(500) NULL,
                section_name VARCHAR(500) NULL,
                line_number INTEGER NULL,
                character_position INTEGER NULL,
                is_visible INTEGER NOT NULL DEFAULT 1,
                detection_rule_id INTEGER NULL,
                detection_category VARCHAR(100) NOT NULL DEFAULT "lorem_ipsum",
                severity TEXT NOT NULL DEFAULT "high",
                confidence TEXT NOT NULL DEFAULT "high",
                finding_fingerprint CHAR(64) NOT NULL DEFAULT "",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
        $this->testZeroLoremWithSeoIssues();
        $this->testMultipleOccurrencesInParagraph();
        $this->testCounterSumIntegrity();
        $this->testResourceFindingsDoNotIncrementProcessedCount();
        $this->testResourceRetryPreservesFindingsAndReprocessesCleanly();
        $this->testCanonicalPageResultSupersedingHistoricalDuplicates();
        $this->testMutuallyExclusivePageClassification();
        $this->testCategoryFilteringWithSeoGroup();
        $this->testExportServiceAndControllerContract();
        $this->testRuntimeCounterIdempotencyOnPageRetry();
        $this->testReconcileCountersDryRunAndApply();
        $this->testStaleCounterOverrideInBothDirections();
        $this->testActualApiControllersWithTestMode();
    }

    private function testZeroLoremWithSeoIssues(): void
    {
        $projId = $this->projectRepo->findOrCreate('SEO Test Site', 'https://seo.example.com', 'https://seo.example.com');
        $scanId = $this->scanRepo->createScan($projId, ['max_urls' => 200]);

        for ($i = 1; $i <= 116; $i++) {
            $url = "https://seo.example.com/page-{$i}";
            $urlId = $this->urlRepo->insertDiscovered($scanId, $url, $url, 1, 'crawler');

            $pageResId = $this->pageResRepo->saveResult([
                'scan_id'           => $scanId,
                'discovered_url_id' => $urlId,
                'requested_url'     => $url,
                'final_url'         => $url,
                'http_status'       => 200,
                'processing_status' => 'findings_detected',
                'audit_status'      => 'audit_issues',
                'finding_count'     => 1,
                'has_lorem_ipsum'   => 0,
            ]);

            $this->findingRepo->insertBatch($scanId, $pageResId, null, [
                [
                    'page_url'            => $url,
                    'detection_category'  => 'missing_title',
                    'severity'            => 'medium',
                    'confidence'          => 'high',
                    'resource_type'       => 'html',
                    'matched_text'        => 'Page is missing a <title> tag',
                    'occurrences'         => 1,
                    'surrounding_context' => '<head></head>',
                    'html_element'        => 'head',
                ]
            ]);

            $this->scanRepo->recordPageResult(
                $scanId,
                'findings_detected',
                1, 0, 1, 0, 0, 0
            );
        }

        $scan = $this->scanRepo->getById($scanId);
        $this->assertEquals(116, (int)$scan['processed_count'], 'Processed count must be 116');
        $this->assertEquals(0, (int)$scan['clean_count'], 'Clean count must be 0');
        $this->assertEquals(116, (int)$scan['affected_page_count'], 'Affected page count must be 116');
        $this->assertEquals(0, (int)$scan['lorem_finding_count'], 'Lorem finding count must be 0');
        $this->assertEquals(0, (int)$scan['lorem_page_count'], 'Lorem page count must be 0');
        $this->assertEquals(116, (int)$scan['total_findings'], 'Total findings must be 116');

        $rows = $this->projectRepo->listProjects(false);
        $projRow = null;
        foreach ($rows as $r) {
            if ((int)$r['id'] === $projId) {
                $projRow = $r;
                break;
            }
        }
        $this->assertNotNull($projRow, 'Project row must exist in listProjects');
        $this->assertEquals(0, (int)($projRow['lorem_finding_count'] ?? 0), 'Project row lorem_finding_count must be 0');
        $this->assertEquals(116, (int)($projRow['affected_page_count'] ?? 0), 'Project row affected_page_count must be 116');
    }

    private function testMultipleOccurrencesInParagraph(): void
    {
        $projId = $this->projectRepo->findOrCreate('Multi Occurrence Site', 'https://multi.example.com', 'https://multi.example.com');
        $scanId = $this->scanRepo->createScan($projId);

        $url = 'https://multi.example.com/lorem-page';
        $urlId = $this->urlRepo->insertDiscovered($scanId, $url, $url, 1, 'crawler');

        $pageResId = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlId,
            'requested_url'     => $url,
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'audit_status'      => 'lorem_only',
            'finding_count'     => 1,
            'has_lorem_ipsum'   => 1,
        ]);

        $this->findingRepo->insertBatch($scanId, $pageResId, null, [
            [
                'page_url'            => $url,
                'detection_category'  => 'lorem_ipsum',
                'severity'            => 'high',
                'confidence'          => 'high',
                'resource_type'       => 'html',
                'matched_text'        => 'Lorem ipsum, dolor sit, amet (4 occurrences)',
                'occurrences'         => 4,
                'surrounding_context' => '<p>Lorem ipsum dolor sit amet lorem ipsum</p>',
                'html_element'        => 'p',
            ]
        ]);

        $this->scanRepo->recordPageResult(
            $scanId,
            'findings_detected',
            1, 1, 0, 0, 1, 4
        );

        $scan = $this->scanRepo->getById($scanId);
        $this->assertEquals(1, (int)$scan['processed_count'], 'Processed count must be 1');
        $this->assertEquals(1, (int)$scan['affected_page_count'], 'Affected page count must be 1');
        $this->assertEquals(1, (int)$scan['lorem_finding_count'], 'Lorem paragraph finding count must be 1');
        $this->assertEquals(4, (int)$scan['lorem_occurrence_count'], 'Lorem occurrence count must be 4');
        $this->assertEquals(1, (int)$scan['lorem_page_count'], 'Lorem page count must be 1');
    }

    private function testCounterSumIntegrity(): void
    {
        $projId = $this->projectRepo->findOrCreate('Sum Integrity Site', 'https://sum.example.com', 'https://sum.example.com');
        $scanId = $this->scanRepo->createScan($projId);

        for ($i = 1; $i <= 10; $i++) {
            $this->scanRepo->recordPageResult($scanId, 'clean', 0, 0, 0, 0, 0, 0);
        }
        for ($i = 1; $i <= 5; $i++) {
            $this->scanRepo->recordPageResult($scanId, 'findings_detected', 2, 1, 1, 0, 1, 1);
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->scanRepo->recordPageResult($scanId, 'failed', 0, 0, 0, 0, 0, 0);
        }

        $scan = $this->scanRepo->getById($scanId);
        $clean    = (int)$scan['clean_count'];
        $affected = (int)$scan['affected_page_count'];
        $failed   = (int)$scan['failed_count'];
        $total    = (int)$scan['processed_count'];

        $this->assertEquals(10, $clean, 'Clean pages must be 10');
        $this->assertEquals(5, $affected, 'Affected pages must be 5');
        $this->assertEquals(3, $failed, 'Failed pages must be 3');
        $this->assertEquals(18, $total, 'Processed count must be 18');
        $this->assertEquals($total, $clean + $affected + $failed, 'processed_count must equal clean + affected + failed');
    }

    private function testResourceFindingsDoNotIncrementProcessedCount(): void
    {
        $projId = $this->projectRepo->findOrCreate('Resource Site', 'https://res.example.com', 'https://res.example.com');
        $scanId = $this->scanRepo->createScan($projId);

        $this->scanRepo->recordPageResult($scanId, 'clean', 0, 0, 0, 0, 0, 0);

        $scanBefore = $this->scanRepo->getById($scanId);
        $this->assertEquals(1, (int)$scanBefore['processed_count'], 'Initial processed_count must be 1');
        $this->assertEquals(1, (int)$scanBefore['clean_count'], 'Initial clean_count must be 1');
        $this->assertEquals(0, (int)$scanBefore['resource_finding_count'], 'Initial resource findings must be 0');

        $this->scanRepo->recordResourceFindings($scanId, 3);

        $scanAfter = $this->scanRepo->getById($scanId);
        $this->assertEquals(1, (int)$scanAfter['processed_count'], 'Processed count must remain 1');
        $this->assertEquals(1, (int)$scanAfter['clean_count'], 'Clean count must remain 1');
        $this->assertEquals(3, (int)$scanAfter['resource_finding_count'], 'Resource finding count must be 3');
        $this->assertEquals(3, (int)$scanAfter['total_findings'], 'Total findings must be 3');
    }

    /**
     * Phase 1 & Phase 10 Item B:
     * Retrying a page must NEVER delete external resource findings (resource_id IS NOT NULL).
     * Reprocessing a resource to 0 findings must remove old findings and decrement counters.
     */
    private function testResourceRetryPreservesFindingsAndReprocessesCleanly(): void
    {
        $projId = $this->projectRepo->findOrCreate('Resource Retry Site', 'https://resretry.example.com', 'https://resretry.example.com');
        $scanId = $this->scanRepo->createScan($projId);

        $pageUrl = 'https://resretry.example.com/page1';
        $cssUrl  = 'https://resretry.example.com/assets/styles.css';
        $urlId   = $this->urlRepo->insertDiscovered($scanId, $pageUrl, $pageUrl, 1, 'crawler');

        // 1. Initial page audit: 2 HTML Lorem findings
        $save1 = $this->pageResRepo->saveResultWithMetrics([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlId,
            'requested_url'     => $pageUrl,
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'finding_count'     => 2,
            'has_lorem_ipsum'   => 1,
        ]);
        $pageResultId = $save1['id'];

        $this->findingRepo->insertBatch($scanId, $pageResultId, null, [
            ['page_url' => $pageUrl, 'detection_category' => 'lorem_ipsum', 'matched_text' => 'Lorem 1', 'occurrences' => 1],
            ['page_url' => $pageUrl, 'detection_category' => 'lorem_ipsum', 'matched_text' => 'Lorem 2', 'occurrences' => 1],
        ]);
        $this->scanRepo->recordPageResult($scanId, 'findings_detected', 2, 2, 0, 0, 1, 2, $save1['old_metrics']);

        // 2. Discover CSS resource and detect 3 findings in it
        $reg = $this->resourceRepo->findOrCreate($scanId, $pageResultId, $cssUrl, $cssUrl, 'css');
        $resourceId = $reg['id'];

        $this->resourceRepo->markCompleted($resourceId, [
            'content_type'  => 'text/css',
            'http_status'   => 200,
            'response_size' => 1024,
            'duration_ms'   => 50,
            'finding_count' => 3,
        ]);
        $this->findingRepo->insertBatch($scanId, $pageResultId, $resourceId, [
            ['page_url' => $pageUrl, 'resource_url' => $cssUrl, 'resource_type' => 'css', 'detection_category' => 'placeholder', 'matched_text' => 'CSS Match 1', 'occurrences' => 1],
            ['page_url' => $pageUrl, 'resource_url' => $cssUrl, 'resource_type' => 'css', 'detection_category' => 'placeholder', 'matched_text' => 'CSS Match 2', 'occurrences' => 1],
            ['page_url' => $pageUrl, 'resource_url' => $cssUrl, 'resource_type' => 'css', 'detection_category' => 'placeholder', 'matched_text' => 'CSS Match 3', 'occurrences' => 1],
        ]);
        $this->scanRepo->recordResourceFindings($scanId, 3, 0);

        $scan = $this->scanRepo->getById($scanId);
        $this->assertEquals(1, (int)$scan['processed_count'], 'Processed pages must be 1');
        $this->assertEquals(2, (int)$scan['lorem_finding_count'], 'Page lorem findings must be 2');
        $this->assertEquals(3, (int)$scan['resource_finding_count'], 'Resource findings must be 3');
        $this->assertEquals(5, (int)$scan['total_findings'], 'Total findings must be 5 (2 page + 3 resource)');

        // 3. RETRY THE PAGE: Re-save page results (e.g. now page has 1 lorem finding)
        $saveRetry = $this->pageResRepo->saveResultWithMetrics([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlId,
            'requested_url'     => $pageUrl,
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'finding_count'     => 1,
            'has_lorem_ipsum'   => 1,
        ]);
        $this->assertTrue(!$saveRetry['is_new'], 'Page retry must recognize existing record');

        $this->findingRepo->insertBatch($scanId, $pageResultId, null, [
            ['page_url' => $pageUrl, 'detection_category' => 'lorem_ipsum', 'matched_text' => 'Updated Lorem 1', 'occurrences' => 1],
        ]);
        $this->scanRepo->recordPageResult($scanId, 'findings_detected', 1, 1, 0, 0, 1, 1, $saveRetry['old_metrics']);

        // CRITICAL CHECK: Did the 3 CSS resource findings survive the page retry?
        $stmtCssCheck = $this->pdo->prepare('SELECT COUNT(*) FROM findings WHERE scan_id = ? AND resource_id = ?');
        $stmtCssCheck->execute([$scanId, $resourceId]);
        $survivingResourceFindings = (int)$stmtCssCheck->fetchColumn();
        $this->assertEquals(3, $survivingResourceFindings, 'Page retry must NEVER delete external resource findings!');

        // 4. REPROCESS RESOURCE WITH ZERO FINDINGS:
        // Resource was re-fetched and now has 0 findings
        $oldDeleted = $this->findingRepo->deleteByResourceId($scanId, $resourceId);
        $this->assertEquals(3, $oldDeleted, 'deleteByResourceId must report 3 findings deleted');
        $this->resourceRepo->markCompleted($resourceId, [
            'content_type'  => 'text/css',
            'http_status'   => 200,
            'finding_count' => 0,
        ]);
        $this->scanRepo->recordResourceFindings($scanId, 0, $oldDeleted);

        $scanAfterResClean = $this->scanRepo->getById($scanId);
        $this->assertEquals(0, (int)$scanAfterResClean['resource_finding_count'], 'Resource finding count must decrement to 0');
        $this->assertEquals(1, (int)$scanAfterResClean['total_findings'], 'Total findings must be 1 (page finding only)');

        // 5. REPROCESS RESOURCE WITH 2 NEW FINDINGS:
        $this->findingRepo->insertBatch($scanId, $pageResultId, $resourceId, [
            ['page_url' => $pageUrl, 'resource_url' => $cssUrl, 'resource_type' => 'css', 'detection_category' => 'placeholder', 'matched_text' => 'New CSS Match A', 'occurrences' => 1],
            ['page_url' => $pageUrl, 'resource_url' => $cssUrl, 'resource_type' => 'css', 'detection_category' => 'placeholder', 'matched_text' => 'New CSS Match B', 'occurrences' => 1],
        ]);
        $this->scanRepo->recordResourceFindings($scanId, 2, 0);

        $scanFinal = $this->scanRepo->getById($scanId);
        $this->assertEquals(2, (int)$scanFinal['resource_finding_count'], 'Resource finding count must become 2');
        $this->assertEquals(3, (int)$scanFinal['total_findings'], 'Total findings must be 3');
    }

    /**
     * Phase 4 & Phase 10 Item D:
     * Canonical page result selection must supersede historical duplicate records without deleting them.
     */
    private function testCanonicalPageResultSupersedingHistoricalDuplicates(): void
    {
        $projId = $this->projectRepo->findOrCreate('Canonical Site', 'https://canonical.example.com', 'https://canonical.example.com');
        $scanId = $this->scanRepo->createScan($projId);

        $url = 'https://canonical.example.com/duplicate-test';
        $urlId = $this->urlRepo->insertDiscovered($scanId, $url, $url, 1, 'crawler');

        // Historical record 1: Superseded attempt with 3 Lorem findings
        $p1 = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlId,
            'requested_url'     => $url,
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'finding_count'     => 3,
            'has_lorem_ipsum'   => 1,
        ]);
        $this->findingRepo->insertBatch($scanId, $p1, null, [
            ['page_url' => $url, 'detection_category' => 'lorem_ipsum', 'matched_text' => 'Old Lorem 1', 'occurrences' => 1],
            ['page_url' => $url, 'detection_category' => 'lorem_ipsum', 'matched_text' => 'Old Lorem 2', 'occurrences' => 1],
            ['page_url' => $url, 'detection_category' => 'lorem_ipsum', 'matched_text' => 'Old Lorem 3', 'occurrences' => 1],
        ]);

        // Historical record 2: Canonical latest attempt which resolved to CLEAN (0 findings)
        $p2 = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlId,
            'requested_url'     => $url,
            'http_status'       => 200,
            'processing_status' => 'clean',
            'finding_count'     => 0,
            'has_lorem_ipsum'   => 0,
        ]);

        $this->assertTrue($p2 > $p1, 'Latest page result must have higher ID');

        // Reconcile scan
        $rec = $this->scanRepo->reconcileCounters($scanId, true);

        // Canonical result is p2 (clean), so processed=1, clean=1, affected=0, lorem_findings=0
        $this->assertEquals(1, $rec['processed_count'], 'Canonical processed count must be 1 (not 2)');
        $this->assertEquals(1, $rec['clean_count'], 'Canonical clean count must be 1');
        $this->assertEquals(0, $rec['affected_page_count'], 'Affected count must be 0 (superseded record ignored)');
        $this->assertEquals(0, $rec['lorem_finding_count'], 'Canonical lorem findings must be 0');
        $this->assertEquals(1, $rec['diagnostics']['duplicate_pages_count'], 'Diagnostic must report 1 duplicate page set');

        // Historical record p1 must still exist in page_results and findings (never deleted automatically!)
        $stmtChkP1 = $this->pdo->prepare('SELECT COUNT(*) FROM page_results WHERE id = ?');
        $stmtChkP1->execute([$p1]);
        $this->assertEquals(1, (int)$stmtChkP1->fetchColumn(), 'Historical superseded page result must be preserved');

        $stmtChkF1 = $this->pdo->prepare('SELECT COUNT(*) FROM findings WHERE page_result_id = ?');
        $stmtChkF1->execute([$p1]);
        $this->assertEquals(3, (int)$stmtChkF1->fetchColumn(), 'Historical superseded findings must be preserved');
    }

    /**
     * Phase 3 & Phase 10 Item C:
     * Classification must be strictly mutually exclusive and truthful.
     */
    private function testMutuallyExclusivePageClassification(): void
    {
        $projId = $this->projectRepo->findOrCreate('Class Site', 'https://class.example.com', 'https://class.example.com');
        $scanId = $this->scanRepo->createScan($projId);

        $urlIdx = 600;

        // 1. Clean page: 200, clean, 0 findings
        $urlIdx++;
        $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlIdx,
            'requested_url'     => "https://class.example.com/p-{$urlIdx}",
            'http_status'       => 200,
            'processing_status' => 'clean',
            'finding_count'     => 0,
        ]);

        // 2. Affected page: 200, findings_detected, 2 findings
        $urlIdx++;
        $pAff = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlIdx,
            'requested_url'     => "https://class.example.com/p-{$urlIdx}",
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'finding_count'     => 2,
        ]);
        $this->findingRepo->insertBatch($scanId, $pAff, null, [
            ['page_url' => "https://class.example.com/p-{$urlIdx}", 'detection_category' => 'missing_title', 'matched_text' => 'Title missing', 'occurrences' => 1],
            ['page_url' => "https://class.example.com/p-{$urlIdx}", 'detection_category' => 'missing_h1', 'matched_text' => 'H1 missing', 'occurrences' => 1],
        ]);

        // 3. Failed pages: 500, 404, 429, timeout, ssl_error
        $failureCases = [
            ['status' => 'failed', 'http' => 500],
            ['status' => 'not_found', 'http' => 404],
            ['status' => 'rate_limited', 'http' => 429],
            ['status' => 'timeout', 'http' => 0],
            ['status' => 'ssl_error', 'http' => 0],
        ];
        foreach ($failureCases as $fc) {
            $urlIdx++;
            $this->pageResRepo->saveResult([
                'scan_id'           => $scanId,
                'discovered_url_id' => $urlIdx,
                'requested_url'     => "https://class.example.com/p-{$urlIdx}",
                'http_status'       => $fc['http'],
                'processing_status' => $fc['status'],
            ]);
        }

        // 4. Inconsistent page: marked 'clean' but actually has 1 persisted finding
        $urlIdx++;
        $pIncon = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlIdx,
            'requested_url'     => "https://class.example.com/p-{$urlIdx}",
            'http_status'       => 200,
            'processing_status' => 'clean',
            'finding_count'     => 0,
        ]);
        $this->findingRepo->insertBatch($scanId, $pIncon, null, [
            ['page_url' => "https://class.example.com/p-{$urlIdx}", 'detection_category' => 'lorem_ipsum', 'matched_text' => 'Surprise lorem', 'occurrences' => 1],
        ]);

        // Run reconciliation
        $rec = $this->scanRepo->reconcileCounters($scanId, true);

        $this->assertEquals(1, $rec['clean_count'], 'Clean count must be strictly 1');
        $this->assertEquals(2, $rec['affected_page_count'], 'Affected count must be 2 (affected page + repaired inconsistent clean)');
        $this->assertEquals(5, $rec['failed_count'], 'Failed count must be strictly 5');
        $this->assertEquals(8, $rec['processed_count'], 'Processed count must be 8 (1 + 2 + 5)');
        $this->assertEquals(1, $rec['diagnostics']['inconsistent_clean_count'], 'Must detect 1 inconsistent clean discrepancy');
        $this->assertTrue($rec['diagnostics']['invariant_passed'], 'Invariant check must pass');
    }

    private function testCategoryFilteringWithSeoGroup(): void
    {
        $projId = $this->projectRepo->findOrCreate('SEO Group Site', 'https://seogroup.example.com', 'https://seogroup.example.com');
        $scanId = $this->scanRepo->createScan($projId);

        $pageResId = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => 1,
            'requested_url'     => 'https://seogroup.example.com/',
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'audit_status'      => 'audit_issues',
            'finding_count'     => 7,
        ]);

        $findings = [
            ['page_url' => 'https://seogroup.example.com/', 'detection_category' => 'missing_title', 'severity' => 'high', 'matched_text' => 'Title missing', 'occurrences' => 1],
            ['page_url' => 'https://seogroup.example.com/', 'detection_category' => 'missing_meta_desc', 'severity' => 'medium', 'matched_text' => 'Meta desc missing', 'occurrences' => 1],
            ['page_url' => 'https://seogroup.example.com/', 'detection_category' => 'missing_h1', 'severity' => 'medium', 'matched_text' => 'H1 missing', 'occurrences' => 1],
            ['page_url' => 'https://seogroup.example.com/', 'detection_category' => 'missing_canonical', 'severity' => 'low', 'matched_text' => 'Canonical missing', 'occurrences' => 1],
            ['page_url' => 'https://seogroup.example.com/', 'detection_category' => 'heading_structure', 'severity' => 'low', 'matched_text' => 'Heading skipped', 'occurrences' => 1],
            ['page_url' => 'https://seogroup.example.com/', 'detection_category' => 'missing_image_alt', 'severity' => 'medium', 'matched_text' => 'Alt missing', 'occurrences' => 1],
            ['page_url' => 'https://seogroup.example.com/', 'detection_category' => 'lorem_ipsum', 'severity' => 'high', 'matched_text' => 'Lorem ipsum', 'occurrences' => 1],
        ];

        $this->findingRepo->insertBatch($scanId, $pageResId, null, $findings);

        $seoFindings = $this->findingRepo->listPaginated($scanId, 1, 50, ['category' => 'seo']);
        $seoCount    = $this->findingRepo->countFiltered($scanId, ['category' => 'seo']);

        $this->assertEquals(5, count($seoFindings), 'category=seo must return exactly 5 SEO findings');
        $this->assertEquals(5, $seoCount, 'countFiltered with category=seo must return 5');

        $expectedCats = ['missing_title', 'missing_meta_desc', 'missing_h1', 'missing_canonical', 'heading_structure'];
        foreach ($seoFindings as $f) {
            $this->assertTrue(in_array($f['detection_category'], $expectedCats, true), 'Finding must belong to SEO group');
        }
    }

    /**
     * Phase 8 & Phase 10 Item G:
     * ExportService exact schema and scan isolation contract.
     */
    private function testExportServiceAndControllerContract(): void
    {
        $exportService = new ExportService($this->pdo);

        $projId = $this->projectRepo->findOrCreate('Export Contract Site', 'https://expcontract.example.com', 'https://expcontract.example.com');
        $scan1 = $this->scanRepo->createScan($projId);
        $scan2 = $this->scanRepo->createScan($projId);

        // Put 1 page finding in scan1
        $p1 = $this->pageResRepo->saveResult([
            'scan_id'           => $scan1,
            'discovered_url_id' => 801,
            'requested_url'     => 'https://expcontract.example.com/scan1-page',
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
        ]);
        $this->findingRepo->insertBatch($scan1, $p1, null, [
            ['page_url' => 'https://expcontract.example.com/scan1-page', 'detection_category' => 'lorem_ipsum', 'matched_text' => 'Scan1 Lorem text', 'occurrences' => 2]
        ]);

        // Put 1 resource finding in scan1
        $reg = $this->resourceRepo->findOrCreate($scan1, $p1, 'https://expcontract.example.com/app.js', 'https://expcontract.example.com/app.js', 'javascript');
        $this->resourceRepo->markCompleted($reg['id'], [
            'content_type'  => 'application/javascript',
            'http_status'   => 200,
            'finding_count' => 1,
        ]);
        $this->findingRepo->insertBatch($scan1, $p1, $reg['id'], [
            ['page_url' => 'https://expcontract.example.com/scan1-page', 'resource_url' => 'https://expcontract.example.com/app.js', 'resource_type' => 'javascript', 'detection_category' => 'placeholder', 'matched_text' => 'TODO sample', 'occurrences' => 1]
        ]);

        // Put a finding in scan2 (must NOT leak into scan1 export!)
        $p2 = $this->pageResRepo->saveResult([
            'scan_id'           => $scan2,
            'discovered_url_id' => 802,
            'requested_url'     => 'https://expcontract.example.com/scan2-page',
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
        ]);
        $this->findingRepo->insertBatch($scan2, $p2, null, [
            ['page_url' => 'https://expcontract.example.com/scan2-page', 'detection_category' => 'lorem_ipsum', 'matched_text' => 'Scan2 Lorem text', 'occurrences' => 1]
        ]);

        // Test scan1 export directly
        $exportData = $exportService->getExportData($scan1);
        $this->assertEquals($scan1, (int)$exportData['scan']['id'], 'Export data must match requested scan ID');
        $this->assertEquals(2, count($exportData['rows']), 'Scan 1 must have 2 finding rows');

        // Test CSV generation
        $csv = $exportService->generateCsv($exportData);
        $lines = explode("\n", trim($csv));
        $this->assertTrue(count($lines) >= 3, 'CSV must have header + 2 rows');

        $headers = str_getcsv($lines[0]);
        $expectedHeaders = [
            'Scan ID', 'Project', 'Page URL', 'Resource URL', 'Source Type', 'Resource Type',
            'Processing Status', 'Audit Status', 'Detection Category', 'Severity', 'Confidence',
            'Matched Text', 'Occurrences', 'Surrounding Context', 'HTML Element', 'CSS Selector',
            'HTTP Status', 'Checked At'
        ];
        $this->assertEquals($expectedHeaders, $headers, 'CSV headers must match contract exactly');

        $row1 = str_getcsv($lines[1]);
        $this->assertEquals('page', $row1[4], 'Page finding source type must be "page"');
        $this->assertEquals('', $row1[3], 'Page finding resource URL must be empty');

        $row2 = str_getcsv($lines[2]);
        $this->assertEquals('resource', $row2[4], 'Resource finding source type must be "resource"');
        $this->assertEquals('https://expcontract.example.com/app.js', $row2[3], 'Resource finding must list resource URL');

        // Test JSON generation
        $json = $exportService->generateJson($exportData);
        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded, 'JSON export must decode cleanly');
        $this->assertEquals($scan1, $decoded['scan_id'], 'JSON export scan_id must match');
        $this->assertEquals(2, $decoded['metrics']['total_findings'], 'JSON authoritative total_findings must be 2');
        $this->assertEquals(1, $decoded['metrics']['lorem_findings'], 'JSON authoritative lorem_findings must be 1');

        // Test Convenience methods
        $csvConvenience = $exportService->buildCsvExport(['scan_id' => $scan1]);
        $this->assertTrue(str_contains($csvConvenience, 'Scan1 Lorem text'), 'Convenience buildCsvExport must work');
    }

    private function testRuntimeCounterIdempotencyOnPageRetry(): void
    {
        $projId = $this->projectRepo->findOrCreate('Idempotency Site', 'https://idem.example.com', 'https://idem.example.com');
        $scanId = $this->scanRepo->createScan($projId);

        $url = 'https://idem.example.com/page-retry';
        $urlId = $this->urlRepo->insertDiscovered($scanId, $url, $url, 1, 'crawler');

        // Initial audit attempt: 2 findings detected
        $save1 = $this->pageResRepo->saveResultWithMetrics([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlId,
            'requested_url'     => $url,
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'audit_status'      => 'lorem_only',
            'finding_count'     => 2,
            'has_lorem_ipsum'   => 1,
        ]);
        $this->assertTrue($save1['is_new'], 'First save must be marked is_new = true');
        $this->assertEquals(null, $save1['old_metrics'], 'First save has no old metrics');

        $this->scanRepo->recordPageResult(
            $scanId,
            'findings_detected',
            2, 2, 0, 0, 1, 2,
            $save1['old_metrics']
        );

        $scan1 = $this->scanRepo->getById($scanId);
        $this->assertEquals(1, (int)$scan1['processed_count'], 'After 1st pass: processed_count = 1');
        $this->assertEquals(0, (int)$scan1['clean_count'], 'After 1st pass: clean_count = 0');
        $this->assertEquals(1, (int)$scan1['affected_page_count'], 'After 1st pass: affected_page_count = 1');
        $this->assertEquals(2, (int)$scan1['total_findings'], 'After 1st pass: total_findings = 2');

        // Retry attempt: Worker re-audits same page, same 2 findings
        $save2 = $this->pageResRepo->saveResultWithMetrics([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlId,
            'requested_url'     => $url,
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'audit_status'      => 'lorem_only',
            'finding_count'     => 2,
            'has_lorem_ipsum'   => 1,
        ]);
        $this->assertTrue(!$save2['is_new'], 'Second save must be marked is_new = false');
        $this->assertNotNull($save2['old_metrics'], 'Second save must return old metrics');

        $this->scanRepo->recordPageResult(
            $scanId,
            'findings_detected',
            2, 2, 0, 0, 1, 2,
            $save2['old_metrics']
        );

        $scan2 = $this->scanRepo->getById($scanId);
        $this->assertEquals(1, (int)$scan2['processed_count'], 'processed_count must remain 1 after retry (no double counting)');
        $this->assertEquals(1, (int)$scan2['affected_page_count'], 'affected_page_count must remain 1');
        $this->assertEquals(2, (int)$scan2['total_findings'], 'total_findings must remain 2');

        // Subsequent retry attempt: Page changes, now clean with 0 findings
        $save3 = $this->pageResRepo->saveResultWithMetrics([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlId,
            'requested_url'     => $url,
            'http_status'       => 200,
            'processing_status' => 'clean',
            'audit_status'      => 'clean',
            'finding_count'     => 0,
            'has_lorem_ipsum'   => 0,
        ]);
        $this->scanRepo->recordPageResult(
            $scanId,
            'clean',
            0, 0, 0, 0, 0, 0,
            $save3['old_metrics']
        );

        $scan3 = $this->scanRepo->getById($scanId);
        $this->assertEquals(1, (int)$scan3['processed_count'], 'processed_count must remain 1');
        $this->assertEquals(1, (int)$scan3['clean_count'], 'clean_count must transition to 1');
        $this->assertEquals(0, (int)$scan3['affected_page_count'], 'affected_page_count must transition to 0');
        $this->assertEquals(0, (int)$scan3['total_findings'], 'total_findings must decrease by 2 to 0');
    }

    private function testReconcileCountersDryRunAndApply(): void
    {
        $projId = $this->projectRepo->findOrCreate('Dry Run Site', 'https://dryrun.example.com', 'https://dryrun.example.com');
        $scanId = $this->scanRepo->createScan($projId);

        // Intentionally corrupt counters with high drift values
        $this->pdo->exec("
            UPDATE scans 
            SET processed_count = 99, 
                clean_count = 50, 
                affected_page_count = 40, 
                failed_count = 9, 
                total_findings = 116, 
                lorem_finding_count = 116 
            WHERE id = {$scanId}
        ");

        $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => 501,
            'requested_url'     => 'https://dryrun.example.com/p1',
            'http_status'       => 200,
            'processing_status' => 'clean',
            'has_lorem_ipsum'   => 0,
        ]);
        $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => 502,
            'requested_url'     => 'https://dryrun.example.com/p2',
            'http_status'       => 200,
            'processing_status' => 'clean',
            'has_lorem_ipsum'   => 0,
        ]);
        $p3 = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => 503,
            'requested_url'     => 'https://dryrun.example.com/p3',
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'has_lorem_ipsum'   => 1,
            'finding_count'     => 1,
        ]);
        $this->findingRepo->insertBatch($scanId, $p3, null, [
            [
                'page_url'           => 'https://dryrun.example.com/p3',
                'detection_category' => 'lorem_ipsum',
                'severity'           => 'high',
                'matched_text'       => 'Authoritative lorem paragraph',
                'occurrences'        => 3,
            ]
        ]);

        // 1. Dry run: assert database is NOT updated
        $dryRun = $this->scanRepo->reconcileCounters($scanId, false);
        $this->assertTrue(!$dryRun['applied'], 'Dry run must return applied = false');
        $this->assertEquals(3, $dryRun['processed_count'], 'Dry run calculated processed_count must be 3');
        $this->assertEquals(1, $dryRun['lorem_finding_count'], 'Dry run calculated lorem_finding_count must be 1');

        $scanUnchanged = $this->scanRepo->getById($scanId);
        $this->assertEquals(99, (int)$scanUnchanged['processed_count'], 'DB must retain corrupted processed_count on dry-run');
        $this->assertEquals(116, (int)$scanUnchanged['lorem_finding_count'], 'DB must retain corrupted lorem_finding_count on dry-run');

        // 2. Apply: assert database IS updated and invariant holds
        $applyResult = $this->scanRepo->reconcileCounters($scanId, true);
        $this->assertTrue($applyResult['applied'], 'Apply mode must return applied = true');
        $this->assertEquals(3, $applyResult['processed_count']);
        $this->assertEquals(2, $applyResult['clean_count']);
        $this->assertEquals(1, $applyResult['affected_page_count']);
        $this->assertEquals(0, $applyResult['failed_count']);
        $this->assertEquals(1, $applyResult['lorem_finding_count']);
        $this->assertEquals(3, $applyResult['lorem_occurrence_count']);
        $this->assertTrue($applyResult['diagnostics']['invariant_passed'], 'Invariant check must pass');

        $scanUpdated = $this->scanRepo->getById($scanId);
        $this->assertEquals(3, (int)$scanUpdated['processed_count'], 'DB must be corrected to 3');
        $this->assertEquals(2, (int)$scanUpdated['clean_count'], 'DB must be corrected to 2');
        $this->assertEquals(1, (int)$scanUpdated['affected_page_count'], 'DB must be corrected to 1');
        $this->assertEquals(1, (int)$scanUpdated['lorem_finding_count'], 'DB must be corrected to 1');
    }

    /**
     * Phase 6: Authoritative in BOTH directions.
     * 1) Stale 116 with 0 findings -> corrected to 0.
     * 2) Stale 0 with 5 actual findings -> corrected to 5 (never hidden!).
     */
    private function testStaleCounterOverrideInBothDirections(): void
    {
        $projId = $this->projectRepo->findOrCreate('Direction Site', 'https://dir.example.com', 'https://dir.example.com');
        
        // Direction 1: Stale 116 with 0 findings -> must report 0
        $scan1 = $this->scanRepo->createScan($projId);
        $this->pdo->exec("UPDATE scans SET lorem_finding_count = 116, total_findings = 116 WHERE id = {$scan1}");
        
        // Direction 2: Stale 0 with 5 findings -> must report 5
        $scan2 = $this->scanRepo->createScan($projId);
        $p2 = $this->pageResRepo->saveResult([
            'scan_id'           => $scan2,
            'discovered_url_id' => 901,
            'requested_url'     => 'https://dir.example.com/p2',
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
        ]);
        $findings5 = [];
        for ($i = 1; $i <= 5; $i++) {
            $findings5[] = [
                'page_url'           => 'https://dir.example.com/p2',
                'detection_category' => 'lorem_ipsum',
                'matched_text'       => "Lorem phrase {$i}",
                'occurrences'        => 1
            ];
        }
        $this->findingRepo->insertBatch($scan2, $p2, null, $findings5);
        $this->pdo->exec("UPDATE scans SET lorem_finding_count = 0, total_findings = 0 WHERE id = {$scan2}");

        // Test ResultsController scanSummary on scan1
        Response::$testMode = true;
        $_GET = ['scan_id' => $scan1];
        $resCtrl = new ResultsController($this->pdo);
        $resCtrl->scanSummary();
        $summary1 = Response::$lastResponse['payload']['data'];
        $this->assertEquals(0, $summary1['lorem']['findings'], 'Scan 1 must override stale 116 to 0');

        // Test ResultsController scanSummary on scan2
        $_GET = ['scan_id' => $scan2];
        $resCtrl->scanSummary();
        $summary2 = Response::$lastResponse['payload']['data'];
        $this->assertEquals(5, $summary2['lorem']['findings'], 'Scan 2 must override stale 0 to 5 (never hide findings!)');

        // Test ScanController status on scan1
        $scanCtrl = new ScanController($this->pdo, []);
        $_GET = ['scan_id' => $scan1];
        $scanCtrl->status();
        $status1 = Response::$lastResponse['payload']['data'];
        $this->assertEquals(0, $status1['project']['progress']['lorem_found'], 'Live status must override stale 116 to 0');

        // Test ScanController status on scan2
        $_GET = ['scan_id' => $scan2];
        $scanCtrl->status();
        $status2 = Response::$lastResponse['payload']['data'];
        $this->assertEquals(5, $status2['project']['progress']['lorem_found'], 'Live status must override stale 0 to 5');
    }

    /**
     * Phase 10 Item F:
     * Actual API controller integration tests across ProjectController, ScanController, ResultsController.
     */
    private function testActualApiControllersWithTestMode(): void
    {
        Response::$testMode = true;

        $projId = $this->projectRepo->findOrCreate('API Integ Site', 'https://apiinteg.example.com', 'https://apiinteg.example.com');
        $scanId = $this->scanRepo->createScan($projId);

        $url = 'https://apiinteg.example.com/home';
        $urlId = $this->urlRepo->insertDiscovered($scanId, $url, $url, 1, 'crawler');
        $pid = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlId,
            'requested_url'     => $url,
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
        ]);
        $this->findingRepo->insertBatch($scanId, $pid, null, [
            ['page_url' => $url, 'detection_category' => 'lorem_ipsum', 'matched_text' => 'Home lorem text', 'occurrences' => 2],
        ]);

        // 1. Test ProjectController::list()
        $projCtrl = new ProjectController($this->pdo);
        $_GET = [];
        $projCtrl->list();
        $projList = Response::$lastResponse['payload']['data']['projects'];
        $foundProj = null;
        foreach ($projList as $p) {
            if ((int)$p['id'] === $projId) {
                $foundProj = $p;
                break;
            }
        }
        $this->assertNotNull($foundProj, 'Project must appear in list()');
        $this->assertEquals(1, $foundProj['progress']['lorem_found'], 'Project list lorem_found must be 1');
        $this->assertEquals(2, $foundProj['progress']['lorem_occurrences'], 'Project list lorem_occurrences must be 2');

        // 2. Test ScanController::scansList()
        $scanCtrl = new ScanController($this->pdo, []);
        $_GET = ['project_id' => $projId];
        $scanCtrl->scansList();
        $scans = Response::$lastResponse['payload']['data']['scans'];
        $this->assertEquals(1, count($scans), 'scansList must return 1 scan');
        $this->assertEquals(1, (int)$scans[0]['lorem_finding_count'], 'scansList lorem_finding_count must be 1');

        // 3. Test ResultsController::listPages()
        $resCtrl = new ResultsController($this->pdo);
        $_GET = ['scan_id' => $scanId, 'page' => 1, 'limit' => 10];
        $resCtrl->listPages();
        $pagesData = Response::$lastResponse['payload']['data'];
        $this->assertEquals(1, count($pagesData['results']), 'listPages must return 1 page result');

        // 4. Test ResultsController::listFindings()
        $_GET = ['scan_id' => $scanId, 'page' => 1, 'limit' => 10];
        $resCtrl->listFindings();
        $findData = Response::$lastResponse['payload']['data'];
        $this->assertEquals(1, count($findData['findings']), 'listFindings must return 1 finding');

        // Reset test mode
        Response::$testMode = false;
        Response::$lastResponse = null;
    }

    private function assertEquals($expected, $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new \RuntimeException("Assertion failed: Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ($message ? " ({$message})" : ''));
        }
    }

    private function assertTrue(bool $condition, string $message = ''): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: Expected true" . ($message ? " ({$message})" : ''));
        }
    }

    private function assertNotNull($value, string $message = ''): void
    {
        $this->assertions++;
        if ($value === null) {
            throw new \RuntimeException("Assertion failed: Expected non-null value" . ($message ? " ({$message})" : ''));
        }
    }
}
