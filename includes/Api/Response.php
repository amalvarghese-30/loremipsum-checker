<?php
/**
 * API Response Helper & CORS Enforcer
 *
 * Implements strict, configuration-driven CORS:
 * - Reads allowed origins from config/env (NO wildcards allowed)
 * - Returns Vary: Origin
 * - Handles OPTIONS preflight
 * - Formats consistent JSON envelopes:
 *     { "success": true, "data": {...}, "message": "..." }
 *     { "success": false, "error": { "code": "...", "message": "..." } }
 */

namespace LoremChecker\Api;

class Response
{
    public static bool $testMode = false;
    public static ?array $lastResponse = null;

    private array $allowedOrigins;

    public function __construct(array $allowedOrigins = [])
    {
        $this->allowedOrigins = $allowedOrigins;
    }

    /**
     * Apply strict CORS headers based on incoming request origin.
     */
    public function handleCors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

        header('Vary: Origin');

        if ($origin !== null) {
            $isAllowed = false;
            foreach ($this->allowedOrigins as $allowed) {
                if ($origin === $allowed || rtrim($origin, '/') === rtrim($allowed, '/')) {
                    $isAllowed = true;
                    break;
                }
            }

            if ($isAllowed) {
                header("Access-Control-Allow-Origin: {$origin}");
                header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
                header('Access-Control-Allow-Credentials: true');
            }
        }

        // Handle OPTIONS preflight
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit(0);
        }
    }

    public static function json(array $payload, int $statusCode = 200): void
    {
        self::$lastResponse = ['status' => $statusCode, 'payload' => $payload];
        if (self::$testMode) {
            return;
        }
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit(0);
    }

    public static function success(mixed $data = null, string $message = 'Operation completed successfully', int $statusCode = 200): void
    {
        self::json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
        ], $statusCode);
    }

    public static function error(string $message, string $code = 'BAD_REQUEST', int $statusCode = 400, ?array $details = null): void
    {
        $payload = [
            'success'       => false,
            'error'         => $message, // String message for 100% frontend toast compatibility
            'error_code'    => $code,
            'error_details' => [
                'code'    => $code,
                'message' => $message,
            ],
        ];

        if ($details !== null) {
            $payload['error_details']['details'] = $details;
        }

        self::json($payload, $statusCode);
    }
}
