<?php
/**
 * URL Normalizer Unit Tests
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Discovery/UrlNormalizer.php';

use LoremChecker\Discovery\UrlNormalizer;

class UrlNormalizerTest
{
    private UrlNormalizer $normalizer;
    public int $assertions = 0;

    public function __construct()
    {
        $this->normalizer = new UrlNormalizer();
    }

    public function run(): void
    {
        $this->testBasicNormalization();
        $this->testRelativeResolution();
        $this->testTrackingParamRemoval();
        $this->testScopeChecking();
        $this->testHashStability();
    }

    private function testBasicNormalization(): void
    {
        $norm = $this->normalizer->normalize('HTTPS://Example.COM:443/Path/To/Page#section');
        $this->assert($norm === 'https://example.com/Path/To/Page', 'Normalizes host, default port, and removes fragment');

        $customPort = $this->normalizer->normalize('http://localhost:8080/test');
        $this->assert($customPort === 'http://localhost:8080/test', 'Preserves custom port and localhost');

        $privateIp = $this->normalizer->normalize('http://192.168.1.100:3000/api');
        $this->assert($privateIp === 'http://192.168.1.100:3000/api', 'Preserves private IP address and port');

        $dotPath = $this->normalizer->normalize('https://example.com/a/b/../c/./d');
        $this->assert($dotPath === 'https://example.com/a/c/d', 'Resolves dot segments');
    }

    private function testRelativeResolution(): void
    {
        $base = 'https://example.com/blog/article-1/';

        $relRoot = $this->normalizer->resolveRelative('/about', $base);
        $this->assert($relRoot === 'https://example.com/about', 'Resolves root-relative path');

        $relDoc = $this->normalizer->resolveRelative('sub-page', $base);
        $this->assert($relDoc === 'https://example.com/blog/article-1/sub-page', 'Resolves document-relative path');

        $protoRel = $this->normalizer->resolveRelative('//cdn.example.com/app.js', $base);
        $this->assert($protoRel === 'https://cdn.example.com/app.js', 'Resolves protocol-relative URL');
    }

    private function testTrackingParamRemoval(): void
    {
        $url = 'https://example.com/landing?utm_source=google&page=2&fbclid=123&sort=asc&gclid=456';
        $norm = $this->normalizer->normalize($url);
        $this->assert($norm === 'https://example.com/landing?page=2&sort=asc', 'Strips marketing tracking parameters while preserving meaningful query params');
    }

    private function testScopeChecking(): void
    {
        $base = 'https://mysite.com';

        $this->assert($this->normalizer->isSameScope('https://mysite.com/page1', $base, 'host'), 'Same host is in scope');
        $this->assert(!$this->normalizer->isSameScope('https://sub.mysite.com/page1', $base, 'host'), 'Subdomain out of scope for host-only');
        $this->assert($this->normalizer->isSameScope('https://sub.mysite.com/page1', $base, 'domain'), 'Subdomain in scope for domain mode');
        $this->assert(!$this->normalizer->isSameScope('https://google.com', $base, 'domain'), 'External site out of scope');

        // Internal IPs and localhost scope
        $localBase = 'http://localhost:8000';
        $this->assert($this->normalizer->isSameScope('http://localhost:8000/dashboard', $localBase, 'host'), 'Localhost with custom port in scope');
        $this->assert(!$this->normalizer->isSameScope('http://localhost:8001/dashboard', $localBase, 'host'), 'Different port out of scope');
    }

    private function testHashStability(): void
    {
        $hash1 = $this->normalizer->getUrlHash('https://example.com/test');
        $hash2 = $this->normalizer->getUrlHash('https://example.com/test');
        $this->assert($hash1 === $hash2 && strlen($hash1) === 64, 'URL hash is deterministic 64-char hex');
    }

    private function assert(bool $condition, string $msg): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$msg}");
        }
    }
}
