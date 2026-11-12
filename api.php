<?php
/**
 * Lorem Ipsum Checker — Main API Gateway
 *
 * RESTful JSON API providing scan lifecycle management,
 * results querying, historical comparisons, and exports.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('BASE_DIR', __DIR__);

// Load config
$config = require_once BASE_DIR . '/config.php';

// Include core modules
require_once BASE_DIR . '/includes/database.php';
require_once BASE_DIR . '/includes/QueryHelper.php';
require_once BASE_DIR . '/includes/HttpClient.php';
require_once BASE_DIR . '/includes/Discovery/UrlNormalizer.php';
require_once BASE_DIR . '/includes/Discovery/DiscoveryManager.php';
require_once BASE_DIR . '/includes/Repository/ProjectRepository.php';
require_once BASE_DIR . '/includes/Repository/ScanRepository.php';
require_once BASE_DIR . '/includes/Repository/UrlRepository.php';
require_once BASE_DIR . '/includes/Repository/PageResultRepository.php';
require_once BASE_DIR . '/includes/Repository/ResourceRepository.php';
require_once BASE_DIR . '/includes/Repository/FindingRepository.php';
require_once BASE_DIR . '/includes/Repository/LogRepository.php';
require_once BASE_DIR . '/includes/Repository/RuleRepository.php';

// Include API layer
require_once BASE_DIR . '/includes/Export/ExportService.php';
require_once BASE_DIR . '/includes/Api/Response.php';
require_once BASE_DIR . '/includes/Api/RequestValidator.php';
require_once BASE_DIR . '/includes/Api/ProjectController.php';
require_once BASE_DIR . '/includes/Api/ScanController.php';
require_once BASE_DIR . '/includes/Api/ResultsController.php';
require_once BASE_DIR . '/includes/Api/RulesController.php';
require_once BASE_DIR . '/includes/Api/ExportController.php';
require_once BASE_DIR . '/includes/Api/Router.php';

use LoremChecker\Api\Response;
use LoremChecker\Api\Router;
use LoremChecker\Database;

try {
    // 1. Strict CORS handling
    $allowedOrigins = $config['api']['allowed_origins'] ?? ['http://localhost', 'http://127.0.0.1'];
    $responseHelper = new Response($allowedOrigins);
    $responseHelper->handleCors();

    // 2. Database connection
    $db = Database::getInstance();

    // 3. Dispatch request
    $router = new Router($db, $config);
    $router->dispatch();

} catch (\InvalidArgumentException $e) {
    Response::error($e->getMessage(), 'VALIDATION_ERROR', 400);
} catch (\Throwable $e) {
    // Log internal error without exposing sensitive internals
    error_log('[API Error] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    Response::error(
        !empty($config['debug']['enabled']) ? $e->getMessage() : 'An internal server error occurred.',
        'INTERNAL_ERROR',
        500
    );
}
