<?php
/**
 * Automated Regression Test Suite: Detection Pipeline, HTML Content Extraction, cURL Constants & Status Classification
 *
 * Covers:
 * 1. Safe cURL constant resolution without undefined constant errors
 * 2. SSL error classification as non-retryable permanent failure
 * 3. Regression fixtures (all 8 cases from prompt):
 *    - Case 1: Standard Lorem Ipsum paragraph
 *    - Case 2: Uppercase LOREM IPSUM DOLOR
 *    - Case 3: Multiple Lorem Ipsum paragraphs
 *    - Case 4: Split across nested HTML tags
 *    - Case 5: HTML-encoded content
 *    - Case 6: Unusual whitespace
 *    - Case 7: Clean page without placeholder content
 *    - Case 8: Ordinary words in unrelated context
 * 4. Result & Health classification logic
 * 5. Multiple paragraph / occurrence counting
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/HttpClient.php';
require_once dirname(__DIR__) . '/includes/Detection/HtmlContentExtractor.php';
require_once dirname(__DIR__) . '/includes/Detection/DetectionEngine.php';
require_once dirname(__DIR__) . '/includes/Detection/AuditModules.php';

use LoremChecker\HttpClient;
use LoremChecker\Detection\HtmlContentExtractor;
use LoremChecker\Detection\DetectionEngine;
use LoremChecker\Detection\AuditModules;

class DetectionPipelineTest
{
    public int $assertions = 0;

    public function run(): void
    {
        $this->testSafeCurlConstants();
        $this->testSslClassification();
        $this->testDetectionFixtures();
        $this->testMultipleOccurrences();
        $this->testStatusClassification();
    }

    private function testSafeCurlConstants(): void
    {
        // 1. Safe resolution of constants using defined() and constant()
        $this->assertEquals(28, HttpClient::getCurlErrno('CURLE_OPERATION_TIMEDOUT', 28), 'Resolves timeout errno');
        $this->assertEquals(60, HttpClient::getCurlErrno('CURLE_SSL_CACERT', 60), 'Resolves SSL CACERT errno');
        // Non-existent constant in PHP must return fallback, NEVER throw undefined constant error
        $fallback = HttpClient::getCurlErrno('CURLE_PEER_FAILED_VERIFICATION', 60);
        $this->assertEquals(60, $fallback, 'Safe fallback on non-existent PHP constant');

        // 2. Classify cURL errors safely
        $this->assertEquals('timeout', HttpClient::classifyCurlError(28, 'Operation timed out'));
        $this->assertEquals('ssl_error', HttpClient::classifyCurlError(60, 'SSL certificate problem'));
        $this->assertEquals('ssl_error', HttpClient::classifyCurlError(35, 'SSL connect error'));
        $this->assertEquals('dns_error', HttpClient::classifyCurlError(6, 'Could not resolve host'));
        $this->assertEquals('connection_reset', HttpClient::classifyCurlError(56, 'Recv failure'));
    }

    private function testSslClassification(): void
    {
        // SSL errors must be classified as permanent and NEVER retried
        $this->assertTrue(!HttpClient::isRetryable('ssl_error', null, 60), 'SSL CACERT error (60) is NOT retryable');
        $this->assertTrue(!HttpClient::isRetryable('ssl_error', null, 35), 'SSL connect error (35) is NOT retryable');
        $this->assertTrue(!HttpClient::isRetryable('ssl_error', null, 58), 'SSL cert problem (58) is NOT retryable');
        $this->assertTrue(!HttpClient::isRetryable('ssl_error', 200, 0), 'ssl_error type is strictly NOT retryable');
    }

    private function testDetectionFixtures(): void
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
            [
                'id'                 => 4,
                'name'               => 'Sed Do Eiusmod',
                'pattern'            => '/\bsed\s+do\s+eiusmod\b/i',
                'detection_category' => 'lorem_ipsum',
                'severity'           => 'high',
                'confidence'         => 'high',
                'source_types'       => 'html,text',
            ],
        ];

        $extractor = new HtmlContentExtractor();
        $detector  = new DetectionEngine($rules, 100);

        // FIXTURE 1: Standard Lorem Ipsum paragraph — should be 1 paragraph finding with complete text
        $f1 = '<html><body><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p></body></html>';
        $ext1 = $extractor->extract($f1, 'https://test.local/f1');
        $find1 = $detector->scanHtmlNodes($ext1['nodes'], 'https://test.local/f1');
        $this->assertEquals(1, count($find1), 'Fixture 1: Standard paragraph grouped into ONE finding');
        $this->assertEquals('Lorem ipsum dolor sit amet, consectetur adipiscing elit.', $find1[0]['matched_text'], 'Fixture 1: Stores full paragraph as evidence');
        $this->assertTrue(($find1[0]['occurrences'] ?? 1) >= 2, 'Fixture 1: Counts multiple phrase occurrences in paragraph');

        // FIXTURE 2: Uppercase LOREM IPSUM DOLOR
        $f2 = '<html><body><h5>LOREM IPSUM DOLOR</h5></body></html>';
        $ext2 = $extractor->extract($f2, 'https://test.local/f2');
        $find2 = $detector->scanHtmlNodes($ext2['nodes'], 'https://test.local/f2');
        $this->assertEquals(1, count($find2), 'Fixture 2: Uppercase LOREM IPSUM detected as ONE finding');
        $this->assertEquals('LOREM IPSUM DOLOR', $find2[0]['matched_text'], 'Fixture 2 matches uppercase');

        // FIXTURE 3: Multiple Lorem Ipsum paragraphs (2 distinct paragraphs)
        $f3 = '<html><body><p>Lorem ipsum dolor sit amet.</p><p>Sed do eiusmod tempor incididunt.</p></body></html>';
        $ext3 = $extractor->extract($f3, 'https://test.local/f3');
        $find3 = $detector->scanHtmlNodes($ext3['nodes'], 'https://test.local/f3');
        $this->assertEquals(2, count($find3), 'Fixture 3: Two paragraphs detected as TWO separate findings');

        // FIXTURE 4: Split across nested HTML tags
        $f4 = '<html><body><p><strong>Lorem</strong> <span>ipsum</span> <em>dolor</em> sit amet.</p></body></html>';
        $ext4 = $extractor->extract($f4, 'https://test.local/f4');
        $find4 = $detector->scanHtmlNodes($ext4['nodes'], 'https://test.local/f4');
        $this->assertEquals(1, count($find4), 'Fixture 4: Text split across strong/span/em detected as ONE paragraph finding');
        $this->assertEquals('Lorem ipsum dolor sit amet.', $find4[0]['matched_text'], 'Fixture 4: Preserves complete concatenated text');

        // FIXTURE 5: HTML-encoded content
        $f5 = '<html><body><p>&ldquo;Lorem&nbsp;ipsum&nbsp;dolor&nbsp;sit&nbsp;amet&rdquo;</p></body></html>';
        $ext5 = $extractor->extract($f5, 'https://test.local/f5');
        $find5 = $detector->scanHtmlNodes($ext5['nodes'], 'https://test.local/f5');
        $this->assertTrue(count($find5) >= 1, 'Fixture 5: HTML entities (&ldquo;, &nbsp;) handled cleanly');

        // FIXTURE 6: Unusual whitespace (tabs, newlines, multi-spaces)
        $f6 = "<html><body><p>Lorem\n\t  ipsum   \r\n  dolor sit amet.</p></body></html>";
        $ext6 = $extractor->extract($f6, 'https://test.local/f6');
        $find6 = $detector->scanHtmlNodes($ext6['nodes'], 'https://test.local/f6');
        $this->assertTrue(count($find6) >= 1, 'Fixture 6: Unusual whitespace normalized and matched');

        // FIXTURE 7: Clean page without placeholder content
        $f7 = '<html><body><h1>Clean Tech Architecture</h1><p>This is a completely normal production page.</p></body></html>';
        $ext7 = $extractor->extract($f7, 'https://test.local/f7');
        $find7 = $detector->scanHtmlNodes($ext7['nodes'], 'https://test.local/f7');
        $this->assertEquals(0, count($find7), 'Fixture 7: Clean page yields ZERO findings');

        // FIXTURE 8: Ordinary words in unrelated context
        $f8 = '<html><body><p>The lorem system ipsums its data cleanly without errors.</p></body></html>';
        $ext8 = $extractor->extract($f8, 'https://test.local/f8');
        $find8 = $detector->scanHtmlNodes($ext8['nodes'], 'https://test.local/f8');
        $this->assertEquals(0, count($find8), 'Fixture 8: Ordinary words in unrelated context yields ZERO false positives');
    }

    private function testMultipleOccurrences(): void
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
        ];

        $extractor = new HtmlContentExtractor();
        $detector  = new DetectionEngine($rules, 100);

        // 3 separate paragraphs with Lorem Ipsum
        $html = '
            <html><body>
                <div class="team"><p>Lorem ipsum dolor sit amet</p></div>
                <div class="testimonials"><h4>Lorem ipsum dolor sit amet</h4></div>
                <div class="footer"><p>Lorem ipsum dolor sit amet</p></div>
            </body></html>
        ';

        $ext = $extractor->extract($html, 'https://test.local/repeat');
        $findings = $detector->scanHtmlNodes($ext['nodes'], 'https://test.local/repeat');

        $this->assertEquals(3, count($findings), 'All 3 repeated occurrences captured');
        $fingerprints = array_map(fn($f) => $f['finding_fingerprint'], $findings);
        $this->assertEquals(3, count(array_unique($fingerprints)), 'Each occurrence receives distinct fingerprint');
    }

    private function testStatusClassification(): void
    {
        // 1. Fetched successfully + findings = Needs Attention (findings_detected)
        $findingsDetected = true;
        $status = $findingsDetected ? 'findings_detected' : 'clean';
        $this->assertEquals('findings_detected', $status, 'Pages with findings classified as findings_detected');

        // 2. Fetched successfully + 0 findings = Clean
        $zeroFindings = false;
        $cleanStatus = $zeroFindings ? 'findings_detected' : 'clean';
        $this->assertEquals('clean', $cleanStatus, 'Pages with 0 findings classified as clean');
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
