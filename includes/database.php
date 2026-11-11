<?php
/**
 * Database singleton — PDO connection with manual .env loading.
 *
 * Usage:
 *   $pdo = \LoremChecker\Database::getInstance();
 *   $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
 *   $stmt->execute([$id]);
 */

namespace LoremChecker;

class Database
{
    /** @var \PDO|null */
    private static $instance = null;

    /** @var bool Whether the .env file has been loaded into the environment */
    private static $envLoaded = false;

    /**
     * Get (or create) the PDO singleton.
     *
     * @throws \RuntimeException on connection failure (message hides credentials)
     */
    public static function getInstance(): \PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::loadEnv();

        $host     = getenv('DB_HOST')     ?: '127.0.0.1';
        $port     = (int)(getenv('DB_PORT') ?: 3306);
        $dbName   = getenv('DB_NAME')     ?: 'lorem_ipsum_checker';
        $user     = getenv('DB_USER')     ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $dbName
        );

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            self::$instance = new \PDO($dsn, $user, $password, $options);
        } catch (\PDOException $e) {
            // Never expose credentials or the full DSN in the error message.
            throw new \RuntimeException(
                'Database connection failed: ' . self::sanitizeErrorMessage($e->getMessage())
            );
        }

        return self::$instance;
    }

    /**
     * Reset the singleton (useful for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Load the .env file from the project root into the process environment.
     * Skips lines that are blank or start with #.
     * Does NOT overwrite already-set environment variables so that system-level
     * env vars take precedence over the file.
     */
    private static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }

        // Look for .env next to this file, then in the project root (one dir up).
        $candidates = [
            dirname(__DIR__) . '/.env',
            __DIR__ . '/.env',
        ];

        $envFile = null;
        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                $envFile = $candidate;
                break;
            }
        }

        if ($envFile !== null) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);

                // Skip comments and empty lines.
                if ($line === '' || $line[0] === '#') {
                    continue;
                }

                // Split on the first '=' only.
                $eqPos = strpos($line, '=');
                if ($eqPos === false) {
                    continue;
                }

                $key   = trim(substr($line, 0, $eqPos));
                $value = trim(substr($line, $eqPos + 1));

                // Strip optional surrounding quotes (single or double).
                if (strlen($value) >= 2) {
                    $first = $value[0];
                    $last  = $value[strlen($value) - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }

                // Only set if not already defined in the real environment.
                if ($key !== '' && getenv($key) === false) {
                    putenv("$key=$value");
                    $_ENV[$key]    = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }

        self::$envLoaded = true;
    }

    /**
     * Remove host/port/credential details from PDO error messages before
     * surfacing them to callers.
     */
    private static function sanitizeErrorMessage(string $message): string
    {
        // Strip common patterns that might expose credentials or hostnames.
        $message = preg_replace('/password[=:][^\s]*/i', 'password=[REDACTED]', $message);
        $message = preg_replace('/user[=:][^\s]*/i', 'user=[REDACTED]', $message);
        // Keep the MySQL error code but remove the host from SQLSTATE messages.
        $message = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[host]', $message);
        return $message;
    }

    /** Prevent instantiation. */
    private function __construct() {}
    private function __clone() {}
}
