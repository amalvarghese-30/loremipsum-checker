<?php
/**
 * HTML Content Extractor
 *
 * Uses PHP DOMDocument and DOMXPath to inspect webpages:
 * - Visible text vs hidden text (style="display:none", visibility:hidden, hidden attr)
 * - HTML comments (<!-- ... -->)
 * - Meta tags (<meta name="description">, <title>)
 * - Inline scripts and embedded JSON (<script type="application/ld+json">)
 * - External JS (<script src="...">) and CSS (<link rel="stylesheet" href="...">)
 */

namespace LoremChecker\Detection;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

class HtmlContentExtractor
{
    /**
     * Extract structured content nodes and external resource links from HTML.
     *
     * @param string $html
     * @param string $pageUrl
     * @return array [
     *   'nodes'       => array of content items,
     *   'resources'   => array of discovered external scripts/styles,
     *   'page_title'  => ?string,
     *   'meta_desc'   => ?string,
     *   'word_count'  => int
     * ]
     */
    public function extract(string $html, string $pageUrl): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        // Load with UTF-8 hint
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $nodes = [];
        $resources = [];
        $pageTitle = null;
        $metaDesc  = null;
        $allText   = '';

        // 1. Title
        $titleNodes = $dom->getElementsByTagName('title');
        if ($titleNodes->length > 0) {
            $pageTitle = trim($titleNodes->item(0)->textContent);
            if ($pageTitle !== '') {
                $nodes[] = [
                    'source_type'  => 'html',
                    'text'         => $pageTitle,
                    'html_element' => 'title',
                    'css_selector' => 'head > title',
                    'section_name' => 'Metadata',
                    'is_visible'   => false,
                    'line_number'  => $titleNodes->item(0)->getLineNo(),
                ];
            }
        }

        // 1b. Links for broken-link audit
        $extractedLinks = [];
        $aNodes = $dom->getElementsByTagName('a');
        foreach ($aNodes as $a) {
            if ($a->hasAttribute('href')) {
                $href = trim($a->getAttribute('href'));
                if ($href !== '' && !str_starts_with($href, '#') && !str_starts_with($href, 'javascript:') && !str_starts_with($href, 'mailto:')) {
                    $extractedLinks[] = $href;
                }
            }
        }

        // 2. Meta description
        $metaNodes = $xpath->query('//meta[translate(@name, "DESCRIPTION", "description")="description"]');
        if ($metaNodes && $metaNodes->length > 0) {
            $metaDesc = trim($metaNodes->item(0)->getAttribute('content'));
            if ($metaDesc !== '') {
                $nodes[] = [
                    'source_type'  => 'html',
                    'text'         => $metaDesc,
                    'html_element' => 'meta',
                    'css_selector' => 'meta[name="description"]',
                    'section_name' => 'Metadata',
                    'is_visible'   => false,
                    'line_number'  => $metaNodes->item(0)->getLineNo(),
                ];
            }
        }

        // 3. HTML Comments
        $commentNodes = $xpath->query('//comment()');
        if ($commentNodes) {
            foreach ($commentNodes as $cNode) {
                $commentText = trim($cNode->nodeValue);
                if ($commentText !== '') {
                    $nodes[] = [
                        'source_type'  => 'html_comment',
                        'text'         => $commentText,
                        'html_element' => '#comment',
                        'css_selector' => null,
                        'section_name' => 'HTML Comment',
                        'is_visible'   => false,
                        'line_number'  => $cNode->getLineNo(),
                    ];
                }
            }
        }

        // 4. External resources (<script src="..."> and <link rel="stylesheet">)
        $scriptTags = $dom->getElementsByTagName('script');
        foreach ($scriptTags as $script) {
            $src = trim($script->getAttribute('src'));
            if ($src !== '') {
                $resources[] = [
                    'url'  => $src,
                    'type' => 'javascript',
                ];
            } else {
                // Inline script or embedded JSON
                $typeAttr = strtolower(trim($script->getAttribute('type')));
                $scriptBody = trim($script->nodeValue);
                if ($scriptBody !== '') {
                    if (str_contains($typeAttr, 'json')) {
                        $nodes[] = [
                            'source_type'  => 'embedded_json',
                            'text'         => $scriptBody,
                            'html_element' => 'script[type="' . $typeAttr . '"]',
                            'css_selector' => 'script[type="' . $typeAttr . '"]',
                            'section_name' => 'Embedded JSON',
                            'is_visible'   => false,
                            'line_number'  => $script->getLineNo(),
                        ];
                    } else {
                        $nodes[] = [
                            'source_type'  => 'inline_javascript',
                            'text'         => $scriptBody,
                            'html_element' => 'script',
                            'css_selector' => 'script',
                            'section_name' => 'Inline Script',
                            'is_visible'   => false,
                            'line_number'  => $script->getLineNo(),
                        ];
                    }
                }
            }
        }

        $linkTags = $dom->getElementsByTagName('link');
        foreach ($linkTags as $link) {
            $rel = strtolower(trim($link->getAttribute('rel')));
            if ($rel === 'stylesheet') {
                $href = trim($link->getAttribute('href'));
                if ($href !== '') {
                    $resources[] = [
                        'url'  => $href,
                        'type' => 'css',
                    ];
                }
            }
        }

        // 5. Body elements (paragraphs, headings, lists, buttons, spans, divs, etc.)
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body) {
            $this->walkDom($body, $nodes, $allText);
        }

        $wordCount = str_word_count($allText);

        return [
            'nodes'       => $nodes,
            'resources'   => $resources,
            'page_title'  => $pageTitle,
            'meta_desc'   => $metaDesc,
            'word_count'  => $wordCount,
            'full_text'   => $allText,
            'links'       => $extractedLinks,
        ];
    }

    private function walkDom(DOMNode $node, array &$nodes, string &$allText, bool $parentHidden = false, string $section = 'Main Content', bool $inLeafBlock = false): void
    {
        $isHidden = $parentHidden;

        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);

            // Skip script and style tags inside body (handled separately)
            if (in_array($tag, ['script', 'style', 'svg', 'noscript', 'canvas'], true)) {
                return;
            }

            // Check visibility
            if ($node->hasAttribute('hidden') || $node->getAttribute('aria-hidden') === 'true') {
                $isHidden = true;
            }

            $style = strtolower($node->getAttribute('style'));
            if (str_contains($style, 'display:none') || str_contains($style, 'visibility:hidden') || str_contains($style, 'opacity:0')) {
                $isHidden = true;
            }

            // Detect section names
            if (in_array($tag, ['header', 'nav'], true)) {
                $section = 'Header / Navigation';
            } elseif ($tag === 'footer') {
                $section = 'Footer';
            } elseif ($tag === 'aside') {
                $section = 'Sidebar / Aside';
            } elseif (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                $section = 'Heading: ' . strtoupper($tag);
            }

            $blockTags = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote', 'td', 'th', 'button', 'figcaption', 'label', 'dt', 'dd', 'caption', 'summary'];
            $genericContainers = ['div', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'main', 'form'];

            $isBlockTag = in_array($tag, $blockTags, true);
            $isGenericContainer = in_array($tag, $genericContainers, true);

            // Check if element has child block elements
            $hasBlockChildren = false;
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $cTag = strtolower($child->tagName);
                    if (in_array($cTag, $blockTags, true) || in_array($cTag, $genericContainers, true)) {
                        $hasBlockChildren = true;
                        break;
                    }
                }
            }

            $isLeafBlock = $isBlockTag || ($isGenericContainer && !$hasBlockChildren);

            // Only extract text if we are not already inside an extracted leaf block
            if (!$inLeafBlock) {
                if ($isLeafBlock) {
                    // Extract full text of the leaf block, preserving words split across inline formatting (strong, em, span, a)
                    $rawText = $node->textContent;
                    $cleanText = html_entity_decode($rawText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $cleanText = preg_replace('/[\s\xc2\xa0]+/u', ' ', $cleanText);
                    $cleanText = trim($cleanText);

                    if ($cleanText !== '') {
                        $allText .= ' ' . $cleanText;
                        $nodes[] = [
                            'source_type'  => $isHidden ? 'html_hidden' : 'html_visible',
                            'text'         => $cleanText,
                            'html_element' => $tag,
                            'css_selector' => $this->buildCssSelector($node),
                            'section_name' => $section,
                            'is_visible'   => !$isHidden,
                            'line_number'  => $node->getLineNo(),
                        ];
                    }
                } elseif ($hasBlockChildren) {
                    // Extract any direct loose text within container before/between block children
                    $directText = '';
                    foreach ($node->childNodes as $child) {
                        if ($child instanceof DOMText) {
                            $directText .= $child->wholeText;
                        }
                    }
                    $cleanDirect = html_entity_decode($directText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $cleanDirect = trim(preg_replace('/[\s\xc2\xa0]+/u', ' ', $cleanDirect));
                    if ($cleanDirect !== '') {
                        $allText .= ' ' . $cleanDirect;
                        $nodes[] = [
                            'source_type'  => $isHidden ? 'html_hidden' : 'html_visible',
                            'text'         => $cleanDirect,
                            'html_element' => $tag,
                            'css_selector' => $this->buildCssSelector($node),
                            'section_name' => $section,
                            'is_visible'   => !$isHidden,
                            'line_number'  => $node->getLineNo(),
                        ];
                    }
                }
            }

            // Also check attributes containing relevant text (e.g. placeholder, title, alt, aria-label)
            foreach (['placeholder', 'title', 'alt', 'aria-label'] as $attr) {
                if ($node->hasAttribute($attr)) {
                    $attrVal = trim($node->getAttribute($attr));
                    $attrVal = html_entity_decode($attrVal, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $attrVal = trim(preg_replace('/[\s\xc2\xa0]+/u', ' ', $attrVal));
                    if ($attrVal !== '') {
                        $nodes[] = [
                            'source_type'  => 'html_attribute',
                            'text'         => $attrVal,
                            'html_element' => "{$tag}[{$attr}]",
                            'css_selector' => $this->buildCssSelector($node),
                            'section_name' => "Attribute: {$attr}",
                            'is_visible'   => !$isHidden,
                            'line_number'  => $node->getLineNo(),
                        ];
                    }
                }
            }
        }

        $nowInLeaf = $inLeafBlock || (!empty($isLeafBlock));
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $this->walkDom($child, $nodes, $allText, $isHidden, $section, $nowInLeaf);
            }
        }
    }

    private function buildCssSelector(DOMElement $element): string
    {
        $path = [];
        $curr = $element;

        while ($curr instanceof DOMElement && strtolower($curr->tagName) !== 'body' && strtolower($curr->tagName) !== 'html') {
            $tag = strtolower($curr->tagName);
            if ($curr->hasAttribute('id')) {
                $path[] = $tag . '#' . $curr->getAttribute('id');
                break; // IDs are unique, can anchor selector
            }

            // Find index among siblings of same tag
            $index = 1;
            $prev = $curr->previousSibling;
            while ($prev) {
                if ($prev instanceof DOMElement && strtolower($prev->tagName) === $tag) {
                    $index++;
                }
                $prev = $prev->previousSibling;
            }

            if ($curr->hasAttribute('class')) {
                $classes = explode(' ', trim($curr->getAttribute('class')));
                $firstClass = trim($classes[0] ?? '');
                if ($firstClass !== '') {
                    $tag .= '.' . $firstClass;
                }
            }

            $path[] = "{$tag}:nth-of-type({$index})";
            $curr = $curr->parentNode;
        }

        return !empty($path) ? implode(' > ', array_reverse($path)) : strtolower($element->tagName);
    }
}
