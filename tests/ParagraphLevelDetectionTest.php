<?php
/**
 * Automated Regression Test Suite: Paragraph-Level Detection, Scan Configuration, and Interactive Report Data
 *
 * Covers all 12 test fixtures requested in Section 10:
 *  1. One paragraph with one Lorem Ipsum phrase.
 *  2. One paragraph with three Lorem Ipsum phrases.
 *  3. Five different paragraphs with Lorem Ipsum.
 *  4. Lorem Ipsum across nested inline tags.
 *  5. Lorem Ipsum across whitespace and line breaks.
 *  6. Uppercase Lorem Ipsum.
 *  7. HTML-encoded text.
 *  8. Two identical paragraphs in separate DOM locations.
 *  9. One parent div containing a paragraph (no duplicate parent/child detection).
 * 10. Clean paragraph without Lorem Ipsum.
 * 11. Unrelated use of the individual word "lorem".
 * 12. Script/style containing Lorem Ipsum (not treated as visible page text).
 *
 * Also covers:
 * - Scan configuration snapshot propagation
 * - Metric definitions: distinguishing affected pages, paragraph findings, and occurrences
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Detection/HtmlContentExtractor.php';
require_once dirname(__DIR__) . '/includes/Detection/DetectionEngine.php';
require_once dirname(__DIR__) . '/includes/Worker/ScanWorker.php';

use LoremChecker\Detection\HtmlContentExtractor;
use LoremChecker\Detection\DetectionEngine;
use LoremChecker\Worker\ScanWorker;

class ParagraphLevelDetectionTest
{
    public int $assertions = 0;

    public function run(): void
    {
        $this->testParagraphLevelFixtures();
        $this->testScanConfigSnapshotPropagation();
    }

    private function testParagraphLevelFixtures(): void
    {
        $rules = [
            [
                'id'                 => 1,
                'name'               => 'Lorem Ipsum Classic',
                'pattern'            => '/\blorem\s+ipsum\b/i',
                'detection_category' => 'lorem_ipsum',
                'severity'           => 'high',
                'confidence'         => 'high',
                'source_types'       => 'html,text',
            ],
            [
                'id'                 => 2,
                'name'               => 'Dolor Sit Amet',
                'pattern'            => '/\bdolor\s+sit\s+amet\b/i',
                'detection_category' => 'lorem_ipsum',
                'severity'           => 'high',
                'confidence'         => 'high',
                'source_types'       => 'html,text',
            ],
            [
                'id'                 => 3,
                'name'               => 'Consectetur Adipiscing',
                'pattern'            => '/\bconsectetur\s+adipiscing\b/i',
                'detection_category' => 'lorem_ipsum',
                'severity'           => 'high',
                'confidence'         => 'high',
                'source_types'       => 'html,text',
            ],
        ];

        $extractor = new HtmlContentExtractor();
        $detector  = new DetectionEngine($rules, 100);

        // 1. One paragraph with one Lorem Ipsum phrase -> 1 finding, 1 occurrence
        $html1 = '<html><body><p>This section contains lorem ipsum in the introduction.</p></body></html>';
        $ext1 = $extractor->extract($html1, 'https://test.local/p1');
        $find1 = $detector->scanHtmlNodes($ext1['nodes'], 'https://test.local/p1');
        $this->assertEquals(1, count($find1), 'Fixture 1: Exactly 1 finding for 1 paragraph');
        $this->assertEquals(1, $find1[0]['occurrences'], 'Fixture 1: Exactly 1 occurrence');
        $this->assertEquals('This section contains lorem ipsum in the introduction.', $find1[0]['matched_text'], 'Fixture 1: Full paragraph text preserved');

        // 2. One paragraph with three Lorem Ipsum phrases -> 1 finding, 3 occurrences
        $html2 = '<html><body><p>Lorem ipsum is dummy text. Dolor sit amet is also used, along with consectetur adipiscing in drafts.</p></body></html>';
        $ext2 = $extractor->extract($html2, 'https://test.local/p2');
        $find2 = $detector->scanHtmlNodes($ext2['nodes'], 'https://test.local/p2');
        $this->assertEquals(1, count($find2), 'Fixture 2: Exactly 1 paragraph finding');
        $this->assertEquals(3, $find2[0]['occurrences'], 'Fixture 2: Exactly 3 phrase occurrences counted');

        // 3. Five different paragraphs with Lorem Ipsum -> 5 findings
        $html3 = '<html><body>
            <p>First paragraph with lorem ipsum.</p>
            <p>Second paragraph with dolor sit amet.</p>
            <blockquote>Third quote with consectetur adipiscing.</blockquote>
            <li>Fourth list item with lorem ipsum.</li>
            <div>Fifth leaf div with lorem ipsum.</div>
        </body></html>';
        $ext3 = $extractor->extract($html3, 'https://test.local/p3');
        $find3 = $detector->scanHtmlNodes($ext3['nodes'], 'https://test.local/p3');
        $this->assertEquals(5, count($find3), 'Fixture 3: Five different paragraphs yield exactly 5 findings');

        // 4. Lorem Ipsum across nested inline tags -> 1 finding
        $html4 = '<html><body><p>Welcome to <em>lorem</em> <strong>ipsum</strong> <span>dolor</span> sit amet.</p></body></html>';
        $ext4 = $extractor->extract($html4, 'https://test.local/p4');
        $find4 = $detector->scanHtmlNodes($ext4['nodes'], 'https://test.local/p4');
        $this->assertEquals(1, count($find4), 'Fixture 4: Text split across em/strong/span is ONE paragraph finding');
        $this->assertEquals('Welcome to lorem ipsum dolor sit amet.', $find4[0]['matched_text'], 'Fixture 4: Preserves complete concatenated text');

        // 5. Lorem Ipsum across whitespace and line breaks -> 1 finding
        $html5 = "<html><body><p>Here is\n\t lorem   \r\n  ipsum   in text.</p></body></html>";
        $ext5 = $extractor->extract($html5, 'https://test.local/p5');
        $find5 = $detector->scanHtmlNodes($ext5['nodes'], 'https://test.local/p5');
        $this->assertEquals(1, count($find5), 'Fixture 5: Matched across whitespace and line breaks');

        // 6. Uppercase Lorem Ipsum
        $html6 = '<html><body><h2>LOREM IPSUM DOLOR SIT AMET</h2></body></html>';
        $ext6 = $extractor->extract($html6, 'https://test.local/p6');
        $find6 = $detector->scanHtmlNodes($ext6['nodes'], 'https://test.local/p6');
        $this->assertEquals(1, count($find6), 'Fixture 6: Uppercase detected as 1 finding');
        $this->assertEquals('LOREM IPSUM DOLOR SIT AMET', $find6[0]['matched_text'], 'Fixture 6: Uppercase text matched');

        // 7. HTML-encoded text
        $html7 = '<html><body><p>&ldquo;Lorem&nbsp;ipsum&nbsp;dolor&nbsp;sit&nbsp;amet&rdquo;</p></body></html>';
        $ext7 = $extractor->extract($html7, 'https://test.local/p7');
        $find7 = $detector->scanHtmlNodes($ext7['nodes'], 'https://test.local/p7');
        $this->assertEquals(1, count($find7), 'Fixture 7: HTML entities normalized and detected');

        // 8. Two identical paragraphs in separate DOM locations -> 2 distinct findings with distinct selectors
        $html8 = '<html><body>
            <div id="section-a"><p>Lorem ipsum dolor sit amet.</p></div>
            <div id="section-b"><p>Lorem ipsum dolor sit amet.</p></div>
        </body></html>';
        $ext8 = $extractor->extract($html8, 'https://test.local/p8');
        $find8 = $detector->scanHtmlNodes($ext8['nodes'], 'https://test.local/p8');
        $this->assertEquals(2, count($find8), 'Fixture 8: Two identical paragraphs at separate locations yield 2 findings');
        $this->assertTrue($find8[0]['css_selector'] !== $find8[1]['css_selector'], 'Fixture 8: Distinct CSS selectors');

        // 9. One parent div containing a paragraph -> NO duplicate parent/child detections
        $html9 = '<html><body><div class="card"><p>Lorem ipsum dolor sit amet.</p></div></body></html>';
        $ext9 = $extractor->extract($html9, 'https://test.local/p9');
        $find9 = $detector->scanHtmlNodes($ext9['nodes'], 'https://test.local/p9');
        $this->assertEquals(1, count($find9), 'Fixture 9: Only innermost leaf paragraph extracted, no duplicate parent div finding');

        // 10. Clean paragraph without Lorem Ipsum -> 0 findings
        $html10 = '<html><body><p>This is a completely normal paragraph about modern web engineering.</p></body></html>';
        $ext10 = $extractor->extract($html10, 'https://test.local/p10');
        $find10 = $detector->scanHtmlNodes($ext10['nodes'], 'https://test.local/p10');
        $this->assertEquals(0, count($find10), 'Fixture 10: Clean paragraph yields ZERO findings');

        // 11. Unrelated use of the individual word "lorem" -> 0 findings (phrase matching prevents false positives)
        $html11 = '<html><body><p>The lorem system ipsums its data cleanly without errors.</p></body></html>';
        $ext11 = $extractor->extract($html11, 'https://test.local/p11');
        $find11 = $detector->scanHtmlNodes($ext11['nodes'], 'https://test.local/p11');
        $this->assertEquals(0, count($find11), 'Fixture 11: Unrelated words yield ZERO false positives');

        // 12. Script/style containing Lorem Ipsum -> not treated as visible content / 0 findings
        $html12 = '<html><head><style>/* lorem ipsum dolor sit amet in css */ body { color: red; }</style></head><body><script>const str = "lorem ipsum dolor sit amet";</script><p>Clean visible text.</p></body></html>';
        $ext12 = $extractor->extract($html12, 'https://test.local/p12');
        $find12 = $detector->scanHtmlNodes($ext12['nodes'], 'https://test.local/p12');
        $this->assertEquals(0, count($find12), 'Fixture 12: Inline script/style not treated as visible page text');
    }

    private function testScanConfigSnapshotPropagation(): void
    {
        $baseConfig = [
            'crawler' => ['default_max_urls' => 500, 'default_max_depth' => 3],
            'fetching' => ['concurrency' => 5, 'request_delay' => 1, 'max_response_size' => 10485760],
            'discovery' => ['sitemap' => true, 'internal_crawler' => true],
        ];

        $scanSettings = [
            'max_urls' => 1200,
            'max_depth' => 5,
            'concurrency' => 8,
            'request_delay' => 2,
            'max_response_size' => 20971520,
            'discovery_methods' => ['sitemap' => true, 'internal_crawler' => false],
        ];

        $ref = new \ReflectionMethod(ScanWorker::class, 'mergeEffectiveConfig');
        $ref->setAccessible(true);
        $worker = (new \ReflectionClass(ScanWorker::class))->newInstanceWithoutConstructor();

        $merged = $ref->invoke($worker, $baseConfig, $scanSettings);

        $this->assertEquals(1200, $merged['crawler']['default_max_urls'], 'Config snapshot max_urls merged');
        $this->assertEquals(5, $merged['crawler']['default_max_depth'], 'Config snapshot max_depth merged');
        $this->assertEquals(8, $merged['fetching']['concurrency'], 'Config snapshot concurrency merged');
        $this->assertEquals(2, $merged['fetching']['request_delay'], 'Config snapshot request_delay merged');
        $this->assertEquals(20971520, $merged['fetching']['max_response_size'], 'Config snapshot max_response_size merged');
        $this->assertEquals(false, $merged['discovery']['internal_crawler'], 'Discovery methods override merged');
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
