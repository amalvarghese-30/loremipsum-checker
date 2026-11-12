<?php
/**
 * Database Query Helper & SQL Dialect Compatibility Layer
 *
 * Provides vendor-neutral SQL expressions and statement preparation
 * supporting both SQLite and MySQL seamlessly.
 */

namespace LoremChecker;

use PDO;

class QueryHelper
{
    /**
     * Check if the given PDO connection is using the SQLite driver.
     */
    public static function isSqlite(PDO $db): bool
    {
        return strtolower($db->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite';
    }

    /**
     * Get the SQL expression for the current datetime timestamp.
     * SQLite: datetime('now')
     * MySQL:  NOW()
     */
    public static function now(PDO $db): string
    {
        return self::isSqlite($db) ? "datetime('now')" : "NOW()";
    }

    /**
     * Get the SQL expression for the current datetime with millisecond precision if supported.
     * SQLite: datetime('now') or strftime('%Y-%m-%d %H:%M:%f', 'now')
     * MySQL:  NOW(3)
     */
    public static function nowMs(PDO $db): string
    {
        return self::isSqlite($db) ? "strftime('%Y-%m-%d %H:%M:%f', 'now')" : "NOW(3)";
    }

    /**
     * Adapt any SQL query containing MySQL-specific syntax to SQLite if connected to SQLite.
     * If connected to MySQL, returns the query intact.
     *
     * Automatically translates:
     * - NOW(3) -> strftime('%Y-%m-%d %H:%M:%f', 'now')
     * - NOW() -> datetime('now')
     * - INSERT IGNORE INTO -> INSERT OR IGNORE INTO
     * - IF(condition, true_val, false_val) -> CASE WHEN condition THEN true_val ELSE false_val END
     * - DATE_ADD(..., INTERVAL ? SECOND) -> datetime(..., '+' || ? || ' seconds')
     * - DATE_SUB(..., INTERVAL ? SECOND) -> datetime(..., '-' || ? || ' seconds')
     * - TIMESTAMPDIFF(SECOND, start, end) -> (strftime('%s', end) - strftime('%s', start))
     *
     * @param PDO $db
     * @param string $sql
     * @return string
     */
    public static function adapt(PDO $db, string $sql): string
    {
        if (!self::isSqlite($db)) {
            return $sql;
        }

        // 1. INSERT IGNORE INTO -> INSERT OR IGNORE INTO
        $sql = preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i', 'INSERT OR IGNORE INTO', $sql);

        // 2. NOW(3) / NOW()
        $sql = preg_replace('/\bNOW\s*\(\s*3\s*\)/i', "strftime('%Y-%m-%d %H:%M:%f', 'now')", $sql);
        $sql = preg_replace('/\bNOW\s*\(\s*\)/i', "datetime('now')", $sql);

        // 3. DATE_ADD(datetime('now'), INTERVAL ? SECOND) -> datetime('now', '+' || ? || ' seconds')
        $sql = preg_replace(
            '/DATE_ADD\s*\(\s*datetime\(\'now\'\)\s*,\s*INTERVAL\s+(\?|\d+)\s+SECOND\s*\)/i',
            "datetime('now', '+' || $1 || ' seconds')",
            $sql
        );
        $sql = preg_replace(
            '/DATE_ADD\s*\(\s*NOW\(\)\s*,\s*INTERVAL\s+(\?|\d+)\s+SECOND\s*\)/i',
            "datetime('now', '+' || $1 || ' seconds')",
            $sql
        );

        // 4. DATE_SUB(datetime('now'), INTERVAL ? SECOND) -> datetime('now', '-' || ? || ' seconds')
        $sql = preg_replace(
            '/DATE_SUB\s*\(\s*datetime\(\'now\'\)\s*,\s*INTERVAL\s+(\?|\d+)\s+SECOND\s*\)/i',
            "datetime('now', '-' || $1 || ' seconds')",
            $sql
        );
        $sql = preg_replace(
            '/DATE_SUB\s*\(\s*NOW\(\)\s*,\s*INTERVAL\s+(\?|\d+)\s+SECOND\s*\)/i',
            "datetime('now', '-' || $1 || ' seconds')",
            $sql
        );

        // 5. TIMESTAMPDIFF(SECOND, col1, col2) -> (CAST(strftime('%s', col2) AS INTEGER) - CAST(strftime('%s', col1) AS INTEGER))
        $sql = preg_replace_callback(
            '/TIMESTAMPDIFF\s*\(\s*SECOND\s*,\s*([^,]+)\s*,\s*([^)]+)\s*\)/i',
            function ($matches) {
                $start = trim($matches[1]);
                $end   = trim($matches[2]);
                return "(CAST(strftime('%s', {$end}) AS INTEGER) - CAST(strftime('%s', {$start}) AS INTEGER))";
            },
            $sql
        );

        return $sql;
    }

    /**
     * Prepare a statement with automatic dialect adaptation.
     *
     * @param PDO $db
     * @param string $sql
     * @return \PDOStatement
     */
    public static function prepare(PDO $db, string $sql): \PDOStatement
    {
        return $db->prepare(self::adapt($db, $sql));
    }
}
