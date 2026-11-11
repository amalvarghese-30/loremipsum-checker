<?php
/**
 * Database Migration Runner (Supports both SQLite and MySQL)
 *
 * Runs all SQL migrations in order, tracking applied ones in schema_migrations.
 *
 * Usage:
 *   php database/migrate.php              # Run any pending migrations
 *   php database/migrate.php --fresh      # Drop all tables, run all migrations
 *   php database/migrate.php --seed       # Run migrations + seeder (011_seed_*)
 *   php database/migrate.php --fresh --seed
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/database.php';

try {
    $pdo = \LoremChecker\Database::getInstance();
    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
} catch (\RuntimeException $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

// Parse CLI arguments
$args  = array_slice($argv, 1);
$fresh = in_array('--fresh', $args, true);
$seed  = in_array('--seed',  $args, true);

function info(string $msg): void  { echo "[INFO]  $msg\n"; }
function warn(string $msg): void  { echo "[WARN]  $msg\n"; }
function migError(string $msg): void { echo "[ERROR] $msg\n"; }
function head(string $msg): void  { echo "\n$msg\n" . str_repeat('-', 60) . "\n"; }

info("Using database driver: " . strtoupper($driver));

// ── --fresh: drop everything ──────────────────────────────────────────────────
if ($fresh) {
    head('Dropping all application tables (--fresh)');

    if ($driver === 'mysql') {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    }

    $tables = [
        'scan_logs', 'findings', 'scan_resources', 'page_results',
        'url_discovery_sources', 'discovered_urls', 'scan_settings',
        'scans', 'projects', 'detection_rules', 'schema_migrations',
    ];

    foreach ($tables as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        info("Dropped table: {$table}");
    }

    if ($driver === 'mysql') {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

// ── Ensure tracker table exists ───────────────────────────────────────────────
if ($driver === 'sqlite') {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
            `migration`  TEXT NOT NULL UNIQUE,
            `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
} else {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `migration`  VARCHAR(255) NOT NULL,
            `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_migration` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// Discover migration files
$migrationsDir = __DIR__ . '/migrations';
$files = glob($migrationsDir . '/*.sql');

if (empty($files)) {
    warn("No migration files found in {$migrationsDir}");
    exit(0);
}

sort($files);

$applied = $pdo
    ->query("SELECT `migration` FROM `schema_migrations`")
    ->fetchAll(\PDO::FETCH_COLUMN);
$applied = array_flip($applied);

head('Running migrations');

$ran     = 0;
$skipped = 0;

foreach ($files as $file) {
    $filename = basename($file);

    if (!$seed && stripos($filename, 'seed') !== false) {
        warn("Skipping seeder (use --seed to run): {$filename}");
        $skipped++;
        continue;
    }

    if (isset($applied[$filename])) {
        info("Skip (already applied): {$filename}");
        $skipped++;
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        warn("Empty or unreadable file: {$filename}");
        $skipped++;
        continue;
    }

    if ($driver === 'sqlite') {
        $sql = convertMysqlToSqlite($sql);
    }

    try {
        $statements = preg_split('/;\s*(\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || $stmt === ';') {
                continue;
            }
            $pdo->exec($stmt);
        }

        $ins = $pdo->prepare("INSERT INTO `schema_migrations` (`migration`) VALUES (?)");
        $ins->execute([$filename]);

        info("Applied: {$filename}");
        $ran++;

    } catch (\PDOException $e) {
        migError("Failed on: {$filename}");
        migError($e->getMessage());
        exit(2);
    }
}

head('Done');
echo "Applied : {$ran}\n";
echo "Skipped : {$skipped}\n\n";
exit(0);

/**
 * Adapt MySQL table creation syntax to SQLite
 */
function convertMysqlToSqlite(string $sql): string
{
    // Remove ENGINE=..., DEFAULT CHARSET=..., COLLATE=...
    $sql = preg_replace('/ENGINE=\w+/i', '', $sql);
    $sql = preg_replace('/DEFAULT CHARSET=\w+/i', '', $sql);
    $sql = preg_replace('/COLLATE=\w+/i', '', $sql);

    // Convert AUTO_INCREMENT primary keys
    $sql = preg_replace('/`id`\s+BIGINT\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT/i', '`id` INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/`id`\s+INT\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT/i', '`id` INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/,\s*PRIMARY\s+KEY\s*\(`id`\)/i', '', $sql);

    // Remove index prefix lengths like (`normalized_website_url`(255)) -> (`normalized_website_url`)
    $sql = preg_replace('/\(`([^`]+)`\(\d+\)\)/', '(`$1`)', $sql);

    // Remove standalone table INDEX definitions (SQLite prefers CREATE INDEX outside, but ignores if removed or converted)
    $sql = preg_replace('/,\s*INDEX\s+`[^`]+`\s*\([^)]+\)/i', '', $sql);

    // Convert ENUMs to TEXT
    $sql = preg_replace('/ENUM\s*\([^)]+\)/i', 'TEXT', $sql);

    // Convert INT types
    $sql = preg_replace('/\b(TINYINT|SMALLINT|MEDIUMINT|BIGINT|INT)\s+UNSIGNED\b/i', 'INTEGER', $sql);
    $sql = preg_replace('/\b(TINYINT|SMALLINT|MEDIUMINT|BIGINT|INT)\b/i', 'INTEGER', $sql);
    $sql = preg_replace('/\bDATETIME\(\d+\)\b/i', 'DATETIME', $sql);
    $sql = preg_replace('/ON UPDATE CURRENT_TIMESTAMP/i', '', $sql);
    $sql = preg_replace('/COMMENT\s+\'[^\']*\'/i', '', $sql);

    return $sql;
}
