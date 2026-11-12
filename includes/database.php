<?php
/**
 * Database singleton — PDO connection supporting SQLite and MySQL.
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
     * @throws \RuntimeException on connection failure
     */
    public static function getInstance(): \PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::loadEnv();

        $driver = strtolower(getenv('DB_DRIVER') ?: 'sqlite');

        if ($driver === 'sqlite') {
            $rawPath = getenv('DB_SQLITE_PATH') ?: 'database/lorem_ipsum_checker.sqlite';
            // Resolve relative paths reliably against project root
            if (!preg_match('/^([a-zA-Z]:[\\\\\/]|\/)/', $rawPath)) {
                $sqlitePath = dirname(__DIR__) . '/' . ltrim($rawPath, '/\\');
            } else {
                $sqlitePath = $rawPath;
            }
            $dir = dirname($sqlitePath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            try {
                self::$instance = new \PDO("sqlite:{$sqlitePath}", null, null, [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]);
                self::$instance->exec('PRAGMA foreign_keys = ON;');
                self::$instance->exec('PRAGMA journal_mode = WAL;');
                return self::$instance;
            } catch (\PDOException $e) {
                throw new \RuntimeException('SQLite database connection failed: ' . $e->getMessage());
            }
        }

        // MySQL (Primary production database)
        $host     = getenv('DB_HOST')     ?: '127.0.0.1';
        $port     = (int)(getenv('DB_PORT') ?: 3306);
        $dbName   = getenv('DB_NAME')     ?: 'lorem_ipsum_checker';
        $user     = getenv('DB_USER')     ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            self::$instance = new \PDO($dsn, $user, $password, $options);
        } catch (\PDOException $e) {
            // If MySQL fails and DB_DRIVER was not explicitly specified, try SQLite fallback gracefully
            if (getenv('DB_DRIVER') === false) {
                $sqlitePath = dirname(__DIR__) . '/database/lorem_ipsum_checker.sqlite';
                $dir = dirname($sqlitePath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                try {
                    self::$instance = new \PDO("sqlite:{$sqlitePath}", null, null, [
                        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    ]);
                    self::$instance->exec('PRAGMA foreign_keys = ON;');
                    self::$instance->exec('PRAGMA journal_mode = WAL;');
                    return self::$instance;
                } catch (\PDOException $sqle) {
                    // Fall through to throw original MySQL error
                }
            }
            throw new \RuntimeException('Database connection failed: ' . self::sanitizeErrorMessage($e->getMessage()));
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

    private static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }

        $candidates = [
            dirname(__DIR__) . '/.env',
            __DIR__ . '/.env',
        ];

        foreach ($candidates as $envFile) {
            if (file_exists($envFile) && is_readable($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines !== false) {
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '' || $line[0] === '#') {
                            continue;
                        }
                        if (str_contains($line, '=')) {
                            [$key, $value] = explode('=', $line, 2);
                            $key   = trim($key);
                            $value = trim($value);
                            if (strlen($value) >= 2 && (
                                ($value[0] === '"' && $value[-1] === '"') ||
                                ($value[0] === "'" && $value[-1] === "'")
                            )) {
                                $value = substr($value, 1, -1);
                            }
                            if (getenv($key) === false && !array_key_exists($key, $_ENV)) {
                                putenv("{$key}={$value}");
                                $_ENV[$key] = $value;
                            }
                        }
                    }
                }
                self::$envLoaded = true;
                break;
            }
        }
    }

    private static function sanitizeErrorMessage(string $msg): string
    {
        $patterns = [
            '/user=\S+/i'     => 'user=***',
            '/password=\S+/i' => 'password=***',
            '/pwd=\S+/i'      => 'pwd=***',
            '/host=\S+/i'     => 'host=***',
        ];
        return preg_replace(array_keys($patterns), array_values($patterns), $msg) ?? 'Connection error';
    }
}
