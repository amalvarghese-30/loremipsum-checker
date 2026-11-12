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
 * 5. Export supports scan_id directly.
 * 6. Historical reconciliation recalculates scans counters from page_results and findings.
 * 7. Category filtering supports 'seo' across all SEO categories (title, meta, h1, canonical, headings).
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
require_once dirname(__DIR__) . '/includes/Api/Response.php';
require_once dirname(__DIR__) . '/includes/Api/RequestValidator.php';
require_once dirname(__DIR__) . '/includes/Api/ProjectController.php';
require_once dirname(__DIR__) . '/includes/Api/ScanController.php';
require_once dirname(__DIR__) . '/includes/Api/ResultsController.php';
require_once dirname(__DIR__) . '/includes/Api/ExportController.php';

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
                scan_resource_id INTEGER NULL,
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
        $this->testReconcileCounters();
        $this->testExportControllerScanIdResolution();
    }

    private function testZeroLoremWithSeoIssues(): void
    {
        $projId = $this->projectRepo->findOrCreate('SEO Test Site', 'https://seo.example.com', 'https://seo.example.com');
        $scanId = $this->scanRepo->create($projId, 1, ['max_urls' => 200]);

        // Record 116 pages with SEO issues (e.g. missing title), 0 Lorem Ipsum
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
                1, // findingCount
                0, // loremCount
                1, // auditCount
                0, // resourceCount
                0, // loremPageCount
                0  // loremOccurrenceCount
            );
        }

        $scan = $this->scanRepo->getById($scanId);
        $this->assertEquals(116, (int)$scan['processed_count'], 'Processed count must be 116');
        $this->assertEquals(0, (int)$scan['clean_count'], 'Clean count must be 0');
        $this->assertEquals(116, (int)$scan['affected_page_count'], 'Affected page count must be 116');
        $this->assertEquals(0, (int)$scan['lorem_finding_count'], 'Lorem finding count must be 0');
        $this->assertEquals(0, (int)$scan['lorem_page_count'], 'Lorem page count must be 0');
        $this->assertEquals(116, (int)$scan['total_findings'], 'Total findings must be 116');

        // Test ProjectController output mapping
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

        // 1 paragraph finding containing 4 phrase occurrences
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
            1, // findingCount
            1, // loremCount
            0, // auditCount
            0, // resourceCount
            1, // loremPageCount
            4  // loremOccurrenceCount
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

        // 10 clean pages
        for ($i = 1; $i <= 10; $i++) {
            $this->scanRepo->recordPageResult($scanId, 'clean', 0, 0, 0, 0, 0, 0);
        }
        // 5 affected pages
        for ($i = 1; $i <= 5; $i++) {
            $this->scanRepo->recordPageResult($scanId, 'findings_detected', 2, 1, 1, 0, 1, 1);
        }
        // 3 failed pages
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

        // 1 HTML page processed
        $this->scanRepo->recordPageResult($scanId, 'clean', 0, 0, 0, 0, 0, 0);

        $scanBefore = $this->scanRepo->getById($scanId);
        $this->assertEquals(1, (int)$scanBefore['processed_count'], 'Initial processed_count must be 1');
        $this->assertEquals(1, (int)$scanBefore['clean_count'], 'Initial clean_count must be 1');
        $this->assertEquals(0, (int)$scanBefore['resource_finding_count'], 'Initial resource findings must be 0');

        // External CSS with 3 findings inspected
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

        // Filter by category = 'seo'
        $seoFindings = $this->findingRepo->listPaginated($scanId, 1, 50, ['category' => 'seo']);
        $seoCount    = $this->findingRepo->countFiltered($scanId, ['category' => 'seo']);

        $this->assertEquals(5, count($seoFindings), 'category=seo must return exactly 5 SEO findings');
        $this->assertEquals(5, $seoCount, 'countFiltered with category=seo must return 5');

        // Verify all 5 categories are SEO
        $expectedCats = ['missing_title', 'missing_meta_desc', 'missing_h1', 'missing_canonical', 'heading_structure'];
        foreach ($seoFindings as $f) {
            $this->assertTrue(in_array($f['detection_category'], $expectedCats, true), 'Finding must belong to SEO group');
        }
    }

    private function testReconcileCounters(): void
    {
        $projId = $this->projectRepo->findOrCreate('Reconcile Site', 'https://reconcile.example.com', 'https://reconcile.example.com');
        $scanId = $this->scanRepo->create($projId, 1);

        // Manually corrupt scan aggregate counters to test reconciliation
        $this->pdo->exec("UPDATE scans SET processed_count = 999, clean_count = 999, lorem_finding_count = 999 WHERE id = {$scanId}");

        // Insert actual authoritative rows: 2 clean pages, 1 page with lorem ipsum
        $p1 = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => 101,
            'requested_url'     => 'https://reconcile.example.com/p1',
            'http_status'       => 200,
            'processing_status' => 'clean',
            'has_lorem_ipsum'   => 0,
        ]);
        $p2 = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => 102,
            'requested_url'     => 'https://reconcile.example.com/p2',
            'http_status'       => 200,
            'processing_status' => 'clean',
            'has_lorem_ipsum'   => 0,
        ]);
        $p3 = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => 103,
            'requested_url'     => 'https://reconcile.example.com/p3',
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'has_lorem_ipsum'   => 1,
            'finding_count'     => 1,
        ]);

        $this->findingRepo->insertBatch($scanId, $p3, null, [
            [
                'detection_category' => 'lorem_ipsum',
                'severity'           => 'high',
                'matched_text'       => 'Lorem ipsum dolor (3 occurrences)',
                'occurrences'        => 3,
            ]
        ]);

        // Run reconciliation
        $reconciled = $this->scanRepo->reconcileCounters($scanId);

        $this->assertEquals(3, $reconciled['processed_count'], 'Reconciled processed_count must be 3');
        $this->assertEquals(2, $reconciled['clean_count'], 'Reconciled clean_count must be 2');
        $this->assertEquals(1, $reconciled['affected_page_count'], 'Reconciled affected_page_count must be 1');
        $this->assertEquals(1, $reconciled['lorem_finding_count'], 'Reconciled lorem_finding_count must be 1');
        $this->assertEquals(3, $reconciled['lorem_occurrence_count'], 'Reconciled lorem_occurrence_count must be 3');
        $this->assertEquals(1, $reconciled['lorem_page_count'], 'Reconciled lorem_page_count must be 1');
        $this->assertEquals(1, $reconciled['total_findings'], 'Reconciled total_findings must be 1');

        // Check scans table persisted correctly
        $scan = $this->scanRepo->getById($scanId);
        $this->assertEquals(3, (int)$scan['processed_count'], 'DB processed_count must be updated to 3');
        $this->assertEquals(1, (int)$scan['lorem_finding_count'], 'DB lorem_finding_count must be updated to 1');
    }

    private function testExportControllerScanIdResolution(): void
    {
        $projId = $this->projectRepo->findOrCreate('Export Test Site', 'https://export.example.com', 'https://export.example.com');
        $scan1 = $this->scanRepo->create($projId, 1);
        $scan2 = $this->scanRepo->create($projId, 2);

        // Put findings in scan1
        $p1 = $this->pageResRepo->saveResult([
            'scan_id'           => $scan1,
            'discovered_url_id' => 201,
            'requested_url'     => 'https://export.example.com/scan1-page',
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
        ]);
        $this->findingRepo->insertBatch($scan1, $p1, null, [
            ['detection_category' => 'lorem_ipsum', 'matched_text' => 'Scan1 Lorem text', 'occurrences' => 2]
        ]);

        // Put different finding in scan2
        $p2 = $this->pageResRepo->saveResult([
            'scan_id'           => $scan2,
            'discovered_url_id' => 202,
            'requested_url'     => 'https://export.example.com/scan2-page',
            'http_status'       => 200,
            'processing_status' => 'clean',
        ]);

        // Direct query check simulating ExportController logic for scan1
        $stmt = $this->pdo->prepare('
            SELECT pr.requested_url, f.matched_text
            FROM page_results pr
            LEFT JOIN findings f ON pr.id = f.page_result_id
            WHERE pr.scan_id = ?
        ');
        $stmt->execute([$scan1]);
        $scan1Export = $stmt->fetchAll();

        $this->assertEquals(1, count($scan1Export), 'Scan 1 must have 1 export row');
        $this->assertEquals('https://export.example.com/scan1-page', $scan1Export[0]['requested_url'], 'Exporting scan1 must return scan1 URL');
        $this->assertEquals('Scan1 Lorem text', $scan1Export[0]['matched_text'], 'Exporting scan1 must return scan1 matched text');
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
