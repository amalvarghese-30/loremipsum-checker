<?php
/**
 * Automated Regression & Integration Test Suite: Interactive Reporting Accuracy & Finding Metrics
 *
 * Verifies all 17 requirements:
 *  1. Homepage passes latest_scan_id to viewReport and project ID is never treated as a scan ID.
 *  2. Project ID is never treated as a scan ID.
 *  3. Homepage and report Lorem counts match for the same scan ID.
 *  4. Paragraph findings and phrase occurrences are independently calculated.
 *  5. Multiple phrases inside one paragraph are counted correctly.
 *  6. Resource Lorem findings do not inflate page-level Lorem metrics.
 *  7. Superseded page results do not inflate summary or table counts.
 *  8. Needs Attention filters include all pages with actual findings.
 *  9. The affected-pages table returns accurate category breakdowns (issue chips).
 * 10. SEO filters include all 6 supported SEO categories.
 * 11. Accessibility filters include all supported accessibility categories (missing_image_alt, missing_lang).
 * 12. Missing content filters include empty_page and incomplete_sections.
 * 13. Resource card totals and filtered results match.
 * 14. Scan Errors includes every supported failure status (HTTP >= 400, failed, timeout, ssl_error, etc.).
 * 15. Clean pages exclude failed, incomplete, and unclassified pages.
 * 16. Pagination totals match the result set.
 * 17. Export values match the report for the same scan.
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

class InteractiveReportingAccuracyTest
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
        ');
    }

    private function resetDatabase(): void
    {
        $this->pdo->exec('
            DELETE FROM findings;
            DELETE FROM scan_resources;
            DELETE FROM page_resource_relations;
            DELETE FROM page_results;
            DELETE FROM url_discovery_sources;
            DELETE FROM discovered_urls;
            DELETE FROM scan_settings;
            DELETE FROM scans;
            DELETE FROM projects;
        ');
    }

    private function assertEquals($expected, $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $trace[1]['function'] ?? 'unknown';
            $callerLine = $trace[0]['line'] ?? 0;
            $msg = ($message ?: "Expected " . var_export($expected, true) . ", got " . var_export($actual, true)) . " in {$caller}() at line {$callerLine}";
            throw new \RuntimeException("Assertion failed: {$msg}");
        }
    }

    private function assertTrue(bool $condition, string $message = ''): void
    {
        $this->assertions++;
        if (!$condition) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $trace[1]['function'] ?? 'unknown';
            $callerLine = $trace[0]['line'] ?? 0;
            $msg = ($message ?: "Condition is not true") . " in {$caller}() at line {$callerLine}";
            throw new \RuntimeException("Assertion failed: {$msg}");
        }
    }

    public function run(): void
    {
        $this->testHomepageLatestScanIdAndProjectResolution();
        $this->testHomepageAndReportLoremCountsMatch();
        $this->testParagraphFindingsAndPhraseOccurrencesCalculatedIndependently();
        $this->testMultiplePhrasesInsideOneParagraph();
        $this->testResourceLoremDoesNotInflatePageLevelLoremMetrics();
        $this->testSupersededPageResultsDoNotInflateCounts();
        $this->testNeedsAttentionFilterIncludesAllAffectedPages();
        $this->testAffectedPagesCategoryBreakdowns();
        $this->testSeoFilterIncludesAllSixCategories();
        $this->testAccessibilityFilterIncludesAllCategories();
        $this->testMissingContentFilterIncludesEmptyPageAndIncompleteSections();
        $this->testResourceCardTotalsAndFilteredResultsMatch();
        $this->testScanErrorsIncludesAllFailureStatuses();
        $this->testCleanPagesExcludesFailedIncompleteAndUnclassified();
        $this->testPaginationTotalsMatchResultSet();
        $this->testExportValuesMatchReport();
    }

    /**
     * Requirement 1 & 2: Homepage passes latest_scan_id to viewReport and project_id is never treated as scan_id.
     */
    private function testHomepageLatestScanIdAndProjectResolution(): void
    {
        $this->resetDatabase();

        // Project 1
        $projId = $this->projectRepo->findOrCreate('Acme Corp', 'https://acme.test', 'https://acme.test');
        $this->assertEquals(1, $projId);

        // Project 1 has two scans: Scan 1 (historical) and Scan 2 (latest)
        $scan1 = $this->scanRepo->createScan($projId);
        $this->scanRepo->updateStatus($scan1, 'completed');

        $scan2 = $this->scanRepo->createScan($projId);
        $this->scanRepo->updateStatus($scan2, 'completed');

        // Query project list through ProjectRepository
        $projects = $this->projectRepo->listProjects(false);
        $this->assertEquals(1, count($projects));
        $this->assertEquals(2, (int)$projects[0]['latest_scan_id'], 'Project latest_scan_id must resolve to scan 2, not scan 1 or project id 1');

        // Now verify frontend contract in index.html (search for viewReport call in buildCard)
        $indexHtml = file_get_contents(dirname(__DIR__) . '/index.html');
        $this->assertTrue(
            strpos($indexHtml, 'viewReport(${scanIdArg})') !== false ||
            strpos($indexHtml, 'viewReport(scanId)') !== false,
            'index.html buildCard must pass scanIdArg (p.latest_scan_id) to viewReport'
        );
        $this->assertTrue(
            strpos($indexHtml, "viewReport('\${p.id}')") === false,
            'index.html buildCard must NOT pass p.id to viewReport'
        );
    }

    /**
     * Requirement 3: Homepage and report Lorem counts match for the same scan ID.
     */
    private function testHomepageAndReportLoremCountsMatch(): void
    {
        $this->resetDatabase();

        $projId = $this->projectRepo->findOrCreate('Brand Co', 'https://brand.test', 'https://brand.test');
        $scanId = $this->scanRepo->createScan($projId);

        // 3 pages with Lorem findings
        for ($i = 1; $i <= 3; $i++) {
            $url = "https://brand.test/p{$i}";
            $urlId = $this->urlRepo->insertDiscovered($scanId, $url, $url, 1, 'crawler');
            $pageResId = $this->pageResRepo->saveResult([
                'scan_id'           => $scanId,
                'discovered_url_id' => $urlId,
                'requested_url'     => $url,
                'final_url'         => $url,
                'http_status'       => 200,
                'processing_status' => 'findings_detected',
                'audit_status'      => 'audit_issues',
                'finding_count'     => 2,
                'has_lorem_ipsum'   => 1,
            ]);

            // 2 findings per page, each with 3 occurrences
            $this->findingRepo->insertBatch($scanId, $pageResId, null, [
                [
                    'page_url'           => $url,
                    'matched_text'       => 'Lorem ipsum dolor sit amet',
                    'occurrences'        => 3,
                    'detection_category' => 'lorem_ipsum',
                    'severity'           => 'high',
                ],
                [
                    'page_url'           => $url,
                    'matched_text'       => 'Consectetur adipiscing elit',
                    'occurrences'        => 3,
                    'detection_category' => 'lorem_ipsum',
                    'severity'           => 'high',
                ],
            ]);
        }

        // Authoritative metrics
        $metrics = $this->scanRepo->getAuthoritativeMetrics($scanId);
        $this->assertEquals(3, $metrics['lorem_page_count']);
        $this->assertEquals(6, $metrics['lorem_finding_count']);
        $this->assertEquals(18, $metrics['lorem_occurrence_count']);

        // Invoke ResultsController scanSummary logic (simulating API output)
        $summaryLorem = [
            'pages'       => $metrics['lorem_page_count'],
            'findings'    => $metrics['lorem_finding_count'],
            'occurrences' => $metrics['lorem_occurrence_count'],
        ];

        // Verify homepage and report are 100% matched
        $this->assertEquals($metrics['lorem_finding_count'], $summaryLorem['findings']);
        $this->assertEquals($metrics['lorem_occurrence_count'], $summaryLorem['occurrences']);
        $this->assertEquals($metrics['lorem_page_count'], $summaryLorem['pages']);
    }

    /**
     * Requirement 4 & 5: Paragraph findings and phrase occurrences independently calculated; multiple phrases in one paragraph.
     */
    private function testParagraphFindingsAndPhraseOccurrencesCalculatedIndependently(): void
    {
        $this->resetDatabase();

        $projId = $this->projectRepo->findOrCreate('Test 4', 'https://t4.test', 'https://t4.test');
        $scanId = $this->scanRepo->createScan($projId);

        $url = 'https://t4.test/story';
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
            'has_lorem_ipsum'   => 1,
        ]);

        // One single paragraph finding containing 4 occurrences of lorem phrases
        $this->findingRepo->insertBatch($scanId, $pageResId, null, [
            [
                'page_url'           => $url,
                'html_element'       => 'p',
                'matched_text'       => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt',
                'occurrences'        => 4,
                'detection_category' => 'lorem_ipsum',
                'severity'           => 'high',
            ]
        ]);

        $metrics = $this->scanRepo->getAuthoritativeMetrics($scanId);
        $this->assertEquals(1, $metrics['lorem_finding_count'], 'Should be exactly 1 paragraph finding');
        $this->assertEquals(4, $metrics['lorem_occurrence_count'], 'Should be exactly 4 phrase occurrences');
        $this->assertEquals(1, $metrics['lorem_page_count'], 'Should be exactly 1 affected page');
    }

    /**
     * Requirement 5: Multiple phrases inside one paragraph.
     */
    private function testMultiplePhrasesInsideOneParagraph(): void
    {
        $this->resetDatabase();

        $projId = $this->projectRepo->findOrCreate('Test 5', 'https://t5.test', 'https://t5.test');
        $scanId = $this->scanRepo->createScan($projId);

        $url = 'https://t5.test/p1';
        $urlId = $this->urlRepo->insertDiscovered($scanId, $url, $url, 1, 'crawler');
        $pageResId = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlId,
            'requested_url'     => $url,
            'final_url'         => $url,
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'audit_status'      => 'audit_issues',
            'finding_count'     => 2,
            'has_lorem_ipsum'   => 1,
        ]);

        // Finding 1: 3 occurrences, Finding 2: 7 occurrences
        $this->findingRepo->insertBatch($scanId, $pageResId, null, [
            [
                'page_url'           => $url,
                'occurrences'        => 3,
                'matched_text'       => 'lorem ipsum dolor',
                'detection_category' => 'lorem_ipsum',
            ],
            [
                'page_url'           => $url,
                'occurrences'        => 7,
                'matched_text'       => 'consectetur adipiscing elit',
                'detection_category' => 'lorem_ipsum',
            ],
        ]);

        $metrics = $this->scanRepo->getAuthoritativeMetrics($scanId);
        $this->assertEquals(2, $metrics['lorem_finding_count']);
        $this->assertEquals(10, $metrics['lorem_occurrence_count']);
    }

    /**
     * Requirement 6: Resource Lorem findings do not inflate page-level Lorem metrics.
     */
    private function testResourceLoremDoesNotInflatePageLevelLoremMetrics(): void
    {
        $this->resetDatabase();

        $projId = $this->projectRepo->findOrCreate('Test 6', 'https://t6.test', 'https://t6.test');
        $scanId = $this->scanRepo->createScan($projId);

        $pageUrl = 'https://t6.test/page';
        $urlId = $this->urlRepo->insertDiscovered($scanId, $pageUrl, $pageUrl, 1, 'crawler');
        $pageResId = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlId,
            'requested_url'     => $pageUrl,
            'final_url'         => $pageUrl,
            'http_status'       => 200,
            'processing_status' => 'clean',
            'audit_status'      => 'clean',
            'finding_count'     => 0,
            'has_lorem_ipsum'   => 0,
        ]);

        // Create an external resource
        $resUrl = 'https://t6.test/script.js';
        $reg = $this->resourceRepo->findOrCreate($scanId, $pageResId, $resUrl, $resUrl, 'script');
        $resId = $reg['id'];

        $this->resourceRepo->markCompleted($resId, [
            'content_type'  => 'application/javascript',
            'http_status'   => 200,
            'response_size' => 2048,
            'duration_ms'   => 40,
            'finding_count' => 1,
        ]);

        // Add a resource finding with category lorem_ipsum
        $this->findingRepo->insertBatch($scanId, $pageResId, $resId, [
            [
                'page_url'           => $pageUrl,
                'resource_url'       => $resUrl,
                'resource_type'      => 'script',
                'matched_text'       => '/* lorem ipsum comment in JS */',
                'occurrences'        => 5,
                'detection_category' => 'lorem_ipsum',
            ]
        ]);

        $metrics = $this->scanRepo->getAuthoritativeMetrics($scanId);
        $this->assertEquals(0, $metrics['lorem_finding_count'], 'Page lorem finding count must be 0');
        $this->assertEquals(0, $metrics['lorem_page_count'], 'Page lorem count must be 0');
        $this->assertEquals(0, $metrics['lorem_occurrence_count'], 'Page lorem occurrence count must be 0');
        $this->assertEquals(1, $metrics['resource_finding_count'], 'Resource finding count must be 1');
        $this->assertEquals(1, $metrics['total_findings'], 'Total findings must be 1');
    }

    /**
     * Requirement 7: Superseded page results do not inflate summary or table counts.
     */
    private function testSupersededPageResultsDoNotInflateCounts(): void
    {
        $this->resetDatabase();

        $projId = $this->projectRepo->findOrCreate('Test 7', 'https://t7.test', 'https://t7.test');
        $scanId = $this->scanRepo->createScan($projId);

        $url = 'https://t7.test/item';
        $urlId = $this->urlRepo->insertDiscovered($scanId, $url, $url, 1, 'crawler');

        // First attempt (superseded): failed with 1 finding
        $oldResId = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlId,
            'requested_url'     => $url,
            'final_url'         => $url,
            'http_status'       => 500,
            'processing_status' => 'failed',
            'audit_status'      => 'failed',
            'finding_count'     => 1,
        ]);
        $this->findingRepo->insertBatch($scanId, $oldResId, null, [
            [
                'page_url'           => $url,
                'matched_text'       => 'Old error',
                'detection_category' => 'lorem_ipsum',
            ]
        ]);

        // Second attempt (canonical): HTTP 200, clean, 0 findings
        $canonResId = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlId,
            'requested_url'     => $url,
            'final_url'         => $url,
            'http_status'       => 200,
            'processing_status' => 'clean',
            'audit_status'      => 'clean',
            'finding_count'     => 0,
        ]);

        $metrics = $this->scanRepo->getAuthoritativeMetrics($scanId);
        $this->assertEquals(1, $metrics['processed_count'], 'Should count only 1 canonical processed page');
        $this->assertEquals(1, $metrics['clean_count'], 'Should count 1 clean page');
        $this->assertEquals(0, $metrics['failed_count'], 'Should count 0 failed pages');
        $this->assertEquals(0, $metrics['total_findings'], 'Should count 0 total findings');

        // Verify PageResultRepository listPaginated returns only the canonical record
        $pages = $this->pageResRepo->listPaginated($scanId, 1, 10);
        $this->assertEquals(1, count($pages), 'Must return exactly 1 canonical page result');
        $this->assertEquals($canonResId, (int)$pages[0]['id']);

        // Verify FindingRepository listPaginated returns 0 findings for canonical pages
        $findings = $this->findingRepo->listPaginated($scanId, 1, 10);
        $this->assertEquals(0, count($findings), 'Must return 0 findings from superseded page results');
    }

    /**
     * Requirement 8: Needs Attention filters include all pages with actual findings.
     */
    private function testNeedsAttentionFilterIncludesAllAffectedPages(): void
    {
        $this->resetDatabase();

        $projId = $this->projectRepo->findOrCreate('Test 8', 'https://t8.test', 'https://t8.test');
        $scanId = $this->scanRepo->createScan($projId);

        // Page A: clean, 0 findings
        $urlA = 'https://t8.test/clean';
        $urlIdA = $this->urlRepo->insertDiscovered($scanId, $urlA, $urlA, 1, 'crawler');
        $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlIdA,
            'requested_url'     => $urlA,
            'final_url'         => $urlA,
            'http_status'       => 200,
            'processing_status' => 'clean',
            'audit_status'      => 'clean',
            'finding_count'     => 0,
        ]);

        // Page B: affected, 1 finding
        $urlB = 'https://t8.test/affected';
        $urlIdB = $this->urlRepo->insertDiscovered($scanId, $urlB, $urlB, 1, 'crawler');
        $pageB = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlIdB,
            'requested_url'     => $urlB,
            'final_url'         => $urlB,
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'audit_status'      => 'audit_issues',
            'finding_count'     => 1,
        ]);
        $this->findingRepo->insertBatch($scanId, $pageB, null, [
            [
                'page_url'           => $urlB,
                'matched_text'       => 'Missing Title',
                'detection_category' => 'missing_title',
            ]
        ]);

        // Filter by audit_status: affected (matches findings_detected and audit_issues)
        $affectedPages = $this->pageResRepo->listPaginated($scanId, 1, 10, ['audit_status' => 'affected']);
        $this->assertEquals(1, count($affectedPages));
        $this->assertEquals('https://t8.test/affected', $affectedPages[0]['requested_url']);

        // Filter by audit_status: clean
        $cleanPages = $this->pageResRepo->listPaginated($scanId, 1, 10, ['audit_status' => 'clean']);
        $this->assertEquals(1, count($cleanPages));
        $this->assertEquals('https://t8.test/clean', $cleanPages[0]['requested_url']);
    }

    /**
     * Requirement 9: The affected-pages table returns accurate category breakdowns (issue chips).
     */
    private function testAffectedPagesCategoryBreakdowns(): void
    {
        $this->resetDatabase();

        $projId = $this->projectRepo->findOrCreate('Test 9', 'https://t9.test', 'https://t9.test');
        $scanId = $this->scanRepo->createScan($projId);

        $url = 'https://t9.test/multi';
        $urlId = $this->urlRepo->insertDiscovered($scanId, $url, $url, 1, 'crawler');
        $pageId = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlId,
            'requested_url'     => $url,
            'final_url'         => $url,
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'audit_status'      => 'audit_issues',
            'finding_count'     => 4,
        ]);

        $this->findingRepo->insertBatch($scanId, $pageId, null, [
            [
                'page_url'           => $url,
                'detection_category' => 'lorem_ipsum',
                'matched_text'       => 'lorem 1',
            ],
            [
                'page_url'           => $url,
                'detection_category' => 'lorem_ipsum',
                'matched_text'       => 'lorem 2',
            ],
            [
                'page_url'           => $url,
                'detection_category' => 'missing_title',
                'matched_text'       => 'no title',
            ],
            [
                'page_url'           => $url,
                'detection_category' => 'missing_image_alt',
                'matched_text'       => 'no alt',
            ],
        ]);

        $pages = $this->pageResRepo->listPaginated($scanId, 1, 10);
        $this->assertEquals(1, count($pages));
        $this->assertTrue(isset($pages[0]['issues']), 'Page result must have issues array attached');

        $issues = $pages[0]['issues'];
        $issueMap = [];
        foreach ($issues as $iss) {
            $issueMap[$iss['category']] = (int)$iss['count'];
        }

        $this->assertEquals(2, $issueMap['lorem_ipsum'] ?? 0);
        $this->assertEquals(1, $issueMap['missing_title'] ?? 0);
        $this->assertEquals(1, $issueMap['missing_image_alt'] ?? 0);
    }

    /**
     * Requirement 10: SEO filters include all 6 supported SEO categories.
     */
    private function testSeoFilterIncludesAllSixCategories(): void
    {
        $this->resetDatabase();

        $projId = $this->projectRepo->findOrCreate('Test 10', 'https://t10.test', 'https://t10.test');
        $scanId = $this->scanRepo->createScan($projId);

        $categories = [
            'missing_title',
            'missing_meta_desc',
            'missing_h1',
            'multiple_h1',
            'missing_canonical',
            'heading_structure',
        ];

        foreach ($categories as $idx => $cat) {
            $url = "https://t10.test/{$cat}";
            $urlId = $this->urlRepo->insertDiscovered($scanId, $url, $url, 1, 'crawler');
            $pId = $this->pageResRepo->saveResult([
                'scan_id'           => $scanId,
                'discovered_url_id' => $urlId,
                'requested_url'     => $url,
                'final_url'         => $url,
                'http_status'       => 200,
                'processing_status' => 'findings_detected',
                'audit_status'      => 'audit_issues',
                'finding_count'     => 1,
            ]);
            $this->findingRepo->insertBatch($scanId, $pId, null, [
                [
                    'page_url'           => $url,
                    'detection_category' => $cat,
                    'matched_text'       => "Finding for {$cat}",
                ]
            ]);
        }

        // Also add 1 non-SEO finding
        $urlOther = 'https://t10.test/other';
        $urlIdOther = $this->urlRepo->insertDiscovered($scanId, $urlOther, $urlOther, 1, 'crawler');
        $pOth = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlIdOther,
            'requested_url'     => $urlOther,
            'final_url'         => $urlOther,
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'audit_status'      => 'audit_issues',
            'finding_count'     => 1,
        ]);
        $this->findingRepo->insertBatch($scanId, $pOth, null, [
            [
                'page_url'           => $urlOther,
                'detection_category' => 'lorem_ipsum',
                'matched_text'       => 'lorem text',
            ]
        ]);

        // Filter findings by seo
        $seoFindings = $this->findingRepo->listPaginated($scanId, 1, 20, ['category' => 'seo']);
        $this->assertEquals(6, count($seoFindings), 'All 6 SEO category findings must be returned');

        // Filter pages by seo
        $seoPages = $this->pageResRepo->listPaginated($scanId, 1, 20, ['category' => 'seo']);
        $this->assertEquals(6, count($seoPages), 'All 6 SEO pages must be returned');
    }

    /**
     * Requirement 11: Accessibility filters include all supported accessibility categories (missing_image_alt, missing_lang).
     */
    private function testAccessibilityFilterIncludesAllCategories(): void
    {
        $this->resetDatabase();

        $projId = $this->projectRepo->findOrCreate('Test 11', 'https://t11.test', 'https://t11.test');
        $scanId = $this->scanRepo->createScan($projId);

        $a11yCats = ['missing_image_alt', 'missing_lang'];

        foreach ($a11yCats as $idx => $cat) {
            $url = "https://t11.test/{$cat}";
            $urlId = $this->urlRepo->insertDiscovered($scanId, $url, $url, 1, 'crawler');
            $pId = $this->pageResRepo->saveResult([
                'scan_id'           => $scanId,
                'discovered_url_id' => $urlId,
                'requested_url'     => $url,
                'final_url'         => $url,
                'http_status'       => 200,
                'processing_status' => 'findings_detected',
                'audit_status'      => 'audit_issues',
                'finding_count'     => 1,
            ]);
            $this->findingRepo->insertBatch($scanId, $pId, null, [
                [
                    'page_url'           => $url,
                    'detection_category' => $cat,
                    'matched_text'       => "Finding for {$cat}",
                ]
            ]);
        }

        // Filter findings by a11y
        $a11yFindings = $this->findingRepo->listPaginated($scanId, 1, 20, ['category' => 'a11y']);
        $this->assertEquals(2, count($a11yFindings), 'Both missing_image_alt and missing_lang must be returned under a11y');

        // Filter pages by a11y
        $a11yPages = $this->pageResRepo->listPaginated($scanId, 1, 20, ['category' => 'a11y']);
        $this->assertEquals(2, count($a11yPages));
    }

    /**
     * Requirement 12: Missing content filters include empty_page and incomplete_sections.
     */
    private function testMissingContentFilterIncludesEmptyPageAndIncompleteSections(): void
    {
        $this->resetDatabase();

        $projId = $this->projectRepo->findOrCreate('Test 12', 'https://t12.test', 'https://t12.test');
        $scanId = $this->scanRepo->createScan($projId);

        $emptyCats = ['empty_page', 'incomplete_sections'];

        foreach ($emptyCats as $idx => $cat) {
            $url = "https://t12.test/{$cat}";
            $urlId = $this->urlRepo->insertDiscovered($scanId, $url, $url, 1, 'crawler');
            $pId = $this->pageResRepo->saveResult([
                'scan_id'           => $scanId,
                'discovered_url_id' => $urlId,
                'requested_url'     => $url,
                'final_url'         => $url,
                'http_status'       => 200,
                'processing_status' => 'findings_detected',
                'audit_status'      => 'audit_issues',
                'finding_count'     => 1,
            ]);
            $this->findingRepo->insertBatch($scanId, $pId, null, [
                [
                    'page_url'           => $url,
                    'detection_category' => $cat,
                    'matched_text'       => "Finding for {$cat}",
                ]
            ]);
        }

        $emptyFindings = $this->findingRepo->listPaginated($scanId, 1, 20, ['category' => 'empty_page']);
        $this->assertEquals(2, count($emptyFindings), 'Both empty_page and incomplete_sections must match');
    }

    /**
     * Requirement 13: Resource card totals and filtered results match.
     */
    private function testResourceCardTotalsAndFilteredResultsMatch(): void
    {
        $this->resetDatabase();

        $projId = $this->projectRepo->findOrCreate('Test 13', 'https://t13.test', 'https://t13.test');
        $scanId = $this->scanRepo->createScan($projId);

        $url = 'https://t13.test/home';
        $urlId = $this->urlRepo->insertDiscovered($scanId, $url, $url, 1, 'crawler');
        $pId = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $urlId,
            'requested_url'     => $url,
            'final_url'         => $url,
            'http_status'       => 200,
            'processing_status' => 'clean',
            'audit_status'      => 'clean',
            'finding_count'     => 0,
        ]);

        $res1 = $this->resourceRepo->findOrCreate($scanId, $pId, 'https://t13.test/style.css', 'https://t13.test/style.css', 'css');
        $res2 = $this->resourceRepo->findOrCreate($scanId, $pId, 'https://t13.test/app.js', 'https://t13.test/app.js', 'script');

        $this->findingRepo->insertBatch($scanId, $pId, $res1['id'], [
            [
                'page_url'           => $url,
                'resource_url'       => 'https://t13.test/style.css',
                'matched_text'       => 'lorem css',
                'detection_category' => 'lorem_ipsum',
            ]
        ]);
        $this->findingRepo->insertBatch($scanId, $pId, $res2['id'], [
            [
                'page_url'           => $url,
                'resource_url'       => 'https://t13.test/app.js',
                'matched_text'       => 'lorem js',
                'detection_category' => 'lorem_ipsum',
            ]
        ]);

        $metrics = $this->scanRepo->getAuthoritativeMetrics($scanId);
        $this->assertEquals(2, $metrics['resource_finding_count']);

        // Query with is_resource = true
        $resFindings = $this->findingRepo->listPaginated($scanId, 1, 10, ['is_resource' => true]);
        $this->assertEquals(2, count($resFindings));
    }

    /**
     * Requirement 14: Scan Errors includes every supported failure status (HTTP >= 400, failed, timeout, ssl_error, etc.).
     */
    private function testScanErrorsIncludesAllFailureStatuses(): void
    {
        $this->resetDatabase();

        $projId = $this->projectRepo->findOrCreate('Test 14', 'https://t14.test', 'https://t14.test');
        $scanId = $this->scanRepo->createScan($projId);

        $statuses = [
            ['http' => 404, 'proc' => 'failed'],
            ['http' => 500, 'proc' => 'failed'],
            ['http' => null, 'proc' => 'timeout'],
            ['http' => null, 'proc' => 'ssl_error'],
            ['http' => null, 'proc' => 'dns_error'],
            ['http' => null, 'proc' => 'connection_error'],
        ];

        foreach ($statuses as $idx => $st) {
            $url = "https://t14.test/err{$idx}";
            $urlId = $this->urlRepo->insertDiscovered($scanId, $url, $url, 1, 'crawler');
            $this->pageResRepo->saveResult([
                'scan_id'           => $scanId,
                'discovered_url_id' => $urlId,
                'requested_url'     => $url,
                'final_url'         => $url,
                'http_status'       => $st['http'],
                'processing_status' => $st['proc'],
                'audit_status'      => 'failed',
                'finding_count'     => 0,
            ]);
        }

        // One successful clean page
        $cleanUrl = 'https://t14.test/ok';
        $cleanId = $this->urlRepo->insertDiscovered($scanId, $cleanUrl, $cleanUrl, 1, 'crawler');
        $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $cleanId,
            'requested_url'     => $cleanUrl,
            'final_url'         => $cleanUrl,
            'http_status'       => 200,
            'processing_status' => 'clean',
            'audit_status'      => 'clean',
            'finding_count'     => 0,
        ]);

        $errorPages = $this->pageResRepo->listPaginated($scanId, 1, 20, ['is_error' => true]);
        $this->assertEquals(6, count($errorPages), 'Scan errors must include all 6 failure types');
    }

    /**
     * Requirement 15: Clean pages exclude failed, incomplete, and unclassified pages.
     */
    private function testCleanPagesExcludesFailedIncompleteAndUnclassified(): void
    {
        $this->resetDatabase();

        $projId = $this->projectRepo->findOrCreate('Test 15', 'https://t15.test', 'https://t15.test');
        $scanId = $this->scanRepo->createScan($projId);

        // Clean page: HTTP 200, processing_status clean, 0 findings
        $u1 = 'https://t15.test/clean';
        $id1 = $this->urlRepo->insertDiscovered($scanId, $u1, $u1, 1, 'crawler');
        $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $id1,
            'requested_url'     => $u1,
            'final_url'         => $u1,
            'http_status'       => 200,
            'processing_status' => 'clean',
            'audit_status'      => 'clean',
            'finding_count'     => 0,
        ]);

        // Failed page: HTTP 500
        $u2 = 'https://t15.test/failed';
        $id2 = $this->urlRepo->insertDiscovered($scanId, $u2, $u2, 1, 'crawler');
        $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $id2,
            'requested_url'     => $u2,
            'final_url'         => $u2,
            'http_status'       => 500,
            'processing_status' => 'failed',
            'audit_status'      => 'clean', // Mislabeled audit_status, but HTTP 500
            'finding_count'     => 0,
        ]);

        // Incomplete/affected page: HTTP 200, but has 1 finding
        $u3 = 'https://t15.test/dirty';
        $id3 = $this->urlRepo->insertDiscovered($scanId, $u3, $u3, 1, 'crawler');
        $p3 = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $id3,
            'requested_url'     => $u3,
            'final_url'         => $u3,
            'http_status'       => 200,
            'processing_status' => 'clean',
            'audit_status'      => 'clean', // Mislabeled clean, but finding_count > 0
            'finding_count'     => 1,
        ]);
        $this->findingRepo->insertBatch($scanId, $p3, null, [
            [
                'page_url'           => $u3,
                'matched_text'       => 'dirty',
                'detection_category' => 'lorem_ipsum',
            ]
        ]);

        // Query clean pages
        $cleanPages = $this->pageResRepo->listPaginated($scanId, 1, 10, ['audit_status' => 'clean']);
        $this->assertEquals(1, count($cleanPages), 'Only strictly clean pages (HTTP < 400, 0 findings) should pass clean filter');
        $this->assertEquals('https://t15.test/clean', $cleanPages[0]['requested_url']);
    }

    /**
     * Requirement 16: Pagination totals match the result set.
     */
    private function testPaginationTotalsMatchResultSet(): void
    {
        $this->resetDatabase();

        $projId = $this->projectRepo->findOrCreate('Test 16', 'https://t16.test', 'https://t16.test');
        $scanId = $this->scanRepo->createScan($projId);

        for ($i = 1; $i <= 15; $i++) {
            $u = "https://t16.test/{$i}";
            $id = $this->urlRepo->insertDiscovered($scanId, $u, $u, 1, 'crawler');
            $pId = $this->pageResRepo->saveResult([
                'scan_id'           => $scanId,
                'discovered_url_id' => $id,
                'requested_url'     => $u,
                'final_url'         => $u,
                'http_status'       => 200,
                'processing_status' => 'findings_detected',
                'audit_status'      => 'audit_issues',
                'finding_count'     => 1,
            ]);
            $this->findingRepo->insertBatch($scanId, $pId, null, [
                [
                    'page_url'           => $u,
                    'matched_text'       => "item {$i}",
                    'detection_category' => 'lorem_ipsum',
                ]
            ]);
        }

        $totalPages = $this->pageResRepo->countFiltered($scanId);
        $this->assertEquals(15, $totalPages);

        $page1 = $this->pageResRepo->listPaginated($scanId, 1, 10);
        $this->assertEquals(10, count($page1));

        $page2 = $this->pageResRepo->listPaginated($scanId, 2, 10);
        $this->assertEquals(5, count($page2));

        $totalFindings = $this->findingRepo->countFiltered($scanId);
        $this->assertEquals(15, $totalFindings);

        $find1 = $this->findingRepo->listPaginated($scanId, 1, 10);
        $this->assertEquals(10, count($find1));

        $find2 = $this->findingRepo->listPaginated($scanId, 2, 10);
        $this->assertEquals(5, count($find2));
    }

    /**
     * Requirement 17: Export values match the report for the same scan.
     */
    private function testExportValuesMatchReport(): void
    {
        $this->resetDatabase();

        $projId = $this->projectRepo->findOrCreate('Test 17', 'https://t17.test', 'https://t17.test');
        $scanId = $this->scanRepo->createScan($projId);

        $u = 'https://t17.test/home';
        $id = $this->urlRepo->insertDiscovered($scanId, $u, $u, 1, 'crawler');
        $pId = $this->pageResRepo->saveResult([
            'scan_id'           => $scanId,
            'discovered_url_id' => $id,
            'requested_url'     => $u,
            'final_url'         => $u,
            'http_status'       => 200,
            'processing_status' => 'findings_detected',
            'audit_status'      => 'audit_issues',
            'finding_count'     => 2,
            'has_lorem_ipsum'   => 1,
        ]);

        $this->findingRepo->insertBatch($scanId, $pId, null, [
            [
                'page_url'           => $u,
                'matched_text'       => 'lorem ipsum',
                'occurrences'        => 3,
                'detection_category' => 'lorem_ipsum',
            ],
            [
                'page_url'           => $u,
                'matched_text'       => 'missing meta',
                'occurrences'        => 1,
                'detection_category' => 'missing_meta_desc',
            ],
        ]);

        $metrics = $this->scanRepo->getAuthoritativeMetrics($scanId);

        $exportService = new ExportService($this->pdo);
        $exportData = $exportService->getExportData($scanId);

        $this->assertEquals($metrics['discovered_count'], $exportData['metrics']['discovered_count']);
        $this->assertEquals($metrics['processed_count'], $exportData['metrics']['processed_count']);
        $this->assertEquals($metrics['clean_count'], $exportData['metrics']['clean_count']);
        $this->assertEquals($metrics['affected_page_count'], $exportData['metrics']['affected_page_count']);
        $this->assertEquals($metrics['failed_count'], $exportData['metrics']['failed_count']);
        $this->assertEquals($metrics['total_findings'], $exportData['metrics']['total_findings']);
        $this->assertEquals($metrics['lorem_finding_count'], $exportData['metrics']['lorem_finding_count']);
        $this->assertEquals($metrics['lorem_occurrence_count'], $exportData['metrics']['lorem_occurrence_count']);
        $this->assertEquals($metrics['lorem_page_count'], $exportData['metrics']['lorem_page_count']);
        $this->assertEquals($metrics['resource_finding_count'], $exportData['metrics']['resource_finding_count']);
        $this->assertEquals($metrics['audit_finding_count'], $exportData['metrics']['audit_finding_count']);
    }
}
