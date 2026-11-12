<?php
/**
 * Detection Engine Unit Tests
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Detection/DetectionEngine.php';

use LoremChecker\Detection\DetectionEngine;

class DetectionEngineTest
{
    private DetectionEngine $engine;
    public int $assertions = 0;

    public function __construct()
    {
        $rules = [
            [
                'id'                 => 1,
                'name'               => 'Lorem Ipsum Classic',
                'pattern'            => '/lorem\s+ipsum/i',
                'detection_category' => 'lorem_ipsum',
                'severity'           => 'high',
                'confidence'         => 'high',
                'source_types'       => 'html,javascript,css,json,text',
            ],
            [
                'id'                 => 2,
                'name'               => 'Dolor Sit Amet',
                'pattern'            => '/dolor\s+sit\s+amet/i',
                'detection_category' => 'lorem_ipsum',
                'severity'           => 'high',
                'confidence'         => 'high',
                'source_types'       => 'html,javascript,css,json,text',
            ],
            [
                'id'                 => 3,
                'name'               => 'Placeholder Text',
                'pattern'            => '/\bplaceholder\s+text\b/i',
                'detection_category' => 'placeholder',
                'severity'           => 'medium',
                'confidence'         => 'medium',
                'source_types'       => 'html,javascript,css,json,text',
            ],
            [
                'id'                 => 4,
                'name'               => 'Coming Soon',
                'pattern'            => '/\bcoming\s+soon\b/i',
                'detection_category' => 'placeholder',
                'severity'           => 'low',
                'confidence'         => 'low',
                'source_types'       => 'html,javascript,css,json,text',
            ],
        ];

        $this->engine = new DetectionEngine($rules, 50);
    }

    public function run(): void
    {
        $this->testHtmlDetection();
        $this->testJsDetection();
        $this->testJsonDetection();
        $this->testCleanContentNoFalsePositives();
    }

    private function testHtmlDetection(): void
    {
        $nodes = [
            [
                'source_type'  => 'html_visible',
                'text'         => 'Welcome to our company. Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
                'html_element' => 'p',
                'css_selector' => 'main > p',
                'section_name' => 'Main Content',
                'is_visible'   => true,
                'line_number'  => 12,
            ],
            [
                'source_type'  => 'html_hidden',
                'text'         => 'This is a placeholder text inside a modal.',
                'html_element' => 'div',
                'css_selector' => '#modal div',
                'section_name' => 'Modal Dialog',
                'is_visible'   => false,
                'line_number'  => 45,
            ]
        ];

        $findings = $this->engine->scanHtmlNodes($nodes, 'https://example.com/about');
        $this->assert(count($findings) >= 2, 'Found expected matches in HTML nodes');

        $first = $findings[0];
        $this->assert($first['detection_category'] === 'lorem_ipsum', 'Correct category lorem_ipsum');
        $this->assert($first['severity'] === 'high', 'Correct severity high for visible body text');
        $this->assert(str_contains($first['matched_text'], 'Lorem ipsum'), 'Matched paragraph text contains match');
        $this->assert(!empty($first['finding_fingerprint']), 'Fingerprint computed');

        // Check hidden node adjusted severity
        $hiddenFinding = end($findings);
        $this->assert($hiddenFinding['severity'] === 'low', 'Hidden element finding has adjusted severity low');
    }

    private function testJsDetection(): void
    {
        $js = '
            // This is a comment with lorem ipsum inside
            const title = "Welcome";
            const desc = "Dolor sit amet here";
        ';

        $findings = $this->engine->scanJavaScript($js, 'https://example.com/app.js', 'https://example.com/');
        $this->assert(count($findings) === 2, 'Found matches in JavaScript content');

        // One is in comment, one in string
        $commentMatch = $findings[0];
        $this->assert($commentMatch['severity'] === 'low', 'JS comment match severity reduced to low');
    }

    private function testJsonDetection(): void
    {
        $json = json_encode([
            'site' => [
                'name' => 'Acme Corp',
                'tagline' => 'Sample placeholder text for testing',
            ],
            'posts' => [
                ['title' => 'First Post', 'body' => 'Lorem ipsum post content']
            ]
        ]);

        $findings = $this->engine->scanJson($json, 'https://example.com/data.json', 'https://example.com/');
        $this->assert(count($findings) === 2, 'Found matches in recursive JSON values');
        $this->assert(str_contains($findings[0]['section_name'], 'site.tagline') || str_contains($findings[0]['section_name'], 'posts'), 'Identified JSON key path');
    }

    private function testCleanContentNoFalsePositives(): void
    {
        $nodes = [
            [
                'source_type'  => 'html_visible',
                'text'         => 'We provide professional cloud hosting and enterprise software development services.',
                'html_element' => 'p',
                'is_visible'   => true,
            ]
        ];

        $findings = $this->engine->scanHtmlNodes($nodes, 'https://example.com/clean');
        $this->assert(empty($findings), 'No false positives on clean business text');
    }

    private function assert(bool $condition, string $msg): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$msg}");
        }
    }
}
