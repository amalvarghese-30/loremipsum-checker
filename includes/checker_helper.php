<?php
/**
 * Checker Helper Module - Enhanced
 * Handles fetching page content and detecting Lorem Ipsum patterns.
 * Includes: Cloudflare detection, retry logic, IP-ban prevention, realistic fingerprinting.
 */

// ─── USER AGENT POOL ────────────────────────────────────────────────────────

$GLOBALS['_ua_pool'] = [
    // Chrome on Windows
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    // Chrome on Mac
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_3_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    // Firefox on Windows
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0',
    // Firefox on Mac
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.4; rv:124.0) Gecko/20100101 Firefox/124.0',
    // Edge on Windows
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36 Edg/123.0.0.0',
    // Safari on Mac
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_3) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3 Safari/605.1.15',
];

// Track per-domain request counts for politeness throttling
$GLOBALS['_domain_request_count'] = [];
$GLOBALS['_last_request_time']    = [];

// ─── MAIN FETCH FUNCTION ─────────────────────────────────────────────────────

/**
 * Fetch page content with bot-evasion techniques.
 * Returns array with keys: 'content', 'code'  — or 'error', 'error_type'
 *
 * error_type values:
 *   'cloudflare_blocked' | 'rate_limited' | 'ip_banned' | 'timeout' | 'curl_error' | 'http_error'
 */
function fetchPageContent($url, $maxRetries = 3) {

    $domain = parse_url($url, PHP_URL_HOST);

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {

        // ── Politeness delay (IP-ban prevention) ──────────────────────────
        _applyPolitenessDelay($domain, $attempt);

        // ── Build realistic headers ────────────────────────────────────────
        $ua      = _pickUserAgent($domain, $attempt);
        $headers = _buildHeaders($ua, $url);

        // ── Execute request ────────────────────────────────────────────────
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_ENCODING       => 'gzip, deflate, br',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIEFILE     => '',   // enable cookie engine (in-memory)
            CURLOPT_COOKIEJAR      => '',
            // Mimic real browser TLS fingerprint order
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ]);

        $content  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        // ── Track domain request count ─────────────────────────────────────
        $GLOBALS['_domain_request_count'][$domain] =
            ($GLOBALS['_domain_request_count'][$domain] ?? 0) + 1;
        $GLOBALS['_last_request_time'][$domain] = microtime(true);

        // ── Evaluate result ────────────────────────────────────────────────

        // cURL-level failure
        if ($curlErr) {
            $errType = (stripos($curlErr, 'timed out') !== false) ? 'timeout' : 'curl_error';
            if ($attempt < $maxRetries) {
                sleep(3 * $attempt); // back-off before retry
                continue;
            }
            return ['error' => "cURL error: {$curlErr}", 'error_type' => $errType];
        }

        // Cloudflare / bot-protection detection (must happen before HTTP code check)
        $cfResult = _detectProtection($content, $httpCode, $url);
        if ($cfResult !== null) {
            // On CF challenge, wait longer then retry with a fresh UA
            if ($attempt < $maxRetries) {
                sleep(5 * $attempt);
                continue;
            }
            return $cfResult; // return structured error after all retries exhausted
        }

        // Rate-limit / IP-ban HTTP codes
        if ($httpCode === 429) {
            $retryAfter = 10 * $attempt;
            if ($attempt < $maxRetries) {
                sleep($retryAfter);
                continue;
            }
            return [
                'error'      => 'HTTP 429 - Rate Limited (Too Many Requests)',
                'error_type' => 'rate_limited',
                'code'       => 429,
            ];
        }

        if ($httpCode === 403) {
            // Could be IP-ban or basic auth — try to distinguish
            $isIpBan = _looksLikeIpBan($content, $httpCode);
            if ($isIpBan && $attempt < $maxRetries) {
                sleep(8 * $attempt);
                continue;
            }
            if ($isIpBan) {
                return [
                    'error'      => 'HTTP 403 - IP Banned or Access Denied',
                    'error_type' => 'ip_banned',
                    'code'       => 403,
                ];
            }
            // Plain 403 (not an IP ban)
            return ['error' => 'HTTP 403 - Forbidden', 'error_type' => 'http_error', 'code' => 403];
        }

        if ($httpCode >= 500) {
            if ($attempt < $maxRetries) {
                sleep(4 * $attempt);
                continue;
            }
            return ['error' => "HTTP {$httpCode} - Server Error", 'error_type' => 'http_error', 'code' => $httpCode];
        }

        if ($httpCode >= 400) {
            return ['error' => "HTTP {$httpCode}", 'error_type' => 'http_error', 'code' => $httpCode];
        }

        // Success
        return ['content' => $content, 'code' => $httpCode];
    }

    return ['error' => 'Max retries exceeded', 'error_type' => 'http_error'];
}


// ─── PROTECTION DETECTION ────────────────────────────────────────────────────

/**
 * Returns an error array if Cloudflare or similar bot-protection is detected,
 * otherwise returns null (meaning: proceed normally).
 */
function _detectProtection($content, $httpCode, $url) {

    // ── Cloudflare specific indicators ────────────────────────────────────
    $cfSignals = [
        // JS challenge page
        'cf-browser-verification',
        'cf_clearance',
        'ray id',
        'cloudflare ray id',
        '__cf_bm',
        'cf-challenge',
        // Turnstile CAPTCHA
        'challenges.cloudflare.com',
        'cf-turnstile',
        // Interstitial markers
        'attention required! | cloudflare',
        'sorry, you have been blocked',
        'enable cookies and reload the page',
        'checking your browser',
        // Error page title patterns
        '<title>cloudflare',
        'access denied | cloudflare',
    ];

    // ── Generic WAF / bot-protection indicators ───────────────────────────
    $genericSignals = [
        'distil_ident',          // Distil Networks
        'datadome',              // DataDome
        'px-captcha',            // PerimeterX
        '_pxhd',
        'imperva',               // Imperva Incapsula
        'incap_ses',
        'visid_incap',
        'sucuri/cloudproxy',     // Sucuri
        'blocked by',
        'bot protection',
        'human verification',
        'ddos protection by',
    ];

    $contentLower = strtolower($content ?? '');

    // Cloudflare 403/503 with CF signals
    if (in_array($httpCode, [403, 503, 429])) {
        foreach ($cfSignals as $signal) {
            if (strpos($contentLower, $signal) !== false) {
                return [
                    'error'      => 'CLOUDFLARE PROTECTION - Bot challenge detected (HTTP ' . $httpCode . ')',
                    'error_type' => 'cloudflare_blocked',
                    'code'       => $httpCode,
                ];
            }
        }
    }

    // Cloudflare JS challenge can also return 200 with a challenge body
    if ($httpCode === 200) {
        $cfJsSignals = [
            'cf-browser-verification',
            'challenges.cloudflare.com/turnstile',
            '__cf_bm',
            'cf-challenge',
            // Classic JS challenge meta-refresh + hidden form
            'jschl_vc',
            'jschl_answer',
        ];
        foreach ($cfJsSignals as $signal) {
            if (strpos($contentLower, $signal) !== false) {
                return [
                    'error'      => 'CLOUDFLARE PROTECTION - JavaScript challenge (HTTP 200 with JS challenge body)',
                    'error_type' => 'cloudflare_blocked',
                    'code'       => $httpCode,
                ];
            }
        }
    }

    // Generic WAF at any HTTP code
    foreach ($genericSignals as $signal) {
        if (strpos($contentLower, $signal) !== false) {
            return [
                'error'      => 'BOT PROTECTION - Access blocked by security layer (' . strtoupper($signal) . ')',
                'error_type' => 'cloudflare_blocked', // grouped under same bucket for UI
                'code'       => $httpCode,
            ];
        }
    }

    return null; // No protection detected
}

/**
 * Heuristic: does a 403 look like an IP-level ban vs a simple forbidden?
 */
function _looksLikeIpBan($content, $httpCode) {
    $signals = ['your ip', 'ip address', 'ip has been', 'ip was blocked', 'banned', 'blacklisted', 'access denied'];
    $lower   = strtolower($content ?? '');
    foreach ($signals as $s) {
        if (strpos($lower, $s) !== false) return true;
    }
    return false;
}


// ─── REQUEST FINGERPRINTING HELPERS ──────────────────────────────────────────

/**
 * Pick a user agent — rotate on each attempt.
 */
function _pickUserAgent($domain, $attempt) {
    $pool  = $GLOBALS['_ua_pool'];
    // Use domain hash + attempt to rotate without pure randomness (more consistent per domain)
    $index = (crc32($domain) + $attempt) % count($pool);
    return $pool[abs($index)];
}

/**
 * Build realistic browser-like HTTP headers.
 */
function _buildHeaders($ua, $url) {
    $isFirefox = strpos($ua, 'Firefox') !== false;
    $isSafari  = strpos($ua, 'Safari') !== false && strpos($ua, 'Chrome') === false;

    $acceptHeader = $isFirefox
        ? 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8'
        : 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7';

    $parsed  = parse_url($url);
    $referer = $parsed['scheme'] . '://' . $parsed['host'] . '/';

    $headers = [
        'Accept: '                    . $acceptHeader,
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate, br',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'Cache-Control: max-age=0',
    ];

    // Chrome/Edge add Sec-CH-UA headers
    if (!$isFirefox && !$isSafari) {
        $headers[] = 'Sec-CH-UA: "Chromium";v="123", "Not:A-Brand";v="8"';
        $headers[] = 'Sec-CH-UA-Mobile: ?0';
        $headers[] = 'Sec-CH-UA-Platform: "Windows"';
    }

    return $headers;
}

/**
 * Enforce per-domain politeness delay to reduce IP-ban risk.
 * - Adds a base random jitter delay between requests to same domain.
 * - Adds extra back-off on retries.
 */
function _applyPolitenessDelay($domain, $attempt) {
    $last   = $GLOBALS['_last_request_time'][$domain]  ?? 0;
    $count  = $GLOBALS['_domain_request_count'][$domain] ?? 0;
    $now    = microtime(true);
    $elapsed = $now - $last;

    // Minimum gap: 0.8s–2s jitter between requests to same domain
    $minGap = 0.8 + (mt_rand(0, 120) / 100); // 0.8s – 2.0s

    // After every 20 requests to same domain, add a longer pause (anti-ban)
    if ($count > 0 && $count % 20 === 0) {
        $minGap += mt_rand(3, 8); // extra 3–8s cooldown every 20 requests
    }

    // Retry back-off
    if ($attempt > 1) {
        $minGap += (2 ** ($attempt - 1)); // 2s, 4s exponential
    }

    if ($elapsed < $minGap) {
        $sleepUs = (int)(($minGap - $elapsed) * 1_000_000);
        usleep($sleepUs);
    }
}


// ─── LOREM IPSUM DETECTION ───────────────────────────────────────────────────

/**
 * Detect Lorem Ipsum in HTML content.
 */
function detectLoremIpsum($html) {
    $patterns = [
        '/lorem\s+ipsum/i',
        '/dolor\s+sit\s+amet/i',
        '/consectetur\s+adipiscing/i',
        '/elit[,.]?\s+sed\s+do\s+eiusmod/i',
        '/tempor\s+incididunt/i',
        '/ut\s+labore\s+et\s+dolore/i',
        '/magna\s+aliqua/i',
        '/ut\s+enim\s+ad\s+minim/i',
        '/quis\s+nostrud\s+exercitation/i',
        '/duis\s+aute\s+irure/i',
        '/excepteur\s+sint\s+occaecat/i',
        '/nulla\s+pariatur/i',
    ];

    $foundCount = 0;
    $details    = [];

    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $html, $matches)) {
            $count       = count($matches[0]);
            $foundCount += $count;
            $details[]   = trim($matches[0][0]) . " ({$count}x)";
        }
    }

    return [
        'found'   => $foundCount > 0,
        'count'   => $foundCount,
        'details' => implode(', ', $details),
    ];
}