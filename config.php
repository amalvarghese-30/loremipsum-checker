<?php
/**
 * Central application configuration.
 *
 * All settings are environment-driven so that no secrets or host-specific
 * paths are hard-coded here.  Values are read from the process environment
 * (which may have been populated by includes/database.php's .env loader, or
 * by the web-server / shell environment directly).
 *
 * Usage:
 *   $config = require __DIR__ . '/config.php';
 *   $maxUrls = $config['crawler']['default_max_urls'];
 */

// Ensure the .env file is parsed before we read getenv() values.
// database.php's static loadEnv() is idempotent, so calling it here is safe.
if (!class_exists('\LoremChecker\Database', false)) {
    require_once __DIR__ . '/includes/database.php';
}
// Trigger env loading without opening a DB connection.
// We do this by calling a lightweight helper that only loads the .env.
// Since loadEnv() is private, we rely on the fact that getInstance() calls it;
// but to avoid an actual DB connection here we use a small inline loader.
(static function () {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $candidates = [__DIR__ . '/.env'];
    foreach ($candidates as $file) {
        if (!is_readable($file)) {
            continue;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }
            $key   = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));
            if (strlen($value) >= 2) {
                $f = $value[0];
                $l = $value[strlen($value) - 1];
                if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            if ($key !== '' && getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key]    = $value;
                $_SERVER[$key] = $value;
            }
        }
        break; // Only load first found .env.
    }
    $loaded = true;
})();

return [

    // -------------------------------------------------------------------------
    // API / HTTP layer
    // -------------------------------------------------------------------------
    'api' => [
        'allowed_origins' => array_values(array_filter(
            array_map('trim', explode(',', getenv('APP_ALLOWED_ORIGINS') ?: 'http://localhost'))
        )),
        'user_agent' => 'LoremIpsumChecker/2.0 (+https://internal.tool)',
    ],

    // -------------------------------------------------------------------------
    // Database
    // -------------------------------------------------------------------------
    'database' => [
        'host'     => getenv('DB_HOST')     ?: '127.0.0.1',
        'port'     => (int)(getenv('DB_PORT') ?: 3306),
        'name'     => getenv('DB_NAME')     ?: 'lorem_ipsum_checker',
        'user'     => getenv('DB_USER')     ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
    ],

    // -------------------------------------------------------------------------
    // Crawler behaviour
    // -------------------------------------------------------------------------
    'crawler' => [
        'default_max_urls'  => 500,
        'default_max_depth' => 3,
        'follow_redirects'  => true,
        'max_redirects'     => 10,
        'connection_timeout' => 10,
        'request_timeout'   => 30,
    ],

    // -------------------------------------------------------------------------
    // URL Discovery methods
    // -------------------------------------------------------------------------
    'discovery' => [
        'sitemap'          => true,
        'robots_sitemap'   => true,
        'rss_atom'         => true,
        'html_sitemap'     => true,
        'internal_crawler' => true,
        'wordpress_api'    => true,
        'pagination'       => true,
        'search_engine'    => false,

        'common_sitemap_paths' => [
            '/sitemap.xml',
            '/sitemap_index.xml',
            '/sitemap-index.xml',
            '/wp-sitemap.xml',
            '/sitemap1.xml',
            '/sitemap/',
            '/sitemap.html',
            '/site-map/',
            '/site-map.html',
            '/news-sitemap.xml',
            '/product-sitemap.xml',
            '/page-sitemap.xml',
            '/category-sitemap.xml',
        ],
    ],

    // -------------------------------------------------------------------------
    // HTTP fetching
    // -------------------------------------------------------------------------
    'fetching' => [
        'concurrency'              => 5,
        'per_domain_concurrency'   => 2,
        'max_retries'              => 3,
        'retry_base_delay'         => 2,
        'max_retry_delay'          => 60,
        'max_response_size'        => 10485760, // 10 MB
        'request_delay'            => 1,        // seconds between requests to same domain
        'verify_ssl'               => false,
        'worker_heartbeat_interval' => 15,      // seconds
        'stale_worker_threshold'   => 120,      // seconds since last heartbeat before 'interrupted'
    ],

    // -------------------------------------------------------------------------
    // Lorem Ipsum detection rules (also seeded to detection_rules table)
    // -------------------------------------------------------------------------
    'detection' => [
        'default_rules' => [
            [
                'name'       => 'Lorem Ipsum Classic',
                'pattern'    => '/lorem\s+ipsum/i',
                'category'   => 'lorem_ipsum',
                'severity'   => 'high',
                'confidence' => 'high',
                'enabled'    => true,
            ],
            [
                'name'       => 'Dolor Sit Amet',
                'pattern'    => '/dolor\s+sit\s+amet/i',
                'category'   => 'lorem_ipsum',
                'severity'   => 'high',
                'confidence' => 'high',
                'enabled'    => true,
            ],
            [
                'name'       => 'Consectetur Adipiscing',
                'pattern'    => '/consectetur\s+adipiscing/i',
                'category'   => 'lorem_ipsum',
                'severity'   => 'high',
                'confidence' => 'high',
                'enabled'    => true,
            ],
            [
                'name'       => 'Sed Do Eiusmod',
                'pattern'    => '/sed\s+do\s+eiusmod\s+tempor/i',
                'category'   => 'lorem_ipsum',
                'severity'   => 'high',
                'confidence' => 'high',
                'enabled'    => true,
            ],
            [
                'name'       => 'Ut Labore Et Dolore',
                'pattern'    => '/ut\s+labore\s+et\s+dolore/i',
                'category'   => 'lorem_ipsum',
                'severity'   => 'high',
                'confidence' => 'high',
                'enabled'    => true,
            ],
            [
                'name'       => 'Magna Aliqua',
                'pattern'    => '/magna\s+aliqua/i',
                'category'   => 'lorem_ipsum',
                'severity'   => 'high',
                'confidence' => 'high',
                'enabled'    => true,
            ],
            [
                'name'       => 'Duis Aute Irure',
                'pattern'    => '/duis\s+aute\s+irure/i',
                'category'   => 'lorem_ipsum',
                'severity'   => 'high',
                'confidence' => 'high',
                'enabled'    => true,
            ],
            [
                'name'       => 'Excepteur Sint',
                'pattern'    => '/excepteur\s+sint\s+occaecat/i',
                'category'   => 'lorem_ipsum',
                'severity'   => 'high',
                'confidence' => 'high',
                'enabled'    => true,
            ],
            [
                'name'       => 'Nulla Pariatur',
                'pattern'    => '/nulla\s+pariatur/i',
                'category'   => 'lorem_ipsum',
                'severity'   => 'high',
                'confidence' => 'high',
                'enabled'    => true,
            ],
        ],
    ],

    // -------------------------------------------------------------------------
    // Legacy JSON file storage (kept for backward compatibility)
    // -------------------------------------------------------------------------
    'storage' => [
        'data_dir'     => __DIR__ . '/data',
        'projects_dir' => __DIR__ . '/data/projects',
        'logs_dir'     => __DIR__ . '/data/logs',
        'sitemaps_dir' => __DIR__ . '/data/sitemaps',
        'locks_dir'    => __DIR__ . '/data/locks',
    ],

    // -------------------------------------------------------------------------
    // Playwright (optional JS rendering — disabled by default)
    // -------------------------------------------------------------------------
    'playwright' => [
        'enabled' => false,
        'timeout' => 45000,
        'script'  => __DIR__ . '/playwright/render-page.js',
    ],

    // -------------------------------------------------------------------------
    // Background worker
    // -------------------------------------------------------------------------
    'worker' => [
        // PHP_BINARY is a PHP built-in constant with the full path to the
        // current PHP executable — no hardcoding needed.
        'php_binary' => getenv('PHP_CLI_BINARY') ?: PHP_BINARY,
        'script'     => __DIR__ . '/worker.php',
    ],

];
