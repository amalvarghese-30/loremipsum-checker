<?php
/**
 * Central HTTP Fetching Service
 *
 * All HTTP requests in the application go through this class.
 * Provides consistent redirect handling, retries, SSL control,
 * content-type detection and structured response objects.
 *
 * IMPORTANT: Private IPs, localhost and internal hostnames are explicitly
 * supported. This is an internal auditing tool. Do not add SSRF blocking.
 */

namespace LoremChecker;

class HttpClient
{
    private array $config;

    // Track per-domain concurrency and timing for rate control
    private static array $domainRequestTimes = [];

    // Crawler User-Agent — identifiable, not a browser disguise
    private const DEFAULT_UA = 'LoremIpsumChecker/2.0 (Internal Content Audit Tool)';

    // HTTP codes that are temporary failures worth retrying
    private const RETRYABLE_CODES = [408, 425, 429, 500, 502, 503, 504];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'connection_timeout'  => 10,
            'request_timeout'     => 30,
            'max_redirects'       => 10,
            'verify_ssl'          => false,
            'max_response_size'   => 10485760, // 10 MB
            'max_retries'         => 3,
            'retry_base_delay'    => 2,
            'max_retry_delay'     => 60,
            'request_delay'       => 1,        // seconds between same-domain requests
            'user_agent'          => self::DEFAULT_UA,
        ], $config);
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Fetch a single URL.
     *
     * @return array{
     *   requested_url: string,
     *   final_url: string,
     *   http_status: int|null,
     *   content_type: string,
     *   content: string,
     *   headers: array<string,string>,
     *   redirect_chain: array<string>,
     *   duration_ms: int,
     *   response_size: int,
     *   error_type: string|null,
     *   error_message: string|null
     * }
     */
    public function fetch(string $url, array $options = []): array
    {
        $opts = array_merge([
            'timeout'       => $this->config['request_timeout'],
            'max_retries'   => $this->config['max_retries'],
            'apply_delay'   => true,
        ], $options);

        $maxRetries = max(1, (int)$opts['max_retries']);

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            if ($opts['apply_delay'] && $attempt === 1) {
                $this->applyDomainDelay($url);
            }

            $result = $this->doRequest($url, $opts);
            $this->recordDomainRequest($url);

            // Classify and decide whether to retry
            $status = $result['http_status'];

            if ($result['error_type'] === 'curl_error' || $result['error_type'] === 'timeout') {
                if ($attempt < $maxRetries) {
                    $delay = $this->calcRetryDelay($attempt, null);
                    sleep($delay);
                    continue;
                }
            }

            if ($status !== null && in_array($status, self::RETRYABLE_CODES, true)) {
                if ($attempt < $maxRetries) {
                    $retryAfter = $result['headers']['retry-after'] ?? null;
                    $delay = $this->calcRetryDelay($attempt, $retryAfter);
                    sleep($delay);
                    continue;
                }
            }

            // Classify the response error_type properly
            $result = $this->classifyResponse($result);
            return $result;
        }

        return $this->errorResponse($url, 'max_retries_exceeded', 'Maximum retry attempts exceeded');
    }

    /**
     * Fetch multiple URLs concurrently using cURL Multi.
     *
     * @param array<string>  $urls
     * @param callable       $onComplete  Called with (url, response) for each completed request
     * @param array          $options     Shared fetch options
     */
    public function fetchMulti(array $urls, callable $onComplete, array $options = []): void
    {
        $concurrency = (int)($options['concurrency'] ?? 5);
        $queue       = $urls;
        $running     = [];   // handle_id => ['url' => ..., 'started_at' => ...]
        $headerBufs  = [];   // handle_id => header string buffer
        $bodyBufs    = [];   // handle_id => body string

        $mh = curl_multi_init();

        $addNext = function () use (&$queue, &$running, &$headerBufs, &$bodyBufs, $mh, $options) {
            if (empty($queue)) {
                return;
            }
            $url = array_shift($queue);
            $ch  = $this->buildHandle($url, $options);
            $id  = (int)$ch;

            $headerBufs[$id] = '';
            $bodyBufs[$id]   = '';

            // Capture headers via callback
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use ($id, &$headerBufs) {
                $headerBufs[$id] .= $header;
                return strlen($header);
            });

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($id, &$bodyBufs, $options) {
                $maxSize = (int)($options['max_response_size'] ?? $this->config['max_response_size']);
                if (strlen($bodyBufs[$id]) >= $maxSize) {
                    return -1; // Signal cURL to abort
                }
                $bodyBufs[$id] .= $data;
                return strlen($data);
            });

            curl_multi_add_handle($mh, $ch);
            $running[$id] = ['url' => $url, 'ch' => $ch, 'started_at' => microtime(true)];
        };

        // Prime the queue
        for ($i = 0; $i < $concurrency && !empty($queue); $i++) {
            $addNext();
        }

        do {
            $status = curl_multi_exec($mh, $active);
            curl_multi_select($mh, 0.1);

            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $id = (int)$ch;

                if (!isset($running[$id])) {
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    continue;
                }

                $meta       = $running[$id];
                $url        = $meta['url'];
                $durationMs = (int)((microtime(true) - $meta['started_at']) * 1000);

                $curlErr  = curl_error($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;

                $rawHeaders   = $headerBufs[$id] ?? '';
                $parsedHeaders = $this->parseResponseHeaders($rawHeaders);
                $body          = $bodyBufs[$id] ?? '';
                $contentType   = $parsedHeaders['content-type'] ?? '';
                $detectedType  = $this->detectContentType($contentType, $url, $body);

                if ($curlErr) {
                    $errType = str_contains($curlErr, 'timed out') ? 'timeout' : 'curl_error';
                    $response = $this->errorResponse($url, $errType, "cURL: {$curlErr}");
                } else {
                    $response = [
                        'requested_url' => $url,
                        'final_url'     => $finalUrl,
                        'http_status'   => $httpCode,
                        'content_type'  => $detectedType,
                        'content'       => $body,
                        'headers'       => $parsedHeaders,
                        'redirect_chain'=> [],
                        'duration_ms'   => $durationMs,
                        'response_size' => strlen($body),
                        'error_type'    => null,
                        'error_message' => null,
                    ];
                    $response = $this->classifyResponse($response);
                }

                $onComplete($url, $response);

                // Clean up
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($running[$id], $headerBufs[$id], $bodyBufs[$id]);

                // Add next from queue
                $addNext();
            }

        } while ($active > 0 && $status === CURLM_OK);

        curl_multi_close($mh);
    }

    // ── Private implementation ────────────────────────────────────────────────

    private function doRequest(string $url, array $opts): array
    {
        $startTime    = microtime(true);
        $redirectChain = [];

        $ch = $this->buildHandle($url, $opts);

        // Capture headers
        $rawHeaders = '';
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$rawHeaders) {
            $rawHeaders .= $header;
            return strlen($header);
        });

        // Enforce response size limit
        $maxSize = (int)($opts['max_response_size'] ?? $this->config['max_response_size']);
        $body    = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$body, $maxSize) {
            if (strlen($body) >= $maxSize) {
                return -1; // Abort
            }
            $body .= $data;
            return strlen($data);
        });

        $rawBody  = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
        curl_close($ch);

        $durationMs    = (int)((microtime(true) - $startTime) * 1000);
        $parsedHeaders = $this->parseResponseHeaders($rawHeaders);
        $contentType   = $parsedHeaders['content-type'] ?? '';
        $detectedType  = $this->detectContentType($contentType, $url, $body);

        if ($curlErr) {
            $errType = str_contains($curlErr, 'timed out') ? 'timeout' : 'curl_error';
            return $this->errorResponse($url, $errType, "cURL: {$curlErr}");
        }

        return [
            'requested_url' => $url,
            'final_url'     => $finalUrl,
            'http_status'   => $httpCode,
            'content_type'  => $detectedType,
            'content'       => $body,
            'headers'       => $parsedHeaders,
            'redirect_chain' => $redirectChain,
            'duration_ms'   => $durationMs,
            'response_size' => strlen($body),
            'error_type'    => null,
            'error_message' => null,
        ];
    }

    private function buildHandle(string $url, array $opts): \CurlHandle
    {
        $ch = curl_init();

        $timeout    = (int)($opts['timeout'] ?? $this->config['request_timeout']);
        $connTimeout = (int)($opts['connection_timeout'] ?? $this->config['connection_timeout']);
        $maxRedirs   = (int)($opts['max_redirects'] ?? $this->config['max_redirects']);
        $verifySsl   = (bool)($opts['verify_ssl'] ?? $this->config['verify_ssl']);
        $ua          = $opts['user_agent'] ?? $this->config['user_agent'] ?? self::DEFAULT_UA;

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => $maxRedirs,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connTimeout,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_ENCODING       => 'gzip, deflate',
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADER         => false, // We use HEADERFUNCTION instead
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Connection: keep-alive',
            ],
        ]);

        return $ch;
    }

    private function classifyResponse(array $response): array
    {
        $status  = $response['http_status'];
        $content = $response['content'] ?? '';

        if ($response['error_type'] !== null) {
            return $response;
        }

        // Detect bot/Cloudflare protection
        $protection = $this->detectProtection($content, $status);
        if ($protection) {
            $response['error_type']    = 'cloudflare_blocked';
            $response['error_message'] = $protection;
            return $response;
        }

        if ($status === null) {
            return $response;
        }

        if ($status === 429) {
            $response['error_type']    = 'rate_limited';
            $response['error_message'] = 'HTTP 429 - Rate Limited (Too Many Requests)';
            return $response;
        }

        if ($status >= 500) {
            $response['error_type']    = 'server_error';
            $response['error_message'] = "HTTP {$status} - Server Error";
            return $response;
        }

        if ($status >= 400) {
            $response['error_type']    = 'http_error';
            $response['error_message'] = "HTTP {$status}";
            return $response;
        }

        return $response;
    }

    private function detectProtection(string $content, ?int $httpCode): ?string
    {
        if (empty($content)) {
            return null;
        }
        $lower = strtolower($content);

        $cfSignals = [
            'cf-browser-verification',
            'cf_clearance',
            'cloudflare ray id',
            '__cf_bm',
            'challenges.cloudflare.com',
            'cf-turnstile',
            'sorry, you have been blocked',
            'attention required! | cloudflare',
            'jschl_vc',
            'jschl_answer',
        ];

        foreach ($cfSignals as $signal) {
            if (str_contains($lower, $signal)) {
                return 'Cloudflare protection detected';
            }
        }

        $wafSignals = [
            'datadome',
            'px-captcha',
            '_pxhd',
            'imperva',
            'incap_ses',
            'sucuri/cloudproxy',
            'ddos protection by',
        ];

        foreach ($wafSignals as $signal) {
            if (str_contains($lower, $signal)) {
                return 'WAF/bot-protection detected: ' . $signal;
            }
        }

        return null;
    }

    private function detectContentType(string $header, string $url, string $content): string
    {
        // 1. Trust Content-Type header first
        $header = strtolower(trim(explode(';', $header)[0]));

        $typeMap = [
            'text/html'             => 'html',
            'application/xhtml+xml' => 'html',
            'application/javascript'=> 'javascript',
            'text/javascript'       => 'javascript',
            'application/x-javascript' => 'javascript',
            'text/css'              => 'css',
            'application/json'      => 'json',
            'text/json'             => 'json',
            'application/xml'       => 'xml',
            'text/xml'              => 'xml',
            'application/rss+xml'   => 'xml',
            'application/atom+xml'  => 'xml',
            'text/plain'            => 'text',
        ];

        if (isset($typeMap[$header])) {
            return $typeMap[$header];
        }

        // 2. File extension fallback
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        $ext  = pathinfo($path, PATHINFO_EXTENSION);
        $extMap = [
            'js'   => 'javascript',
            'mjs'  => 'javascript',
            'css'  => 'css',
            'json' => 'json',
            'xml'  => 'xml',
            'rss'  => 'xml',
            'atom' => 'xml',
            'html' => 'html',
            'htm'  => 'html',
        ];

        if (isset($extMap[$ext])) {
            return $extMap[$ext];
        }

        // 3. Content sniffing (first 1000 bytes)
        $sniff = trim(substr($content, 0, 1000));
        if (str_starts_with($sniff, '<!doctype html') || str_starts_with($sniff, '<html')) {
            return 'html';
        }
        if (str_starts_with($sniff, '<?xml') || str_starts_with($sniff, '<urlset') || str_starts_with($sniff, '<sitemapindex')) {
            return 'xml';
        }
        if (isset($sniff[0]) && ($sniff[0] === '{' || $sniff[0] === '[')) {
            return 'json';
        }

        return 'unknown';
    }

    private function parseResponseHeaders(string $rawHeaders): array
    {
        $parsed = [];
        $lines  = explode("\r\n", $rawHeaders);
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$key, $val]              = explode(':', $line, 2);
                $parsed[strtolower(trim($key))] = trim($val);
            }
        }
        return $parsed;
    }

    private function calcRetryDelay(int $attempt, ?string $retryAfterHeader): int
    {
        // Respect Retry-After header if present
        if ($retryAfterHeader !== null) {
            $ra = trim($retryAfterHeader);
            if (ctype_digit($ra)) {
                return min((int)$ra, $this->config['max_retry_delay']);
            }
            // Could be HTTP date — parse it
            $ts = strtotime($ra);
            if ($ts !== false) {
                return min(max(0, $ts - time()), $this->config['max_retry_delay']);
            }
        }

        // Exponential backoff with jitter
        $base  = $this->config['retry_base_delay'] * (2 ** ($attempt - 1));
        $jitter = random_int(0, (int)($base * 0.3));
        return min($base + $jitter, $this->config['max_retry_delay']);
    }

    private function applyDomainDelay(string $url): void
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if (!$host) {
            return;
        }

        $last    = self::$domainRequestTimes[$host] ?? 0;
        $elapsed = microtime(true) - $last;
        $minGap  = (float)$this->config['request_delay'];

        if ($elapsed < $minGap) {
            $sleepUs = (int)(($minGap - $elapsed) * 1_000_000);
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }
    }

    private function recordDomainRequest(string $url): void
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if ($host) {
            self::$domainRequestTimes[$host] = microtime(true);
        }
    }

    private function errorResponse(string $url, string $errorType, string $errorMessage): array
    {
        return [
            'requested_url'  => $url,
            'final_url'      => $url,
            'http_status'    => null,
            'content_type'   => 'unknown',
            'content'        => '',
            'headers'        => [],
            'redirect_chain' => [],
            'duration_ms'    => 0,
            'response_size'  => 0,
            'error_type'     => $errorType,
            'error_message'  => $errorMessage,
        ];
    }
}
