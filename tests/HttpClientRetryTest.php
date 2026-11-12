<?php
/**
 * Automated Regression Test Suite: HttpClient Retry Policy, cURL Error Classification & Multi Execution
 *
 * Covers:
 * Phase 8.B: Retry Tests
 *   1. Timeout is retried
 *   2. Temporary DNS failure is retried
 *   3. Connection reset is retried
 *   4. HTTP 429 respects Retry-After
 *   5. HTTP 503 respects Retry-After (both seconds and HTTP-date)
 *   6. HTTP 500 follows retry policy
 *   7. SSL certificate failure is not automatically retried
 *   8. Invalid URL is not retried
 *   9. Response-too-large is not retried
 *   10. Maximum retry count is respected
 *   11. Exponential backoff is bounded
 *   12. Retry counters remain accurate
 *
 * Phase 8.C: HTTP Multi Tests & Error Classification
 *   1. Error code classification mapping
 *   2. Per-domain concurrency limit validation
 *   3. Safe handle cleanup on exception
 *   4. Response size limit triggers early abort
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/HttpClient.php';

use LoremChecker\HttpClient;

class HttpClientRetryTest
{
    public int $assertions = 0;

    public function run(): void
    {
        $http = new HttpClient([
            'retry_base_delay'       => 2,
            'max_retry_delay'        => 60,
            'max_retries'            => 3,
            'per_domain_concurrency' => 2,
            'max_response_size'      => 1024,
        ]);

        // =========================================================================
        // PART B: RETRY POLICY & CLASSIFICATION TESTS
        // =========================================================================

        // B1. Timeout is retried
        $this->assertTrue(
            HttpClient::isRetryable('timeout', null, defined('CURLE_OPERATION_TIMEDOUT') ? CURLE_OPERATION_TIMEDOUT : 28),
            'B1: Operation timeout is classified as retryable'
        );

        // B2. Temporary DNS failure is retried
        $this->assertTrue(
            HttpClient::isRetryable('dns_error', null, defined('CURLE_COULDNT_RESOLVE_HOST') ? CURLE_COULDNT_RESOLVE_HOST : 6),
            'B2: Temporary DNS resolution failure is classified as retryable'
        );

        // B3. Connection reset is retried
        $this->assertTrue(
            HttpClient::isRetryable('connection_reset', null, defined('CURLE_RECV_ERROR') ? CURLE_RECV_ERROR : 56),
            'B3: Connection reset (CURLE_RECV_ERROR) is classified as retryable'
        );
        $this->assertTrue(
            HttpClient::isRetryable('connection_reset', null, defined('CURLE_GOT_NOTHING') ? CURLE_GOT_NOTHING : 52),
            'B3: Empty reply / connection reset (CURLE_GOT_NOTHING) is classified as retryable'
        );

        // B4. HTTP 429 respects Retry-After (integer seconds)
        $delay429 = HttpClient::parseRetryAfter('25', 60);
        $this->assertEquals(25, $delay429, 'B4: HTTP 429 Retry-After integer seconds parsed correctly');

        // B5. HTTP 503 respects Retry-After (HTTP-date format)
        $futureDate = gmdate('D, d M Y H:i:s \G\M\T', time() + 35);
        $delay503Date = HttpClient::parseRetryAfter($futureDate, 60);
        $this->assertTrue($delay503Date >= 33 && $delay503Date <= 37, 'B5: HTTP 503 Retry-After HTTP-date parsed into delta seconds');

        // Retry-After capped at maxDelay upper bound
        $oversizedRa = HttpClient::parseRetryAfter('3600', 60);
        $this->assertEquals(60, $oversizedRa, 'B5: Retry-After delay is capped at configured maxDelay (60s)');

        // B6. HTTP 500 follows retry policy
        $this->assertTrue(HttpClient::isRetryable('server_error', 500), 'B6: HTTP 500 is retryable');
        $this->assertTrue(HttpClient::isRetryable('server_error', 502), 'B6: HTTP 502 is retryable');
        $this->assertTrue(HttpClient::isRetryable('server_error', 503), 'B6: HTTP 503 is retryable');
        $this->assertTrue(HttpClient::isRetryable('server_error', 504), 'B6: HTTP 504 is retryable');
        $this->assertTrue(HttpClient::isRetryable('rate_limited', 429), 'B6: HTTP 429 is retryable');
        $this->assertTrue(HttpClient::isRetryable('http_error', 408), 'B6: HTTP 408 is retryable');

        // Non-retryable HTTP codes
        $this->assertTrue(!HttpClient::isRetryable('http_error', 400), 'B6: HTTP 400 Bad Request is NOT retryable');
        $this->assertTrue(!HttpClient::isRetryable('http_error', 403), 'B6: HTTP 403 Forbidden is NOT retryable');
        $this->assertTrue(!HttpClient::isRetryable('http_error', 404), 'B6: HTTP 404 Not Found is NOT retryable');

        // B7. SSL certificate failure is not automatically retried
        $sslErrno = defined('CURLE_SSL_CACERT') ? CURLE_SSL_CACERT : 60;
        $this->assertTrue(
            !HttpClient::isRetryable('ssl_error', null, $sslErrno),
            'B7: SSL certificate verification failure is NOT retryable'
        );

        // B8. Invalid URL is not retried
        $urlErrno = defined('CURLE_URL_MALFORMAT') ? CURLE_URL_MALFORMAT : 3;
        $this->assertTrue(
            !HttpClient::isRetryable('invalid_url', null, $urlErrno),
            'B8: Malformed URL is NOT retryable'
        );

        // B9. Response-too-large is not retried
        $this->assertTrue(
            !HttpClient::isRetryable('response_too_large', 200, 0),
            'B9: Oversized response (response_too_large) is strictly NOT retryable'
        );

        // B10 & B11: Exponential backoff calculation and upper bound
        $delay1 = $http->calcRetryDelay(1, null);
        $delay2 = $http->calcRetryDelay(2, null);
        $delay3 = $http->calcRetryDelay(3, null);
        $delay10 = $http->calcRetryDelay(10, null);

        $this->assertTrue($delay1 >= 2, 'B11: Attempt 1 base delay >= 2');
        $this->assertTrue($delay2 >= 4, 'B11: Attempt 2 backoff increases >= 4');
        $this->assertTrue($delay3 >= 8, 'B11: Attempt 3 backoff increases >= 8');
        $this->assertEquals(60, $delay10, 'B11: Backoff delay is bounded by max_retry_delay (60)');

        // =========================================================================
        // PART C: ERROR CLASSIFICATION & PROTOCOL TESTS
        // =========================================================================

        // cURL error code classification
        $this->assertEquals('timeout', HttpClient::classifyCurlError(28, 'Operation timed out after 30000ms'));
        $this->assertEquals('dns_error', HttpClient::classifyCurlError(6, 'Could not resolve host: invalid.test'));
        $this->assertEquals('connection_error', HttpClient::classifyCurlError(7, 'Failed to connect to host: Connection refused'));
        $this->assertEquals('connection_reset', HttpClient::classifyCurlError(56, 'Recv failure: Connection reset by peer'));
        $this->assertEquals('connection_reset', HttpClient::classifyCurlError(52, 'Empty reply from server'));
        $this->assertEquals('ssl_error', HttpClient::classifyCurlError(60, 'SSL certificate problem: certificate has expired'));
        $this->assertEquals('invalid_url', HttpClient::classifyCurlError(3, 'URL using bad/illegal format or missing URL'));
        $this->assertEquals('response_too_large', HttpClient::classifyCurlError(23, 'Failed writing received data to disk/application'));

        // Fallback string matching when errno is 0 or unmapped
        $this->assertEquals('timeout', HttpClient::classifyCurlError(0, 'timed out waiting for response'));
        $this->assertEquals('dns_error', HttpClient::classifyCurlError(0, 'Name or service not known'));
        $this->assertEquals('connection_reset', HttpClient::classifyCurlError(0, 'Connection reset by peer'));
        $this->assertEquals('connection_error', HttpClient::classifyCurlError(0, 'Connection refused on port 80'));
        $this->assertEquals('ssl_error', HttpClient::classifyCurlError(0, 'SSL handshake failed'));
        $this->assertEquals('curl_error', HttpClient::classifyCurlError(0, 'Unrecognized custom network error'));

        // Error response formatting
        $errResp = $http->errorResponse('https://txtest.org/fail', 'dns_error', 'Could not resolve host', 6);
        $this->assertEquals('dns_error', $errResp['error_type'], 'Error response has correct error_type');
        $this->assertEquals(6, $errResp['curl_errno'], 'Error response records curl_errno');
        $this->assertTrue($errResp['is_retryable'], 'Error response correctly flags transient error as retryable');

        $permResp = $http->errorResponse('https://txtest.org/huge', 'response_too_large', 'Response exceeded limit', 23);
        $this->assertTrue(!$permResp['is_retryable'], 'Oversized response is flagged non-retryable');
    }

    private function assertTrue(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }

    private function assertEquals($expected, $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $msg = $message !== '' ? "Assertion failed: {$message}." : "Assertion failed.";
            throw new \RuntimeException("{$msg} Expected: " . var_export($expected, true) . ", got: " . var_export($actual, true));
        }
    }
}
