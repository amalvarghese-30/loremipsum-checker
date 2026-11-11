<?php
/**
 * Audit Modules Test
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Detection/AuditModules.php';
require_once dirname(__DIR__) . '/includes/Detection/HtmlContentExtractor.php';

use LoremChecker\Detection\AuditModules;
use LoremChecker\Detection\HtmlContentExtractor;

class AuditModulesTest
{
    public int $assertions = 0;

    public function run(): void
    {
        $this->testEmptyPageDetection();
        $this->testMissingTitleAndMetaDesc();
        $this->testHeadingStructure();
        $this->testMissingAlt();
        $this->testContentHash();
    }

    private function testEmptyPageDetection(): void
    {
        $audit = new AuditModules(['empty_page' => true, 'empty_page_threshold' => 100]);
        $findings = $audit->auditPage('<html><body><p>Short</p></body></html>', 'https://example.com', [
            'full_text' => 'Short',
            'page_title' => 'Page',
            'meta_desc' => 'Desc',
        ]);

        $hasEmpty = false;
        foreach ($findings as $f) {
            if ($f['detection_category'] === 'empty_page') {
                $hasEmpty = true;
                break;
            }
        }
        $this->assertTrue($hasEmpty, 'Detects empty page with text volume below threshold');
    }

    private function testMissingTitleAndMetaDesc(): void
    {
        $audit = new AuditModules(['missing_title' => true, 'missing_meta_desc' => true]);
        $findings = $audit->auditPage('<html><body><h1>Hello</h1></body></html>', 'https://example.com', [
            'full_text' => 'Hello World this is a sufficiently long page text to avoid empty check',
            'page_title' => '',
            'meta_desc' => '',
        ]);

        $cats = array_column($findings, 'detection_category');
        $this->assertTrue(in_array('missing_title', $cats, true), 'Detects missing title');
        $this->assertTrue(in_array('missing_meta_desc', $cats, true), 'Detects missing meta description');
    }

    private function testHeadingStructure(): void
    {
        $audit = new AuditModules(['heading_structure' => true]);
        // Missing H1
        $findings1 = $audit->auditPage('<html><body><h2>No H1</h2></body></html>', 'https://example.com', [
            'page_title' => 'Title',
            'meta_desc' => 'Desc',
            'full_text' => 'Some text',
        ]);
        $cats1 = array_column($findings1, 'detection_category');
        $this->assertTrue(in_array('missing_h1', $cats1, true), 'Detects missing H1');

        // Multiple H1
        $findings2 = $audit->auditPage('<html><body><h1>H1 A</h1><h1>H1 B</h1></body></html>', 'https://example.com', [
            'page_title' => 'Title',
            'meta_desc' => 'Desc',
            'full_text' => 'Some text',
        ]);
        $cats2 = array_column($findings2, 'detection_category');
        $this->assertTrue(in_array('multiple_h1', $cats2, true), 'Detects multiple H1');
    }

    private function testMissingAlt(): void
    {
        $audit = new AuditModules(['image_alt' => true]);
        $findings = $audit->auditPage('<html><body><img src="banner.png"></body></html>', 'https://example.com', [
            'page_title' => 'Title',
            'meta_desc' => 'Desc',
            'full_text' => 'Some text',
        ]);
        $cats = array_column($findings, 'detection_category');
        $this->assertTrue(in_array('missing_image_alt', $cats, true), 'Detects missing image alt text');
    }

    private function testContentHash(): void
    {
        $audit = new AuditModules();
        $h1 = $audit->computeContentHash('  Hello   World  ');
        $h2 = $audit->computeContentHash('hello world');
        $this->assertEquals($h1, $h2, 'Content hash normalizes case and whitespace');
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
