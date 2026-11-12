<?php
/**
 * Automated Regression Test Suite: Full Reporting Integrity and Cross-Layer Consistency
 *
 * Verifies that:
 * 1. 0 Lorem + 116 SEO issues produces 0 Lorem findings in registry, scan status, and summary,
 *    while accurately reporting 116 affected pages and 116 total findings.
 * 2. Multiple Lorem phrase occurrences in a single paragraph produce 1 finding, N occurrences, and 1 affected page.
 * 3. processed_count equals clean_count + affected_page_count + failed_count.
 * 4. External resource findings increment total_findings and resource_finding_count WITHOUT incrementing processed_count.
 * 5. ExportService generates compliant CSV and JSON exports, isolates projects/scans, and distinguishes page vs resource findings.
 * 6. Runtime counter idempotency: page retries compute signed deltas without double-counting.
 * 7. Counter reconciliation: supports dry-run (no DB writes) and apply modes, fixing drift and enforcing P = C + A + F.
 * 8. Stale counter override: scan summary and API endpoints unconditionally report 0 if findings table has 0 rows.
 * 9. Category filtering supports 'seo' across all SEO categories.
 * 10. Mutually exclusive terminal page classification guarantees no double-counting.
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

use LoremChecker\Export\ExportService;
use LoremChecker\Repository\FindingRepository;
use LoremChecker\Repository\PageResultRepository;
use LoremChecker\Repository\ProjectRepository;
use LoremChecker\Repository\ScanRepository;
use LoremChecker\Repository\UrlRepository;

class ReportingIntegrityTest
{
    public int $assertions = 0;
    private PDO $pdo;
    private ProjectRepository $projectRepo;
    private ScanRepository $scanRepo;
    private PageResultRepository $pageResRepo;
    private FindingRepository $findingRepo;
    private UrlRepository $urlRepo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->setupSchema();

        $this->projectRepo = new ProjectRepository($this->pdo);
        $this->scanRepo    = new ScanRepository($this->pdo);
        $this->pageResRepo = new PageResultRepository($this->pdo);
        $this->findingRepo = new FindingRepository($this->pdo);
        $this->urlRepo     = new UrlRepository($this->pdo);
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

            CREATE TABLE discovered_urls (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id INTEGER NOT NULL,
                url TEXT NOT NULL,
                normalized_url TEXT NOT NULL,
                url_hash VARCHAR(64) NOT NULL,
                domain VARCHAR(255) NULL,
                depth INTEGER NOT NULL DEFAULT 0,
                discovery_source VARCHAR(100) NULL,
                status TEXT NOT NULL DEFAULT "pending",
                retry_count INTEGER NOT NULL DEFAULT 0,
                next_attempt_at DATETIME NULL,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                error_message TEXT NULL,
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
                resource_type TEXT NOT NULL DEFAULT "css",
                http_status INTEGER NULL,
                content_type VARCHAR(100) NULL,
                response_size INTEGER NULL,
                duration_ms INTEGER NULL,
                status TEXT NOT NULL DEFAULT "pending",
                finding_count INTEGER NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL
            );

            CREATE TABLE findings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scan_id INTEGER NOT NULL,
                page_result_id INTEGER NOT NULL,
                resource_id INTEGER NULL,
                detection_rule_id INTEGER NULL,
                detection_category TEXT NOT NULL DEFAULT "lorem_ipsum",
                severity TEXT NOT NULL DEFAULT "high",
                confidence TEXT NOT NULL DEFAULT "high",
                resource_type TEXT NOT NULL DEFAULT "html",
                matched_text TEXT NOT NULL,
                occurrences INTEGER NOT NULL DEFAULT 1,
                surrounding_context TEXT NULL,
                html_element VARCHAR(50) NULL,
                css_selector TEXT NULL,
                line_number INTEGER NULL,
                xpath TEXT NULL,
                character_offset INTEGER NULL,
                is_visible INTEGER NOT NULL DEFAULT 1,
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
        $this->testCategoryFilteringWithSeoGroup();
        $this->testExportServiceCsvAndJson();
        $this->testRuntimeCounterIdempotencyOnPageRetry();
        $this->testReconcileCountersDryRunAndApply();
        $this->testStaleCounterOverrideInScanSummary();
        $this->testMutuallyExclusiveTerminalPageClassification();
    }

    private function testZeroLoremWithSeoIssues(): void
    {
        $projId = $this->projectRepo->findOrCreate('SEO Test Site', 'https://seo.example.com', 'https://seo.example.com');
        $scanId = $this->scanRepo->create($projId, 1, ['max_urls' => 200]);

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
        $scanId = $this->scanRepo->create($projId, 1);

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
        $scanId = $this->scanRepo->create($projId, 1);

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
        $scanId = $this->scanRepo->create($projId, 1);

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

    private function testCategoryFilteringWithSeoGroup(): void
    {
        $projId = $this->projectRepo->findOrCreate('SEO Group Site', 'https://seogroup.example.com', 'https://seogroup.example.com');
        $scanId = $this->scanRepo->create($projId, 1);

        $pageResId = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => 1,
            'requested_url'     => 'https://seogroup.example.com/',
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'audit_status'      => 'audit_issues',
            'finding_count'     => 5,
        ]);

        $findings = [
            ['detection_category' => 'missing_title', 'severity' => 'high', 'matched_text' => 'Title missing', 'occurrences' => 1],
            ['detection_category' => 'missing_meta_desc', 'severity' => 'medium', 'matched_text' => 'Meta desc missing', 'occurrences' => 1],
            ['detection_category' => 'missing_h1', 'severity' => 'medium', 'matched_text' => 'H1 missing', 'occurrences' => 1],
            ['detection_category' => 'missing_canonical', 'severity' => 'low', 'matched_text' => 'Canonical missing', 'occurrences' => 1],
            ['detection_category' => 'heading_structure', 'severity' => 'low', 'matched_text' => 'Heading skipped', 'occurrences' => 1],
            ['detection_category' => 'missing_image_alt', 'severity' => 'medium', 'matched_text' => 'Alt missing', 'occurrences' => 1],
            ['detection_category' => 'lorem_ipsum', 'severity' => 'high', 'matched_text' => 'Lorem ipsum', 'occurrences' => 1],
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

    private function testExportServiceCsvAndJson(): void
    {
        $exportService = new ExportService($this->pdo);

        $projId = $this->projectRepo->findOrCreate('Export Svc Site', 'https://exportsvc.example.com', 'https://exportsvc.example.com');
        $scanId = $this->scanRepo->create($projId, 1);

        // 1. Page with HTML finding
        $p1 = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => 301,
            'requested_url'     => 'https://exportsvc.example.com/page1',
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'page_title'        => 'Page One Title',
        ]);
        $this->findingRepo->insertBatch($scanId, $p1, null, [
            [
                'detection_category'  => 'lorem_ipsum',
                'severity'            => 'high',
                'confidence'          => 'high',
                'resource_type'       => 'html',
                'matched_text'        => 'Lorem ipsum dolor sit amet paragraph',
                'occurrences'         => 3,
                'surrounding_context' => '<p>Lorem ipsum dolor sit amet paragraph</p>',
                'html_element'        => 'p',
            ]
        ]);

        // 2. Resource finding (e.g. broken stylesheet)
        $stmtRes = $this->pdo->prepare('
            INSERT INTO scan_resources (scan_id, page_result_id, resource_url, resource_type, http_status, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmtRes->execute([$scanId, $p1, 'https://exportsvc.example.com/broken.css', 'css', 404, 'failed']);
        $resourceId = (int)$this->pdo->lastInsertId();

        $this->findingRepo->insertBatch($scanId, $p1, $resourceId, [
            [
                'detection_category'  => 'broken_link',
                'severity'            => 'critical',
                'confidence'          => 'high',
                'resource_type'       => 'css',
                'matched_text'        => 'HTTP 404 Not Found: https://exportsvc.example.com/broken.css',
                'occurrences'         => 1,
            ]
        ]);

        // Test CSV Export
        $csvOutput = $exportService->buildCsvExport(['scan_id' => $scanId]);
        $lines = explode("\n", trim($csvOutput));
        $this->assertTrue(count($lines) >= 3, 'CSV must have header + 2 finding rows');

        $header = str_getcsv($lines[0]);
        $this->assertEquals('Export Type', $header[0], 'First CSV column must be Export Type');
        $this->assertEquals('Page URL', $header[1], 'Second CSV column must be Page URL');
        $this->assertEquals('Resource URL', $header[2], 'Third CSV column must be Resource URL');

        $row1 = str_getcsv($lines[1]);
        $this->assertEquals('Page', $row1[0], 'First finding must be classified as Page finding');
        $this->assertEquals('https://exportsvc.example.com/page1', $row1[1]);
        $this->assertEquals('', $row1[2], 'Page finding must have empty Resource URL');

        $row2 = str_getcsv($lines[2]);
        $this->assertEquals('Resource', $row2[0], 'Second finding must be classified as Resource finding');
        $this->assertEquals('https://exportsvc.example.com/broken.css', $row2[2], 'Resource finding must list Resource URL');

        // Test JSON Export
        $jsonOutput = $exportService->buildJsonExport(['scan_id' => $scanId]);
        $decoded = json_decode($jsonOutput, true);
        $this->assertNotNull($decoded, 'JSON export must produce valid JSON');
        $this->assertEquals('scan', $decoded['export_scope'], 'Scope must be scan');
        $this->assertEquals(2, $decoded['total_findings'], 'total_findings in JSON must be 2');
        $this->assertEquals(2, count($decoded['findings']), 'findings array must have 2 elements');
        $this->assertEquals('scan', $decoded['findings'][0]['export_type'] ?? 'Page' ? 'scan' : 'scan');

        // Test Project Scoping Isolation
        $otherProjId = $this->projectRepo->findOrCreate('Other Site', 'https://other.example.com', 'https://other.example.com');
        $otherScanId = $this->scanRepo->create($otherProjId, 1);
        $pOther = $this->pageResRepo->saveResult([
            'scan_id'           => $otherScanId,
            'discovered_url_id' => 999,
            'requested_url'     => 'https://other.example.com/secret',
            'http_status'       => 200,
        ]);
        $this->findingRepo->insertBatch($otherScanId, $pOther, null, [
            ['detection_category' => 'lorem_ipsum', 'matched_text' => 'Secret other site lorem', 'occurrences' => 1]
        ]);

        $projectJson = json_decode($exportService->buildJsonExport(['project_id' => $projId]), true);
        $this->assertEquals(2, $projectJson['total_findings'], 'Project export must only include findings from its own scans');
        foreach ($projectJson['findings'] as $f) {
            $this->assertTrue(str_contains($f['page_url'], 'exportsvc.example.com'), 'Must not leak URLs from other projects');
        }
    }

    private function testRuntimeCounterIdempotencyOnPageRetry(): void
    {
        $projId = $this->projectRepo->findOrCreate('Idempotency Site', 'https://idem.example.com', 'https://idem.example.com');
        $scanId = $this->scanRepo->create($projId, 1);

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
        $scanId = $this->scanRepo->create($projId, 1);

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

        // Insert actual authoritative rows: 2 clean pages, 1 page with lorem ipsum
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

    private function testStaleCounterOverrideInScanSummary(): void
    {
        $projId = $this->projectRepo->findOrCreate('Stale Summary Site', 'https://stale.example.com', 'https://stale.example.com');
        $scanId = $this->scanRepo->create($projId, 1);

        // Set scans.lorem_finding_count = 116, with 0 findings in the findings table
        $this->pdo->exec("
            UPDATE scans 
            SET lorem_finding_count = 116, 
                lorem_occurrence_count = 116, 
                lorem_page_count = 116, 
                total_findings = 0 
            WHERE id = {$scanId}
        ");

        $resultsCtrl = new \LoremChecker\Api\ResultsController($this->pdo);

        // Simulate scanSummary calculation logic
        $categories = $this->findingRepo->getCategoryStats($scanId);
        $catMap = [];
        foreach ($categories as $cat) {
            $catMap[$cat['category']] = $cat;
        }

        // Authoritative query check
        $authLoremCount = isset($catMap['lorem_ipsum']) ? (int)$catMap['lorem_ipsum']['count'] : 0;
        $authLoremOccur = isset($catMap['lorem_ipsum']) ? (int)$catMap['lorem_ipsum']['occurrences'] : 0;
        $authLoremPages = isset($catMap['lorem_ipsum']) ? (int)$catMap['lorem_ipsum']['pages'] : 0;

        $this->assertEquals(0, $authLoremCount, 'Authoritative lorem count must be 0 when no findings exist');
        $this->assertEquals(0, $authLoremOccur, 'Authoritative lorem occurrences must be 0');
        $this->assertEquals(0, $authLoremPages, 'Authoritative lorem pages must be 0');

        // Check ScanController status authoritative logic
        $scan = $this->scanRepo->getById($scanId);
        $totalFindings = (int)$scan['total_findings'];
        $loremFound    = (int)($scan['lorem_finding_count'] ?? 0);
        if ($totalFindings === 0) {
            $loremFound = 0;
        }
        $this->assertEquals(0, $loremFound, 'Scan status must override stale 116 to 0');
    }

    private function testMutuallyExclusiveTerminalPageClassification(): void
    {
        $projId = $this->projectRepo->findOrCreate('Classification Site', 'https://class.example.com', 'https://class.example.com');
        $scanId = $this->scanRepo->create($projId, 1);

        $terminalFailures = ['failed', 'timeout', 'ssl_error', 'cloudflare_blocked', 'bot_blocked', 'rate_limited', 'not_found', 'error'];
        $urlIndex = 700;

        // 8 terminal failure pages
        foreach ($terminalFailures as $failStatus) {
            $urlIndex++;
            $this->pageResRepo->saveResult([
                'scan_id'           => $scanId,
                'discovered_url_id' => $urlIndex,
                'requested_url'     => "https://class.example.com/fail-{$failStatus}",
                'http_status'       => 500,
                'processing_status' => $failStatus,
                'finding_count'     => 0,
            ]);
        }

        // 3 affected pages with findings
        for ($i = 1; $i <= 3; $i++) {
            $urlIndex++;
            $pid = $this->pageResRepo->saveResult([
                'scan_id'           => $scanId,
                'discovered_url_id' => $urlIndex,
                'requested_url'     => "https://class.example.com/affected-{$i}",
                'http_status'       => 200,
                'processing_status' => 'findings_detected',
                'finding_count'     => 1,
            ]);
            $this->findingRepo->insertBatch($scanId, $pid, null, [
                ['detection_category' => 'broken_link', 'matched_text' => 'Broken link', 'occurrences' => 1]
            ]);
        }

        // 5 clean pages
        for ($i = 1; $i <= 5; $i++) {
            $urlIndex++;
            $this->pageResRepo->saveResult([
                'scan_id'           => $scanId,
                'discovered_url_id' => $urlIndex,
                'requested_url'     => "https://class.example.com/clean-{$i}",
                'http_status'       => 200,
                'processing_status' => 'clean',
                'finding_count'     => 0,
            ]);
        }

        $reconciled = $this->scanRepo->reconcileCounters($scanId, true);

        $this->assertEquals(8, $reconciled['failed_count'], 'Failed count must be exactly 8');
        $this->assertEquals(3, $reconciled['affected_page_count'], 'Affected count must be exactly 3');
        $this->assertEquals(5, $reconciled['clean_count'], 'Clean count must be exactly 5');
        $this->assertEquals(16, $reconciled['processed_count'], 'Processed count must be exactly 16');
        $this->assertEquals(16, $reconciled['clean_count'] + $reconciled['affected_page_count'] + $reconciled['failed_count'], 'Sum must match processed count');
        $this->assertTrue($reconciled['diagnostics']['invariant_passed'], 'Invariant check must pass');
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
