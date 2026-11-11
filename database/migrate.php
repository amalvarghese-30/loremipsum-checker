<?php
/**
 * Database Migration Runner
 *
 * Runs all SQL migrations in order, tracking applied ones in schema_migrations.
 *
 * Usage:
 *   php database/migrate.php              # Run any pending migrations
 *   php database/migrate.php --fresh      # Drop all tables, run all migrations
 *   php database/migrate.php --seed       # Run migrations + seeder (011_seed_*)
 *   php database/migrate.php --fresh --seed
 *
 * This script can be run from any working directory.
 */

declare(strict_types=1);

// ── Bootstrap ───────────────────────────────────────────────────────────────

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/database.php';

try {
    $pdo = \LoremChecker\Database::getInstance();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Make sure .env is configured and MySQL is running.\n");
    exit(1);
}

// ── Parse CLI arguments ──────────────────────────────────────────────────────

$args  = array_slice($argv, 1);
$fresh = in_array('--fresh', $args, true);
$seed  = in_array('--seed',  $args, true);

// ── Helpers ──────────────────────────────────────────────────────────────────

function info(string $msg): void  { echo "\033[32m[INFO]\033[0m  $msg\n"; }
function warn(string $msg): void  { echo "\033[33m[WARN]\033[0m  $msg\n"; }
function migError(string $msg): void { echo "\033[31m[ERROR]\033[0m $msg\n"; }
function head(string $msg): void  { echo "\n\033[1m$msg\033[0m\n" . str_repeat('-', 60) . "\n"; }

// ── --fresh: drop everything ──────────────────────────────────────────────────

if ($fresh) {
    head('Dropping all application tables (--fresh)');

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $tables = [
        'scan_logs', 'findings', 'scan_resources', 'page_results',
        'url_discovery_sources', 'discovered_urls', 'scan_settings',
        'scans', 'projects', 'detection_rules', 'schema_migrations',
    ];

    foreach ($tables as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `$table`");
        info("Dropped table: $table");
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

// ── Ensure the migrations tracker table exists ────────────────────────────────

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `migration`  VARCHAR(255) NOT NULL,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_migration` (`migration`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Discover migration files ───────────────────────────────────────────────────

$migrationsDir = __DIR__ . '/migrations';
$files = glob($migrationsDir . '/*.sql');

if (empty($files)) {
    warn("No migration files found in $migrationsDir");
    exit(0);
}

sort($files);

// ── Fetch already-applied migrations ─────────────────────────────────────────

$applied = $pdo
    ->query("SELECT `migration` FROM `schema_migrations`")
    ->fetchAll(\PDO::FETCH_COLUMN);
$applied = array_flip($applied);

// ── Run pending migrations ────────────────────────────────────────────────────

head('Running migrations');

$ran     = 0;
$skipped = 0;

foreach ($files as $file) {
    $filename = basename($file);

    // Skip seeders unless --seed is passed.
    if (!$seed && stripos($filename, 'seed') !== false) {
        warn("Skipping seeder (use --seed to run): $filename");
        $skipped++;
        continue;
    }

    if (isset($applied[$filename])) {
        info("Skip (already applied): $filename");
        $skipped++;
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        warn("Empty or unreadable file, skipping: $filename");
        $skipped++;
        continue;
    }

    try {
        // Split on semicolons followed by newlines/EOF to support multi-statement files.
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

        info("Applied: $filename");
        $ran++;

    } catch (\PDOException $e) {
        migError("Failed on: $filename");
        migError($e->getMessage());
        exit(2);
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────

head('Done');
echo "Applied : $ran\n";
echo "Skipped : $skipped\n\n";
exit(0);
