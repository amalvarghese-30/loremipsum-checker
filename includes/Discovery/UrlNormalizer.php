<?php
/**
 * URL Normalizer
 *
 * Central URL normalization service.
 * Handles relative, absolute, protocol-relative URLs.
 * Provides consistent URL hashing for deduplication.
 * Does NOT block private IPs or localhost — this is an internal auditing tool.
 */

namespace LoremChecker\Discovery;

class UrlNormalizer
{
    // Query parameters to strip (tracking-only params)
    private array $trackingParams = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'utm_id', 'utm_source_platform', 'utm_creative_format', 'utm_marketing_tactic',
        'gclid', 'gclsrc', 'gad_source', 'fbclid', '_ga', '_gid', 'ref',
        'mc_cid', 'mc_eid', 'msclkid', 'twclid',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Normalize a URL to a canonical form for deduplication.
     *
     * @param string $url     The URL to normalize (may be absolute or relative)
     * @param string $baseUrl The base URL for resolving relative URLs
     * @return string|null    Normalized URL, or null if not a valid HTTP(S) URL
     */
    public function normalize(string $url, string $baseUrl = ''): ?string
    {
        $url = trim($url);

        if (empty($url)) {
            return null;
        }

        // Skip non-web schemes
        if (preg_match('/^(javascript|mailto|tel|data|blob|ftp):/i', $url)) {
            return null;
        }

        // Skip bare anchors
        if ($url === '#' || str_starts_with($url, '#')) {
            return null;
        }

        // Resolve relative and protocol-relative URLs
        $resolved = $this->resolveRelative($url, $baseUrl);
        if ($resolved === null) {
            return null;
        }

        $parts = parse_url($resolved);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        // Only HTTP and HTTPS
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        // Normalize hostname: lowercase
        $host = strtolower($parts['host']);

        // Remove default ports
        $port = $parts['port'] ?? null;
        if ($port !== null) {
            if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
                $port = null;
            }
        }

        // Normalize path: resolve dot segments, preserve case
        $path = $parts['path'] ?? '/';
        $path = $this->removeDotSegments($path);
        if (empty($path)) {
            $path = '/';
        }

        // Handle query string: remove tracking params, sort for determinism
        $query = $parts['query'] ?? null;
        if ($query !== null) {
            $query = $this->normalizeQuery($query);
        }

        // Build normalized URL (no fragment — fragments are page sections, not separate pages)
        $normalized  = $scheme . '://';
        $normalized .= $host;
        if ($port !== null) {
            $normalized .= ':' . $port;
        }
        $normalized .= $path;
        if ($query !== null && $query !== '') {
            $normalized .= '?' . $query;
        }

        return $normalized;
    }

    /**
     * Generate a stable SHA-256 hash of a normalized URL for use as a DB key.
     */
    public function getUrlHash(string $normalizedUrl): string
    {
        return hash('sha256', $normalizedUrl);
    }

    /**
     * Resolve a relative URL against a base URL.
     *
     * @return string|null Absolute URL, or null if resolution failed
     */
    public function resolveRelative(string $url, string $base): ?string
    {
        if (empty($url)) {
            return null;
        }

        // Already absolute
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        // Protocol-relative
        if (str_starts_with($url, '//')) {
            $baseParts = parse_url($base);
            $scheme    = $baseParts['scheme'] ?? 'https';
            return $scheme . ':' . $url;
        }

        if (empty($base)) {
            return null;
        }

        $baseParts = parse_url($base);
        if (!$baseParts || empty($baseParts['host'])) {
            return null;
        }

        $scheme = $baseParts['scheme'] ?? 'https';
        $host   = $baseParts['host'];
        $port   = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

        // Root-relative
        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$port}{$url}";
        }

        // Document-relative
        $basePath = $baseParts['path'] ?? '/';
        if (str_ends_with($basePath, '/')) {
            $dir = $basePath;
        } else {
            $dir = dirname($basePath);
            if ($dir === '.' || $dir === '\\') {
                $dir = '/';
            }
            $dir = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        }

        return "{$scheme}://{$host}{$port}{$dir}{$url}";
    }

    /**
     * Check whether a URL is within the crawl scope relative to the base URL.
     *
     * @param string $url     URL to check
     * @param string $baseUrl Scan base URL
     * @param string $scope   'host' (same host only) | 'domain' (same domain + subdomains)
     */
    public function isSameScope(string $url, string $baseUrl, string $scope = 'host'): bool
    {
        $urlParts  = parse_url($url);
        $baseParts = parse_url($baseUrl);

        if (!$urlParts || !$baseParts) {
            return false;
        }

        $urlHost  = strtolower($urlParts['host'] ?? '');
        $baseHost = strtolower($baseParts['host'] ?? '');
        $urlPort  = $urlParts['port'] ?? null;
        $basePort = $baseParts['port'] ?? null;

        if (empty($urlHost) || empty($baseHost)) {
            return false;
        }

        switch ($scope) {
            case 'host':
                // Exact host match (port-sensitive)
                return $urlHost === $baseHost && $urlPort === $basePort;

            case 'domain':
                // Same domain: allow subdomains of base, same port
                $urlDomain  = $this->extractDomain($urlHost);
                $baseDomain = $this->extractDomain($baseHost);
                return $urlDomain === $baseDomain && $urlPort === $basePort;

            default:
                return $urlHost === $baseHost && $urlPort === $basePort;
        }
    }

    /**
     * Extract the registrable domain from a hostname.
     * Very simplified: last two labels for public hosts, full host for IP/localhost.
     */
    public function extractDomain(string $host): string
    {
        // If it's an IP or localhost, return as-is
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            || $host === 'localhost'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
        ) {
            return $host;
        }

        $parts = explode('.', $host);
        if (count($parts) >= 2) {
            return implode('.', array_slice($parts, -2));
        }
        return $host;
    }

    /**
     * Extract just the host (and port if non-default) from a URL.
     */
    public function extractHost(string $url): string
    {
        $parts  = parse_url($url);
        $host   = strtolower($parts['host'] ?? '');
        $port   = $parts['port'] ?? null;
        $scheme = strtolower($parts['scheme'] ?? 'http');

        if ($port !== null
            && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))
        ) {
            return $host . ':' . $port;
        }

        return $host;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function normalizeQuery(string $query): string
    {
        parse_str($query, $params);

        // Remove tracking parameters
        foreach ($this->trackingParams as $param) {
            unset($params[$param]);
        }

        if (empty($params)) {
            return '';
        }

        // Sort for determinism
        ksort($params);

        return http_build_query($params);
    }

    private function removeDotSegments(string $path): string
    {
        $output = [];
        $parts  = explode('/', $path);

        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($output);
            } elseif ($part !== '.') {
                $output[] = $part;
            }
        }

        return implode('/', $output);
    }
}
