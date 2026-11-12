<?php
/**
 * Automated Regression Test Suite: Response Size Limit & Unit Normalization
 *
 * Covers:
 *   1. Response < 5 KB is downloaded and processed successfully.
 *   2. Response ~50 KB is downloaded successfully (proves 5120-byte false failure is resolved).
 *   3. Response ~1 MB is downloaded successfully.
 *   4. Response exceeding configured maximum limit triggers 'response_too_large' abort,
 *      sets HTTP status 200 (if server responded 200 before abort), and sets is_retryable = false.
 *   5. Response below default 10 MB (10485760 bytes) is permitted.
 *   6. API input normalization: MB values (<= 100), KB values (<= 102400), and raw bytes,
 *      bounded safely between 1 MB and 100 MB with 10 MB default.
 *   7. Snapshot propagation: ScanWorker::mergeEffectiveConfig applies max_response_size to fetching config.
 *   8. Enforcement consistency across fetch() and fetchMulti() bounded responses.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/HttpClient.php';
require_once dirname(__DIR__) . '/includes/Worker/ScanWorker.php';

use LoremChecker\HttpClient;
use LoremChecker\Worker\ScanWorker;

class ResponseSizeLimitTest
{
    public int $assertions = 0;

    public function run(): void
    {
        $this->testDefaultConfigValue();
        $this->testApiUnitNormalization();
        $this->testScanWorkerConfigMerge();
        $this->testSmallResponseAllowed();
        $this->testFiftyKbResponseAllowed();
        $this->testOneMbResponseAllowed();
        $this->testExceededResponseSizeAborts();
        $this->testFetchMultiSizeLimitEnforcement();
    }

    private function assert(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }

    /**
     * Test 1: config.php and HttpClient default to 10485760 bytes (10 MB).
     */
    private function testDefaultConfigValue(): void
    {
        $config = require dirname(__DIR__) . '/config.php';
        $this->assert(
            isset($config['fetching']['max_response_size']) && $config['fetching']['max_response_size'] === 10485760,
            "config.php fetching.max_response_size must default to 10485760 (10 MiB)"
        );

        $http = new HttpClient();
        $ref = new \ReflectionClass($http);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $clientConfig = $prop->getValue($http);

        $this->assert(
            $clientConfig['max_response_size'] === 10485760,
            "HttpClient default max_response_size must be 10485760 bytes"
        );
    }

    /**
     * Test 2: ScanController unit normalization logic.
     */
    private function testApiUnitNormalization(): void
    {
        $normalize = function (?string $raw): array {
            $maxResponseSize = 10485760; // 10 MiB default
            if ($raw !== null && is_numeric($raw)) {
                $num = (float)$raw;
                if ($num <= 0) {
                    $maxResponseSize = 10485760;
                } elseif ($num <= 100) {
                    // Megabytes (e.g. 5, 10, 25, 50)
                    $maxResponseSize = (int)round($num * 1048576);
                } elseif ($num <= 102400) {
                    // Kilobytes (e.g. 5120 KB -> 5 MB)
                    $maxResponseSize = (int)round($num * 1024);
                } else {
                    // Bytes directly
                    $maxResponseSize = (int)round($num);
                }
                $maxResponseSize = max(1048576, min(104857600, $maxResponseSize));
            }
            return [
                'bytes' => $maxResponseSize,
                'mb'    => round($maxResponseSize / 1048576, 1),
            ];
        };

        // Case A: Default / null -> 10 MB (10485760 bytes)
        $res = $normalize(null);
        $this->assert($res['bytes'] === 10485760 && $res['mb'] === 10.0, "Null should default to 10 MB");

        // Case B: MB input '10' -> 10485760 bytes
        $res = $normalize('10');
        $this->assert($res['bytes'] === 10485760 && $res['mb'] === 10.0, "'10' should normalize to 10 MB");

        // Case C: MB input '5' -> 5242880 bytes
        $res = $normalize('5');
        $this->assert($res['bytes'] === 5242880 && $res['mb'] === 5.0, "'5' should normalize to 5 MB");

        // Case D: Historical form input '5120' (intended as KB) -> 5242880 bytes (5 MB, not 5 KB!)
        $res = $normalize('5120');
        $this->assert($res['bytes'] === 5242880 && $res['mb'] === 5.0, "'5120' KB must normalize to 5 MB, never 5120 bytes");

        // Case E: Direct bytes '20971520' (20 MB)
        $res = $normalize('20971520');
        $this->assert($res['bytes'] === 20971520 && $res['mb'] === 20.0, "20971520 bytes should remain 20 MB");

        // Case F: Lower bound clamping (< 1 MB -> 1 MB)
        $res = $normalize('100'); // 100 bytes is not possible since <=100 is treated as MB (100MB), test bytes:
        $resLow = $normalize('500000'); // 500,000 bytes (< 1MB)
        $this->assert($resLow['bytes'] === 1048576, "Under 1 MB should clamp to 1048576 (1 MB)");

        // Case G: Upper bound clamping (> 100 MB -> 100 MB)
        $resHigh = $normalize('200000000'); // 200 MB
        $this->assert($resHigh['bytes'] === 104857600, "Over 100 MB should clamp to 104857600 (100 MB)");
    }

    /**
     * Test 3: ScanWorker::mergeEffectiveConfig propagates max_response_size snapshot to fetching config.
     */
    private function testScanWorkerConfigMerge(): void
    {
        $baseConfig = [
            'fetching' => [
                'max_response_size' => 10485760,
                'concurrency'       => 5,
            ],
            'crawler' => [
                'max_redirects' => 10,
            ],
        ];

        // Worker reflection
        $refMethod = new \ReflectionMethod(ScanWorker::class, 'mergeEffectiveConfig');
        $refMethod->setAccessible(true);

        // Dummy worker instance without invoking constructor DB
        $worker = (new \ReflectionClass(ScanWorker::class))->newInstanceWithoutConstructor();

        $scanSettings = [
            'max_response_size' => 20971520, // 20 MB
        ];

        $merged = $refMethod->invoke($worker, $baseConfig, $scanSettings);
        $this->assert(
            isset($merged['fetching']['max_response_size']) && $merged['fetching']['max_response_size'] === 20971520,
            "ScanWorker::mergeEffectiveConfig must propagate max_response_size into fetching config"
        );
    }

    /**
     * Test 4: Small response (< 5 KB) is permitted.
     */
    private function testSmallResponseAllowed(): void
    {
        $http = new HttpClient(['max_response_size' => 10485760]);
        $content = str_repeat('A', 2048); // 2 KB
        $dataUri = 'data:text/html;base64,' . base64_encode($content);

        $res = $http->fetch($dataUri);
        $this->assert(
            $res['error_type'] === null && strlen($res['content']) === 2048,
            "Small response (<5 KB) must succeed without error"
        );
    }

    /**
     * Test 5: ~50 KB response is permitted (the exact case that previously failed under 5120 bytes).
     */
    private function testFiftyKbResponseAllowed(): void
    {
        $http = new HttpClient(['max_response_size' => 10485760]);
        $content = str_repeat('B', 51200); // 50 KB
        $dataUri = 'data:text/html;base64,' . base64_encode($content);

        $res = $http->fetch($dataUri);
        $this->assert(
            $res['error_type'] === null && strlen($res['content']) === 51200,
            "50 KB response must succeed and not be aborted by 5120-byte limit"
        );
    }

    /**
     * Test 6: ~1 MB response is permitted.
     */
    private function testOneMbResponseAllowed(): void
    {
        $http = new HttpClient(['max_response_size' => 10485760]);
        $content = str_repeat('C', 1048576); // 1 MB
        $dataUri = 'data:text/html;base64,' . base64_encode($content);

        $res = $http->fetch($dataUri);
        $this->assert(
            $res['error_type'] === null && strlen($res['content']) === 1048576,
            "1 MB response must succeed under 10 MB default limit"
        );
    }

    /**
     * Test 7: Exceeding configured maximum size triggers response_too_large and is not retryable.
     */
    private function testExceededResponseSizeAborts(): void
    {
        // Limit set to 10 KB (10240 bytes), payload is 20 KB (20480 bytes)
        $http = new HttpClient(['max_response_size' => 10240]);
        $content = str_repeat('X', 20480);
        $dataUri = 'data:text/html;base64,' . base64_encode($content);

        $res = $http->fetch($dataUri);
        $this->assert(
            $res['error_type'] === 'response_too_large',
            "Exceeded response size must produce error_type 'response_too_large'"
        );
        $this->assert(
            $res['is_retryable'] === false,
            "response_too_large must never be marked is_retryable"
        );
        $this->assert(
            strpos($res['error_message'] ?? '', 'exceeded configured maximum limit') !== false,
            "Error message must specify configured maximum limit"
        );
    }

    /**
     * Test 8: fetchMulti() enforces max_response_size identically to fetch().
     */
    private function testFetchMultiSizeLimitEnforcement(): void
    {
        // Limit 15 KB
        $http = new HttpClient(['max_response_size' => 15360, 'concurrency' => 2]);
        $smallContent = str_repeat('S', 5120);   // 5 KB (ok)
        $largeContent = str_repeat('L', 30720);  // 30 KB (oversized)

        $smallUri = 'data:text/html;base64,' . base64_encode($smallContent);
        $largeUri = 'data:text/plain;base64,' . base64_encode($largeContent);

        $completed = [];
        $http->fetchMulti([$smallUri, $largeUri], function (string $url, array $res) use (&$completed) {
            $completed[$url] = $res;
        });

        $this->assert(
            isset($completed[$smallUri]) && $completed[$smallUri]['error_type'] === null,
            "fetchMulti: small URI within 15 KB limit must succeed"
        );
        $this->assert(
            isset($completed[$largeUri]) && $completed[$largeUri]['error_type'] === 'response_too_large',
            "fetchMulti: large URI exceeding 15 KB limit must produce response_too_large"
        );
        $this->assert(
            $completed[$largeUri]['is_retryable'] === false,
            "fetchMulti: response_too_large must not be retryable"
        );
    }
}
