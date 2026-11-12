<?php
/**
 * Detection Engine
 *
 * Core engine for detecting Lorem Ipsum and custom placeholder content.
 * Supports HTML, JavaScript, CSS, and JSON text scanning.
 * Computes context snippets, severity, confidence, and stable finding fingerprints.
 */

namespace LoremChecker\Detection;

class DetectionEngine
{
    private array $rules;
    private int   $contextChars;

    public function __construct(array $rules = [], int $contextChars = 150)
    {
        $this->rules = $rules;
        $this->contextChars = $contextChars;
    }

    /**
     * Scan extracted HTML nodes for matches.
     * Enforces paragraph-level grouping:
     * One paragraph / leaf container = ONE finding.
     * Multiple phrase occurrences within the paragraph are counted in 'occurrences'.
     * The complete paragraph text is stored as evidence in 'matched_text'.
     *
     * @param array  $nodes   List of nodes from HtmlContentExtractor
     * @param string $pageUrl The webpage URL
     * @return array List of findings
     */
    public function scanHtmlNodes(array $nodes, string $pageUrl): array
    {
        $findings = [];
        $seenFingerprints = [];

        foreach ($nodes as $node) {
            $text = $node['text'] ?? '';
            if ($text === '') {
                continue;
            }

            $matches = $this->matchText($text, 'html');
            if (empty($matches)) {
                continue;
            }

            // Group matches for this paragraph/node by detection category (e.g. lorem_ipsum, placeholder)
            $groupedByCategory = [];
            foreach ($matches as $m) {
                $cat = $m['rule']['detection_category'] ?? 'lorem_ipsum';
                if (!isset($groupedByCategory[$cat])) {
                    $groupedByCategory[$cat] = [
                        'rule'        => $m['rule'],
                        'occurrences' => 0,
                        'phrases'     => [],
                        'first_offset'=> $m['offset'],
                    ];
                }
                $groupedByCategory[$cat]['occurrences']++;
                $groupedByCategory[$cat]['phrases'][] = $m['matched_text'];
                // Keep highest severity rule
                if (($m['rule']['severity'] ?? '') === 'high') {
                    $groupedByCategory[$cat]['rule'] = $m['rule'];
                }
            }

            foreach ($groupedByCategory as $category => $groupData) {
                $rule = $groupData['rule'];
                $severity = $this->adjustSeverity($rule['severity'], $node);
                $confidence = $rule['confidence'];
                $selector = $node['css_selector'] ?? '';

                // Deduplication check: smallest meaningful text block / DOM selector
                // Clean normalized paragraph string
                $normalizedParagraph = trim($text);
                $fingerprint = hash(
                    'sha256',
                    $pageUrl . '|html|' . $category . '|' . strtolower($normalizedParagraph) . '|' . $selector
                );

                if (isset($seenFingerprints[$fingerprint])) {
                    continue;
                }
                $seenFingerprints[$fingerprint] = true;

                $uniquePhrases = array_unique($groupData['phrases']);
                $contextSummary = count($uniquePhrases) > 1 
                    ? implode(', ', array_slice($uniquePhrases, 0, 3)) . ' (' . $groupData['occurrences'] . ' occurrences)'
                    : $uniquePhrases[0] . ' (' . $groupData['occurrences'] . ' occurrence' . ($groupData['occurrences'] > 1 ? 's' : '') . ')';

                $findings[] = [
                    'page_url'            => $pageUrl,
                    'resource_url'        => null,
                    'resource_type'       => 'html',
                    'matched_text'        => $normalizedParagraph, // Store complete paragraph text
                    'occurrences'         => $groupData['occurrences'], // Total phrase matches in paragraph
                    'matched_phrases'     => $groupData['phrases'],
                    'surrounding_context' => "Matched: {$contextSummary}",
                    'html_element'        => $node['html_element'] ?? null,
                    'css_selector'        => $selector,
                    'section_name'        => $node['section_name'] ?? null,
                    'line_number'         => $node['line_number'] ?? null,
                    'character_position'  => $groupData['first_offset'],
                    'is_visible'          => $node['is_visible'] ?? true,
                    'detection_rule_id'   => $rule['id'] ?? null,
                    'detection_category'  => $category,
                    'severity'            => $severity,
                    'confidence'          => $confidence,
                    'finding_fingerprint' => $fingerprint,
                ];
            }
        }

        return $findings;
    }

    /**
     * Scan JavaScript resource text.
     */
    public function scanJavaScript(string $jsContent, string $resourceUrl, string $pageUrl): array
    {
        $findings = [];
        $matches = $this->matchText($jsContent, 'javascript');

        foreach ($matches as $m) {
            $isComment = $this->isJsComment($jsContent, $m['offset']);
            $severity = $isComment ? 'low' : ($m['rule']['severity'] ?? 'medium');

            $fingerprint = $this->calcFingerprint($pageUrl, 'javascript', $m['matched_text']);

            $findings[] = [
                'page_url'            => $pageUrl,
                'resource_url'        => $resourceUrl,
                'resource_type'       => 'javascript',
                'matched_text'        => $m['matched_text'],
                'surrounding_context' => $m['context'],
                'html_element'        => 'script',
                'css_selector'        => null,
                'section_name'        => $isComment ? 'JS Comment' : 'JS Code / String',
                'line_number'         => $this->calcLineNumber($jsContent, $m['offset']),
                'character_position'  => $m['offset'],
                'is_visible'          => false,
                'detection_rule_id'   => $m['rule']['id'] ?? null,
                'detection_category'  => $m['rule']['detection_category'] ?? 'lorem_ipsum',
                'severity'            => $severity,
                'confidence'          => $m['rule']['confidence'] ?? 'medium',
                'finding_fingerprint' => $fingerprint,
            ];
        }

        return $findings;
    }

    /**
     * Scan CSS resource text.
     */
    public function scanCss(string $cssContent, string $resourceUrl, string $pageUrl): array
    {
        $findings = [];
        $matches = $this->matchText($cssContent, 'css');

        foreach ($matches as $m) {
            $fingerprint = $this->calcFingerprint($pageUrl, 'css', $m['matched_text']);

            $findings[] = [
                'page_url'            => $pageUrl,
                'resource_url'        => $resourceUrl,
                'resource_type'       => 'css',
                'matched_text'        => $m['matched_text'],
                'surrounding_context' => $m['context'],
                'html_element'        => 'link[rel="stylesheet"]',
                'css_selector'        => null,
                'section_name'        => 'CSS Content / Comment',
                'line_number'         => $this->calcLineNumber($cssContent, $m['offset']),
                'character_position'  => $m['offset'],
                'is_visible'          => false,
                'detection_rule_id'   => $m['rule']['id'] ?? null,
                'detection_category'  => $m['rule']['detection_category'] ?? 'lorem_ipsum',
                'severity'            => 'low',
                'confidence'          => $m['rule']['confidence'] ?? 'medium',
                'finding_fingerprint' => $fingerprint,
            ];
        }

        return $findings;
    }

    /**
     * Scan JSON structure recursively.
     */
    public function scanJson(string $jsonString, string $resourceUrl, string $pageUrl): array
    {
        $findings = [];
        $decoded = @json_decode($jsonString, true);
        if (!is_array($decoded)) {
            return $findings;
        }

        $items = [];
        $this->flattenJson($decoded, '', $items);

        foreach ($items as $item) {
            $keyPath = $item['path'];
            $valText = (string)$item['value'];

            $matches = $this->matchText($valText, 'json');
            foreach ($matches as $m) {
                $fingerprint = $this->calcFingerprint($pageUrl, 'json', $m['matched_text'] . $keyPath);

                $findings[] = [
                    'page_url'            => $pageUrl,
                    'resource_url'        => $resourceUrl,
                    'resource_type'       => 'json',
                    'matched_text'        => $m['matched_text'],
                    'surrounding_context' => "Key: {$keyPath} => " . $m['context'],
                    'html_element'        => null,
                    'css_selector'        => null,
                    'section_name'        => "JSON Property: {$keyPath}",
                    'line_number'         => null,
                    'character_position'  => $m['offset'],
                    'is_visible'          => false,
                    'detection_rule_id'   => $m['rule']['id'] ?? null,
                    'detection_category'  => $m['rule']['detection_category'] ?? 'lorem_ipsum',
                    'severity'            => $m['rule']['severity'] ?? 'medium',
                    'confidence'          => $m['rule']['confidence'] ?? 'high',
                    'finding_fingerprint' => $fingerprint,
                ];
            }
        }

        return $findings;
    }

    // ── Matching helpers ──────────────────────────────────────────────────────

    private function matchText(string $text, string $sourceType): array
    {
        $matches = [];
        $normalizedText = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalizedText = preg_replace('/[\s\xc2\xa0]+/u', ' ', $normalizedText);

        foreach ($this->rules as $rule) {
            // Check if rule applies to this source type
            $sources = explode(',', $rule['source_types'] ?? 'html,javascript,css,json,text');
            $sources = array_map('trim', $sources);
            if (!in_array($sourceType, $sources, true) && !in_array('text', $sources, true)) {
                continue;
            }

            $pattern = $rule['pattern'];
            // Normalize any double-escaped whitespace or word boundary tokens stored in SQLite/DB
            $pattern = str_replace(['\\\\s', '\\\\b', '\\\\w', '\\\\d'], ['\\s', '\\b', '\\w', '\\d'], $pattern);

            // Safe regex check
            if (@preg_match_all($pattern, $normalizedText, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $matchTuple) {
                    $matchedStr = $matchTuple[0];
                    $offset     = $matchTuple[1];

                    $context = $this->extractContext($normalizedText, $offset, strlen($matchedStr));

                    $matches[] = [
                        'rule'         => $rule,
                        'matched_text' => $matchedStr,
                        'offset'       => $offset,
                        'context'      => $context,
                    ];
                }
            }
        }

        return $matches;
    }

    private function extractContext(string $text, int $offset, int $length): string
    {
        $start = max(0, $offset - $this->contextChars);
        $prefix = $start > 0 ? '...' : '';

        $end = min(strlen($text), $offset + $length + $this->contextChars);
        $suffix = $end < strlen($text) ? '...' : '';

        $context = substr($text, $start, $end - $start);
        $context = trim(preg_replace('/\s+/', ' ', $context));

        return $prefix . $context . $suffix;
    }

    private function adjustSeverity(string $baseSeverity, array $node): string
    {
        // If it's a hidden element or HTML comment, reduce severity
        if (empty($node['is_visible']) || ($node['source_type'] ?? '') === 'html_comment') {
            return 'low';
        }

        $tag = strtolower($node['html_element'] ?? '');
        // Heading or title = high
        if (in_array($tag, ['h1', 'h2', 'h3', 'title'], true)) {
            return 'high';
        }

        // Footer = medium
        if (str_contains(strtolower($node['section_name'] ?? ''), 'footer')) {
            return 'medium';
        }

        return $baseSeverity;
    }

    private function calcFingerprint(string $pageUrl, string $resourceType, string $matchedText, string $cssSelector = '', int $offset = 0): string
    {
        return hash('sha256', $pageUrl . '|' . $resourceType . '|' . strtolower(trim($matchedText)) . '|' . $cssSelector . '|' . $offset);
    }

    private function calcLineNumber(string $text, int $offset): int
    {
        $substr = substr($text, 0, $offset);
        return substr_count($substr, "\n") + 1;
    }

    private function isJsComment(string $js, int $offset): bool
    {
        $lineStart = strrpos(substr($js, 0, $offset), "\n");
        $lineStart = ($lineStart === false) ? 0 : $lineStart + 1;
        $lineBefore = substr($js, $lineStart, $offset - $lineStart);
        return str_contains($lineBefore, '//') || str_contains($lineBefore, '/*');
    }

    private function flattenJson(array $data, string $prefix, array &$items): void
    {
        foreach ($data as $key => $val) {
            $path = $prefix === '' ? (string)$key : "{$prefix}.{$key}";
            if (is_array($val)) {
                $this->flattenJson($val, $path, $items);
            } elseif (is_string($val) && trim($val) !== '') {
                $items[] = ['path' => $path, 'value' => $val];
            }
        }
    }
}
