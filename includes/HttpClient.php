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
    public const RETRYABLE_CODES = [408, 425, 429, 500, 502, 503, 504];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'connection_timeout'     => 10,
            'request_timeout'        => 30,
            'max_redirects'          => 10,
            'verify_ssl'             => false,
            'max_response_size'      => 10485760, // 10 MB
            'max_retries'            => 3,
            'retry_base_delay'       => 2,
            'max_retry_delay'        => 60,
            'request_delay'          => 1,        // seconds between same-domain requests
            'per_domain_concurrency' => 2,        // max parallel connections per domain in fetchMulti
            'user_agent'             => self::DEFAULT_UA,
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
     *   error_message: string|null,
     *   curl_errno: int,
     *   is_retryable: bool
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

            // Classify response
            $result = $this->classifyResponse($result);

            // Check if retry eligible
            if (!empty($result['is_retryable']) && $attempt < $maxRetries) {
                $retryAfter = $result['headers']['retry-after'] ?? null;
                $delay = $this->calcRetryDelay($attempt, $retryAfter);
                if ($delay > 0) {
                    sleep($delay);
                }
                continue;
            }

            return $result;
        }

        return $this->errorResponse($url, 'max_retries_exceeded', 'Maximum retry attempts exceeded', 0, null, [], false);
    }

    /**
     * Fetch multiple URLs concurrently using cURL Multi.
     *
     * Hardened against multi execution failures, select -1 busy-loops,
     * and leaks by enforcing per-domain concurrency limits and guaranteed cleanup.
     *
     * @param array<string>  $urls
     * @param callable       $onComplete  Called with (url, response) for each completed request
     * @param array          $options     Shared fetch options
     */
    public function fetchMulti(array $urls, callable $onComplete, array $options = []): void
    {
        $concurrency           = (int)($options['concurrency'] ?? $this->config['concurrency'] ?? 5);
        $perDomainLimit        = (int)($options['per_domain_concurrency'] ?? $this->config['per_domain_concurrency'] ?? 2);
        $queue                 = array_values($urls);
        $running               = [];   // handle_id => ['url' => ..., 'host' => ..., 'started_at' => ..., 'ch' => ...]
        $headerBufs            = [];   // handle_id => header string buffer
        $bodyBufs              = [];   // handle_id => body string
        $isOversized           = [];   // handle_id => bool
        $activePerDomain       = [];   // host => count of active handles

        $mh = curl_multi_init();
        if (!$mh) {
            throw new \RuntimeException('Failed to initialize cURL Multi handle');
        }

        // Helper to launch next eligible URL from queue respecting per-domain concurrency
        $addNext = function () use (
            &$queue,
            &$running,
            &$headerBufs,
            &$bodyBufs,
            &$isOversized,
            &$activePerDomain,
            $mh,
            $options,
            $perDomainLimit,
            $onComplete
        ): bool {
            if (empty($queue)) {
                return false;
            }

            // Find first URL whose domain is under the per-domain concurrency limit
            $selectedIdx = null;
            $selectedHost = null;

            foreach ($queue as $idx => $candUrl) {
                $host = strtolower(parse_url($candUrl, PHP_URL_HOST) ?? '');
                $currentActive = $activePerDomain[$host] ?? 0;
                if ($currentActive < $perDomainLimit) {
                    $selectedIdx  = $idx;
                    $selectedHost = $host;
                    break;
                }
            }

            if ($selectedIdx === null) {
                // All candidate URLs in queue currently at max domain concurrency; wait for an active one to finish
                return false;
            }

            $url = $queue[$selectedIdx];
            array_splice($queue, $selectedIdx, 1);

            $ch = $this->buildHandle($url, $options);
            $id = (int)$ch;

            $headerBufs[$id]  = '';
            $bodyBufs[$id]    = '';
            $isOversized[$id] = false;

            // Capture headers
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use ($id, &$headerBufs) {
                $headerBufs[$id] .= $header;
                return strlen($header);
            });

            // Enforce response size limit
            $maxSize = (int)($options['max_response_size'] ?? $this->config['max_response_size']);
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($id, &$bodyBufs, &$isOversized, $maxSize) {
                if (strlen($bodyBufs[$id]) + strlen($data) > $maxSize) {
                    $isOversized[$id] = true;
                    return -1; // Abort
                }
                $bodyBufs[$id] .= $data;
                return strlen($data);
            });

            // Increment per-domain count
            $activePerDomain[$selectedHost] = ($activePerDomain[$selectedHost] ?? 0) + 1;

            $addResult = curl_multi_add_handle($mh, $ch);
            if ($addResult !== CURLM_OK) {
                // Registration failed: clean up immediately and do not add to running
                curl_close($ch);
                unset($headerBufs[$id], $bodyBufs[$id], $isOversized[$id]);
                $activePerDomain[$selectedHost]--;
                if ($activePerDomain[$selectedHost] <= 0) {
                    unset($activePerDomain[$selectedHost]);
                }
                $errStr = curl_multi_strerror($addResult) ?: "Code {$addResult}";
                $onComplete($url, $this->errorResponse(
                    $url,
                    'curl_error',
                    "curl_multi_add_handle failed: {$errStr}",
                    $addResult,
                    null,
                    [],
                    false
                ));
                return false;
            }

            $running[$id] = [
                'url'        => $url,
                'host'       => $selectedHost,
                'ch'         => $ch,
                'started_at' => microtime(true),
            ];
            return true;
        };

        try {
            // Prime the multi queue
            while (count($running) < $concurrency && !empty($queue)) {
                if (!$addNext()) {
                    break;
                }
            }

            $active = 0;
            do {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc === CURLM_CALL_MULTI_PERFORM);

                if ($mrc !== CURLM_OK && $mrc !== CURLM_CALL_MULTI_PERFORM) {
                    $errStr = curl_multi_strerror($mrc);
                    throw new \RuntimeException("curl_multi_exec failed: {$errStr} (code {$mrc})");
                }

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
                    $host       = $meta['host'];
                    $durationMs = (int)((microtime(true) - $meta['started_at']) * 1000);

                    $curlErr   = curl_error($ch);
                    $curlErrno = curl_errno($ch);
                    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $finalUrl  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;

                    $rawHeaders    = $headerBufs[$id] ?? '';
                    $parsedHeaders = $this->parseResponseHeaders($rawHeaders);
                    $body          = $bodyBufs[$id] ?? '';
                    $contentType   = $parsedHeaders['content-type'] ?? '';
                    $detectedType  = $this->detectContentType($contentType, $url, $body);

                    if (!empty($isOversized[$id])) {
                        $maxSize = (int)($options['max_response_size'] ?? $this->config['max_response_size']);
                        $response = $this->errorResponse(
                            $url,
                            'response_too_large',
                            "Response size exceeded limit ({$maxSize} bytes)",
                            $curlErrno,
                            $httpCode ?: null,
                            $parsedHeaders,
                            false
                        );
                    } elseif ($curlErr) {
                        $errType  = self::classifyCurlError($curlErrno, $curlErr);
                        $response = $this->errorResponse(
                            $url,
                            $errType,
                            "cURL: {$curlErr}",
                            $curlErrno,
                            $httpCode ?: null,
                            $parsedHeaders
                        );
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
                            'curl_errno'    => 0,
                            'is_retryable'  => false,
                        ];
                        $response = $this->classifyResponse($response);
                    }

                    $onComplete($url, $response);

                    // Clean up handle
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);

                    if (isset($activePerDomain[$host])) {
                        $activePerDomain[$host]--;
                        if ($activePerDomain[$host] <= 0) {
                            unset($activePerDomain[$host]);
                        }
                    }

                    unset($running[$id], $headerBufs[$id], $bodyBufs[$id], $isOversized[$id]);

                    // Refill slots up to concurrency limit
                    while (count($running) < $concurrency && !empty($queue)) {
                        if (!$addNext()) {
                            break;
                        }
                    }
                }

                if ($active > 0) {
                    $sel = curl_multi_select($mh, 0.1);
                    if ($sel === -1) {
                        // Avoid busy-loop if select returns -1
                        usleep(15000);
                    }
                }

            } while ($active > 0 || !empty($queue));

        } finally {
            // Guaranteed cleanup of any remaining handles
            foreach ($running as $id => $meta) {
                if (isset($meta['ch']) && (is_resource($meta['ch']) || $meta['ch'] instanceof \CurlHandle)) {
                    @curl_multi_remove_handle($mh, $meta['ch']);
                    @curl_close($meta['ch']);
                }
            }
            @curl_multi_close($mh);
        }
    }

    // ── Private implementation ────────────────────────────────────────────────

    private function doRequest(string $url, array $opts): array
    {
        $startTime     = microtime(true);
        $redirectChain = [];

        $ch = $this->buildHandle($url, $opts);

        // Capture headers
        $rawHeaders = '';
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$rawHeaders) {
            $rawHeaders .= $header;
            return strlen($header);
        });

        // Enforce response size limit
        $maxSize     = (int)($opts['max_response_size'] ?? $this->config['max_response_size']);
        $body        = '';
        $isOversized = false;
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$body, &$isOversized, $maxSize) {
            if (strlen($body) + strlen($data) > $maxSize) {
                $isOversized = true;
                return -1; // Abort
            }
            $body .= $data;
            return strlen($data);
        });

        $rawBody   = curl_exec($ch);
        $curlErr   = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
        curl_close($ch);

        $durationMs    = (int)((microtime(true) - $startTime) * 1000);
        $parsedHeaders = $this->parseResponseHeaders($rawHeaders);
        $contentType   = $parsedHeaders['content-type'] ?? '';
        $detectedType  = $this->detectContentType($contentType, $url, $body);

        if ($isOversized) {
            return $this->errorResponse(
                $url,
                'response_too_large',
                "Response size exceeded limit ({$maxSize} bytes)",
                $curlErrno,
                $httpCode ?: null,
                $parsedHeaders,
                false
            );
        }

        if ($curlErr) {
            $errType = self::classifyCurlError($curlErrno, $curlErr);
            return $this->errorResponse(
                $url,
                $errType,
                "cURL: {$curlErr}",
                $curlErrno,
                $httpCode ?: null,
                $parsedHeaders
            );
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
            'curl_errno'    => 0,
            'is_retryable'  => false,
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
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // Force IPv4 resolution per production stability fix
            CURLOPT_HEADER         => false, // We use HEADERFUNCTION instead
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Connection: keep-alive',
            ],
        ]);

        return $ch;
    }

    /**
     * Resolves a cURL error code from a constant name safely, using defined() / constant().
     * Falls back to standard numeric libcurl error code if constant is not defined in current PHP runtime.
     */
    public static function getCurlErrno(string $constantName, int $fallback): int
    {
        if (defined($constantName)) {
            return (int)constant($constantName);
        }
        return $fallback;
    }

    /**
     * Set of cURL error codes classified as SSL / certificate verification failures (permanent).
     */
    public static function getSslErrorCodes(): array
    {
        static $codes = null;
        if ($codes === null) {
            $names = [
                'CURLE_SSL_CONNECT_ERROR'   => 35,
                'CURLE_SSL_CERTPROBLEM'     => 58,
                'CURLE_SSL_CIPHER'          => 59,
                'CURLE_SSL_CACERT'          => 60,
                'CURLE_SSL_CACERT_BADFILE'  => 77,
                'CURLE_SSL_SHUTDOWN_FAILED' => 80,
                'CURLE_SSL_CRL_BADFILE'     => 82,
                'CURLE_SSL_ISSUER_ERROR'    => 83,
            ];
            $codes = [];
            foreach ($names as $name => $fallback) {
                $code = defined($name) ? (int)constant($name) : $fallback;
                $codes[$code] = true;
            }
        }
        return $codes;
    }

    /**
     * Set of cURL error codes that are permanent configuration/URL errors (never retry).
     */
    public static function getPermanentErrorCodes(): array
    {
        static $codes = null;
        if ($codes === null) {
            $names = [
                'CURLE_UNSUPPORTED_PROTOCOL' => 1,
                'CURLE_URL_MALFORMAT'        => 3,
                'CURLE_WRITE_ERROR'          => 23,
                'CURLE_ABORTED_BY_CALLBACK'  => 42,
            ];
            $codes = self::getSslErrorCodes();
            foreach ($names as $name => $fallback) {
                $code = defined($name) ? (int)constant($name) : $fallback;
                $codes[$code] = true;
            }
        }
        return $codes;
    }

    /**
     * Set of cURL error codes that are transient network errors (eligible for retry).
     */
    public static function getTransientErrorCodes(): array
    {
        static $codes = null;
        if ($codes === null) {
            $names = [
                'CURLE_COULDNT_RESOLVE_PROXY' => 5,
                'CURLE_COULDNT_RESOLVE_HOST'  => 6,
                'CURLE_COULDNT_CONNECT'       => 7,
                'CURLE_OPERATION_TIMEDOUT'    => 28,
                'CURLE_GOT_NOTHING'           => 52,
                'CURLE_SEND_ERROR'            => 55,
                'CURLE_RECV_ERROR'            => 56,
            ];
            $codes = [];
            foreach ($names as $name => $fallback) {
                $code = defined($name) ? (int)constant($name) : $fallback;
                $codes[$code] = true;
            }
        }
        return $codes;
    }

    /**
     * Classify raw cURL error numbers and messages into structured failure types.
     */
    public static function classifyCurlError(int $errno, string $errorMsg): string
    {
        // 1. SSL-related errors (permanent)
        $sslCodes = self::getSslErrorCodes();
        if ($errno > 0 && isset($sslCodes[$errno])) {
            return 'ssl_error';
        }

        // 2. Specific cURL error codes using safe resolution
        if ($errno === self::getCurlErrno('CURLE_OPERATION_TIMEDOUT', 28)) {
            return 'timeout';
        }
        if ($errno === self::getCurlErrno('CURLE_COULDNT_RESOLVE_HOST', 6)
            || $errno === self::getCurlErrno('CURLE_COULDNT_RESOLVE_PROXY', 5)) {
            return 'dns_error';
        }
        if ($errno === self::getCurlErrno('CURLE_COULDNT_CONNECT', 7)) {
            return 'connection_error';
        }
        if ($errno === self::getCurlErrno('CURLE_RECV_ERROR', 56)
            || $errno === self::getCurlErrno('CURLE_SEND_ERROR', 55)
            || $errno === self::getCurlErrno('CURLE_GOT_NOTHING', 52)) {
            return 'connection_reset';
        }
        if ($errno === self::getCurlErrno('CURLE_URL_MALFORMAT', 3)) {
            return 'invalid_url';
        }
        if ($errno === self::getCurlErrno('CURLE_UNSUPPORTED_PROTOCOL', 1)) {
            return 'unsupported_protocol';
        }
        if ($errno === self::getCurlErrno('CURLE_WRITE_ERROR', 23)
            || $errno === self::getCurlErrno('CURLE_ABORTED_BY_CALLBACK', 42)) {
            return 'response_too_large';
        }

        // 3. Fallback string matching on error message
        $msgLower = strtolower($errorMsg);
        if (str_contains($msgLower, 'timed out') || str_contains($msgLower, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($msgLower, 'could not resolve host') || str_contains($msgLower, 'name or service not known')) {
            return 'dns_error';
        }
        if (str_contains($msgLower, 'connection reset') || str_contains($msgLower, 'empty reply') || str_contains($msgLower, 'recv error')) {
            return 'connection_reset';
        }
        if (str_contains($msgLower, 'failed to connect') || str_contains($msgLower, 'connection refused')) {
            return 'connection_error';
        }
        if (str_contains($msgLower, 'ssl') || str_contains($msgLower, 'certificate') || str_contains($msgLower, 'cert problem')) {
            return 'ssl_error';
        }

        return 'curl_error';
    }

    /**
     * Determines whether an error is transient and eligible for retry.
     */
    public static function isRetryable(?string $errorType, ?int $httpStatus, int $curlErrno = 0): bool
    {
        // 1. Explicitly NON-retryable error types
        if (in_array($errorType, ['response_too_large', 'invalid_url', 'unsupported_protocol', 'ssl_error', 'cloudflare_blocked'], true)) {
            return false;
        }

        // 2. Explicitly NON-retryable cURL errno (SSL, malformed URL, unsupported protocol, write/size errors)
        $permanentCodes = self::getPermanentErrorCodes();
        if ($curlErrno > 0 && isset($permanentCodes[$curlErrno])) {
            return false;
        }

        // 3. Transient error types
        if (in_array($errorType, ['timeout', 'dns_error', 'connection_error', 'connection_reset', 'rate_limited'], true)) {
            return true;
        }

        // 4. Transient cURL error codes
        $transientCodes = self::getTransientErrorCodes();
        if ($curlErrno > 0 && isset($transientCodes[$curlErrno])) {
            return true;
        }

        // 5. Retryable HTTP response codes
        if ($httpStatus !== null && in_array($httpStatus, self::RETRYABLE_CODES, true)) {
            return true;
        }

        return false;
    }

    /**
     * Parse Retry-After header supporting both integer seconds and HTTP-date format,
     * bounded by maxDelay.
     */
    public static function parseRetryAfter(?string $header, int $maxDelay = 60): int
    {
        if ($header === null || trim($header) === '') {
            return 0;
        }
        $ra = trim($header);
        if (ctype_digit($ra)) {
            return min((int)$ra, $maxDelay);
        }
        $ts = strtotime($ra);
        if ($ts !== false) {
            return min(max(0, $ts - time()), $maxDelay);
        }
        return 0;
    }

    private function classifyResponse(array $response): array
    {
        $status    = $response['http_status'];
        $content   = $response['content'] ?? '';
        $curlErrno = (int)($response['curl_errno'] ?? 0);

        if ($response['error_type'] !== null) {
            $response['is_retryable'] = self::isRetryable($response['error_type'], $status, $curlErrno);
            return $response;
        }

        // Detect bot/Cloudflare protection
        $protection = $this->detectProtection($content, $status);
        if ($protection) {
            $response['error_type']    = 'cloudflare_blocked';
            $response['error_message'] = $protection;
            $response['is_retryable']  = false;
            return $response;
        }

        if ($status === null) {
            $response['is_retryable'] = self::isRetryable($response['error_type'], $status, $curlErrno);
            return $response;
        }

        if ($status === 429) {
            $response['error_type']    = 'rate_limited';
            $response['error_message'] = 'HTTP 429 - Rate Limited (Too Many Requests)';
            $response['is_retryable']  = true;
            return $response;
        }

        if ($status >= 500) {
            $response['error_type']    = 'server_error';
            $response['error_message'] = "HTTP {$status} - Server Error";
            $response['is_retryable']  = in_array($status, self::RETRYABLE_CODES, true);
            return $response;
        }

        if ($status >= 400) {
            $response['error_type']    = 'http_error';
            $response['error_message'] = "HTTP {$status}";
            $response['is_retryable']  = in_array($status, self::RETRYABLE_CODES, true);
            return $response;
        }

        $response['is_retryable'] = false;
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

    public function calcRetryDelay(int $attempt, ?string $retryAfterHeader): int
    {
        $maxDelay = (int)($this->config['max_retry_delay'] ?? 60);

        // Respect Retry-After header if present
        $raDelay = self::parseRetryAfter($retryAfterHeader, $maxDelay);
        if ($raDelay > 0) {
            return $raDelay;
        }

        // Exponential backoff with jitter
        $baseDelay = (int)($this->config['retry_base_delay'] ?? 2);
        $base      = $baseDelay * (2 ** max(0, $attempt - 1));
        $jitter    = random_int(0, max(1, (int)($base * 0.3)));
        return min($base + $jitter, $maxDelay);
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

    public function errorResponse(
        string $url,
        string $errorType,
        string $errorMessage,
        int $curlErrno = 0,
        ?int $httpStatus = null,
        array $headers = [],
        ?bool $isRetryable = null
    ): array {
        if ($isRetryable === null) {
            $isRetryable = self::isRetryable($errorType, $httpStatus, $curlErrno);
        }

        return [
            'requested_url'  => $url,
            'final_url'      => $url,
            'http_status'    => $httpStatus,
            'content_type'   => 'unknown',
            'content'        => '',
            'headers'        => $headers,
            'redirect_chain' => [],
            'duration_ms'    => 0,
            'response_size'  => 0,
            'error_type'     => $errorType,
            'error_message'  => $errorMessage,
            'curl_errno'     => $curlErrno,
            'is_retryable'   => $isRetryable,
        ];
    }
}
