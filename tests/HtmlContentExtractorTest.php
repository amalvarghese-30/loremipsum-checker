<?php
/**
 * HTML Content Extractor Unit Tests
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Detection/HtmlContentExtractor.php';

use LoremChecker\Detection\HtmlContentExtractor;

class HtmlContentExtractorTest
{
    private HtmlContentExtractor $extractor;
    public int $assertions = 0;

    public function __construct()
    {
        $this->extractor = new HtmlContentExtractor();
    }

    public function run(): void
    {
        $this->testExtraction();
    }

    private function testExtraction(): void
    {
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <title>Test Page Title</title>
            <meta name="description" content="This is the meta description.">
            <link rel="stylesheet" href="/assets/style.css">
            <script src="/assets/app.js"></script>
        </head>
        <body>
            <!-- This is an HTML comment -->
            <header>
                <h1>Main Heading</h1>
            </header>
            <main>
                <p>Visible paragraph text.</p>
                <div style="display:none">Hidden div content.</div>
                <input type="text" placeholder="Search placeholder" />
            </main>
            <script type="application/ld+json">
                {"@type": "WebPage", "name": "JSON data"}
            </script>
        </body>
        </html>';

        $data = $this->extractor->extract($html, 'https://example.com/test');

        $this->assert($data['page_title'] === 'Test Page Title', 'Extracted page title');
        $this->assert($data['meta_desc'] === 'This is the meta description.', 'Extracted meta description');
        $this->assert(count($data['resources']) === 2, 'Extracted external stylesheet and script');

        $nodeSources = array_column($data['nodes'], 'source_type');
        $this->assert(in_array('html_comment', $nodeSources, true), 'Extracted HTML comment');
        $this->assert(in_array('html_visible', $nodeSources, true), 'Extracted visible text');
        $this->assert(in_array('html_hidden', $nodeSources, true), 'Extracted hidden text');
        $this->assert(in_array('html_attribute', $nodeSources, true), 'Extracted input placeholder attribute');
        $this->assert(in_array('embedded_json', $nodeSources, true), 'Extracted embedded JSON-LD');
    }

    private function assert(bool $condition, string $msg): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$msg}");
        }
    }
}
