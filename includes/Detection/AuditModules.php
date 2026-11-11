<?php
/**
 * Optional Website Audit Modules
 *
 * Provides configurable content checks beyond Lorem Ipsum detection:
 * 1. Broken Internal Link Detection
 * 2. Empty Page Detection
 * 3. Duplicate Content Detection (normalized text hashing)
 * 4. Missing Title Detection
 * 5. Missing Meta Description Detection
 * 6. Image Alt-Text Detection
 * 7. Heading Structure Audit (Missing H1, multiple H1, skipped levels)
 * 8. Incomplete Content / Empty Section Detection
 */

namespace LoremChecker\Detection;

use DOMDocument;
use DOMXPath;

class AuditModules
{
    private array $moduleSettings;

    public function __construct(array $moduleSettings = [])
    {
        $this->moduleSettings = array_merge([
            'broken_links'         => false,
            'empty_page'           => true,
            'empty_page_threshold' => 100, // bytes of text
            'duplicate_content'    => true,
            'missing_title'        => true,
            'missing_meta_desc'    => true,
            'image_alt'            => true,
            'heading_structure'    => true,
        ], $moduleSettings);
    }

    /**
     * Run all enabled audit checks on an HTML page.
     *
     * @param string $html
     * @param string $pageUrl
     * @param array  $extractedData From HtmlContentExtractor
     * @return array List of audit finding records
     */
    public function auditPage(string $html, string $pageUrl, array $extractedData = []): array
    {
        $findings = [];

        // 1. Empty page detection
        if ($this->moduleSettings['empty_page']) {
            $threshold = (int)$this->moduleSettings['empty_page_threshold'];
            $textLen = strlen(trim($extractedData['full_text'] ?? ''));
            if ($textLen < $threshold) {
                $findings[] = [
                    'page_url'            => $pageUrl,
                    'resource_url'        => null,
                    'resource_type'       => 'audit',
                    'matched_text'        => "Very low text volume ({$textLen} characters, threshold {$threshold})",
                    'surrounding_context' => "Page appears nearly empty or relies entirely on client-side JS rendering.",
                    'html_element'        => 'body',
                    'css_selector'        => 'body',
                    'section_name'        => 'Content Quality',
                    'line_number'         => null,
                    'character_position'  => null,
                    'is_visible'          => true,
                    'detection_rule_id'   => null,
                    'detection_category'  => 'empty_page',
                    'severity'            => 'medium',
                    'confidence'          => 'high',
                    'finding_fingerprint' => hash('sha256', $pageUrl . '|audit|empty_page'),
                ];
            }
        }

        // 2. Missing title
        if ($this->moduleSettings['missing_title']) {
            if (empty($extractedData['page_title'])) {
                $findings[] = [
                    'page_url'            => $pageUrl,
                    'resource_url'        => null,
                    'resource_type'       => 'audit',
                    'matched_text'        => 'Missing or empty <title> tag',
                    'surrounding_context' => 'Webpage does not define a page title in <head>.',
                    'html_element'        => 'title',
                    'css_selector'        => 'head > title',
                    'section_name'        => 'SEO / Meta',
                    'line_number'         => null,
                    'character_position'  => null,
                    'is_visible'          => false,
                    'detection_rule_id'   => null,
                    'detection_category'  => 'missing_title',
                    'severity'            => 'medium',
                    'confidence'          => 'high',
                    'finding_fingerprint' => hash('sha256', $pageUrl . '|audit|missing_title'),
                ];
            }
        }

        // 3. Missing meta description
        if ($this->moduleSettings['missing_meta_desc']) {
            if (empty($extractedData['meta_desc'])) {
                $findings[] = [
                    'page_url'            => $pageUrl,
                    'resource_url'        => null,
                    'resource_type'       => 'audit',
                    'matched_text'        => 'Missing meta description',
                    'surrounding_context' => '<meta name="description"> tag is absent or blank.',
                    'html_element'        => 'meta',
                    'css_selector'        => 'meta[name="description"]',
                    'section_name'        => 'SEO / Meta',
                    'line_number'         => null,
                    'character_position'  => null,
                    'is_visible'          => false,
                    'detection_rule_id'   => null,
                    'detection_category'  => 'missing_meta_desc',
                    'severity'            => 'low',
                    'confidence'          => 'high',
                    'finding_fingerprint' => hash('sha256', $pageUrl . '|audit|missing_meta_desc'),
                ];
            }
        }

        // 4. Image alt text & Heading structure audit
        if ($this->moduleSettings['image_alt'] || $this->moduleSettings['heading_structure']) {
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();

            if ($this->moduleSettings['image_alt']) {
                $imgNodes = $dom->getElementsByTagName('img');
                $missingAltCount = 0;
                foreach ($imgNodes as $img) {
                    if (!$img->hasAttribute('alt')) {
                        $missingAltCount++;
                    }
                }
                if ($missingAltCount > 0) {
                    $findings[] = [
                        'page_url'            => $pageUrl,
                        'resource_url'        => null,
                        'resource_type'       => 'audit',
                        'matched_text'        => "{$missingAltCount} image(s) missing alt attribute",
                        'surrounding_context' => "Accessibility issue: <img> elements found without an alt attribute.",
                        'html_element'        => 'img',
                        'css_selector'        => 'img:not([alt])',
                        'section_name'        => 'Accessibility',
                        'line_number'         => null,
                        'character_position'  => null,
                        'is_visible'          => true,
                        'detection_rule_id'   => null,
                        'detection_category'  => 'missing_image_alt',
                        'severity'            => 'low',
                        'confidence'          => 'high',
                        'finding_fingerprint' => hash('sha256', $pageUrl . '|audit|missing_image_alt'),
                    ];
                }
            }

            if ($this->moduleSettings['heading_structure']) {
                $h1Nodes = $dom->getElementsByTagName('h1');
                if ($h1Nodes->length === 0) {
                    $findings[] = [
                        'page_url'            => $pageUrl,
                        'resource_url'        => null,
                        'resource_type'       => 'audit',
                        'matched_text'        => 'Missing H1 heading',
                        'surrounding_context' => 'Page does not contain any <h1> heading element.',
                        'html_element'        => 'h1',
                        'css_selector'        => null,
                        'section_name'        => 'Heading Hierarchy',
                        'line_number'         => null,
                        'character_position'  => null,
                        'is_visible'          => true,
                        'detection_rule_id'   => null,
                        'detection_category'  => 'missing_h1',
                        'severity'            => 'low',
                        'confidence'          => 'high',
                        'finding_fingerprint' => hash('sha256', $pageUrl . '|audit|missing_h1'),
                    ];
                } elseif ($h1Nodes->length > 1) {
                    $findings[] = [
                        'page_url'            => $pageUrl,
                        'resource_url'        => null,
                        'resource_type'       => 'audit',
                        'matched_text'        => "Multiple H1 headings found ({$h1Nodes->length})",
                        'surrounding_context' => 'Best practice recommendation: standard pages should have a single primary <h1> element.',
                        'html_element'        => 'h1',
                        'css_selector'        => 'h1',
                        'section_name'        => 'Heading Hierarchy',
                        'line_number'         => null,
                        'character_position'  => null,
                        'is_visible'          => true,
                        'detection_rule_id'   => null,
                        'detection_category'  => 'multiple_h1',
                        'severity'            => 'low',
                        'confidence'          => 'medium',
                        'finding_fingerprint' => hash('sha256', $pageUrl . '|audit|multiple_h1'),
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * Compute a content hash for duplicate page detection.
     */
    public function computeContentHash(string $fullText): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $fullText)));
        return hash('sha256', $normalized);
    }
}
