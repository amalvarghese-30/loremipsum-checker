<?php
/**
 * Test Runner
 *
 * Runs all unit tests and outputs pass/fail status and summary.
 * Usage: php tests/run_tests.php
 */

declare(strict_types=1);

require_once __DIR__ . '/UrlNormalizerTest.php';
require_once __DIR__ . '/DetectionEngineTest.php';
require_once __DIR__ . '/HtmlContentExtractorTest.php';
require_once __DIR__ . '/AuditModulesTest.php';
require_once __DIR__ . '/RepositoryIntegrationTest.php';

echo "========================================================\n";
echo "       LOREM IPSUM CHECKER - AUTOMATED TEST SUITE        \n";
echo "========================================================\n\n";

$tests = [
    'UrlNormalizerTest'        => new UrlNormalizerTest(),
    'DetectionEngineTest'      => new DetectionEngineTest(),
    'HtmlContentExtractorTest' => new HtmlContentExtractorTest(),
    'AuditModulesTest'         => new AuditModulesTest(),
    'RepositoryIntegrationTest'=> new RepositoryIntegrationTest(),
];

$passedSuites = 0;
$failedSuites = 0;
$totalAssertions = 0;

foreach ($tests as $name => $testInstance) {
    echo "Running {$name}... ";
    try {
        $testInstance->run();
        $totalAssertions += $testInstance->assertions;
        echo "✓ PASSED ({$testInstance->assertions} assertions)\n";
        $passedSuites++;
    } catch (\Throwable $e) {
        echo "✗ FAILED\n";
        echo "  " . $e->getMessage() . "\n";
        echo "  at " . $e->getFile() . ":" . $e->getLine() . "\n\n";
        $failedSuites++;
    }
}

echo "\n========================================================\n";
echo "Test Suites:  " . ($passedSuites + $failedSuites) . " total, {$passedSuites} passed, {$failedSuites} failed\n";
echo "Assertions:   {$totalAssertions} passed\n";
echo "========================================================\n";

exit($failedSuites > 0 ? 1 : 0);
