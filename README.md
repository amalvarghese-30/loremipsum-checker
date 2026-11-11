# Lorem Ipsum & Content Placeholder Checker (v2.0)

An enterprise-grade, high-performance website audit engine designed to detect Lorem Ipsum, template placeholders, and content quality issues across websites, web applications, external JavaScript assets, CSS stylesheets, and JSON data.

---

## 🚀 Key Features

1. **Multi-Source Discovery Engine**:
   - XML Sitemaps (standard, sitemap indexes, multi-namespace, gzip).
   - `robots.txt` Sitemap declarations parsing (without skipping pages due to Disallow).
   - HTTP `Link` header discovery (`rel="sitemap"`).
   - RSS and Atom feed discovery (via `<link rel="alternate">` and common feed paths).
   - HTML sitemap page parsing (`/sitemap/`, `/sitemap.html`, etc.).
   - WordPress REST API discovery (`/wp-json/wp/v2/pages`, `/posts` with pagination).
   - BFS Internal Link Crawler with configurable depth and limits.
   - All enabled discovery methods contribute to a unified, deduplicated URL queue.

2. **Controlled Concurrent Scanning (PHP cURL Multi)**:
   - Configurable global concurrency (default: 5) and per-domain limits (default: 2).
   - Exponential backoff with random jitter for transient errors (408, 429, 500, 502, 503, 504).
   - Respects `Retry-After` headers.
   - Real-time incremental persistence to MySQL after each URL completes.

3. **Multi-Layer Content Inspection**:
   - DOM tree inspection: visible vs. hidden elements (`display:none`, `visibility:hidden`, `hidden` attribute).
   - HTML comments (`<!-- ... -->`).
   - Title, metadata (`<meta name="description">`), and input placeholder attributes.
   - Embedded JSON data (`<script type="application/ld+json">`).
   - External JavaScript files (`<script src="...">`) and CSS stylesheets (`<link rel="stylesheet">`).
   - Optional Playwright Node.js fallback for client-rendered JavaScript applications (SPAs).

4. **Internal Network & Localhost Support**:
   - Built for internal firm audits: supports `localhost`, `127.0.0.1`, private IPv4/IPv6 networks, internal hostnames, and custom ports.
   - Supports self-signed or untrusted SSL certificates when configured.

5. **Cross-Scan History & Comparison**:
   - Each audit is stored as an independent scan under a website project.
   - Finding fingerprints allow automated comparison between scans:
     - New findings
     - Persisting findings
     - Resolved findings (verified only when the page was successfully audited in the latest run).

6. **Relational MySQL Database with Legacy JSON Migration**:
   - 11 normalized tables with foreign keys and indexes.
   - Fully automated migration tool to import legacy `data/projects/*.json` files without data loss.

---

## 📋 Requirements

- **PHP 8.1 or higher** (PHP 8.2 or 8.3 recommended)
- **Required PHP Extensions:**
  - `pdo` and `pdo_mysql`
  - `curl`
  - `dom`
  - `xml`
  - `simplexml`
  - `json`
- **Database:** MySQL 5.7+ or MariaDB 10.3+
- **Web Server:** Apache (with `mod_rewrite` and `mod_headers`) or Nginx
- **Node.js (Optional):** Only required if running the Playwright fallback for SPA rendering.

---

## 🔧 Installation & Setup

### 1. Clone / Extract Repository
Place the repository files into your web root (e.g. `/var/www/html/loremipsum-checker` or `/home/user/public_html/LoremIpsum_checker`):
```bash
cd /path/to/LoremIpsum_checker
```

### 2. Configure Environment
Copy `.env.example` to `.env`:
```bash
cp .env.example .env
```
Edit `.env` and set your database connection and environment settings:
```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=lorem_ipsum_checker
DB_USER=root
DB_PASSWORD=your_secret_password

APP_ALLOWED_ORIGINS=http://localhost,http://127.0.0.1
PHP_CLI_BINARY=php
APP_ENV=production
```

### 3. Run Database Migrations
Create the tables and seed default detection rules:
```bash
php database/migrate.php --seed
```

### 4. (Optional) Migrate Legacy JSON Project Data
If you have existing scan data in `data/projects/`:
```bash
# Preview what will be imported
php database/migrate_legacy_json.php --dry-run

# Run the live import (automatically creates a backup of data/ first)
php database/migrate_legacy_json.php
```

### 5. Set File Permissions
Ensure the web server user (e.g. `www-data` or `apache`) has read and write permissions to `data/`:
```bash
chmod 755 api.php worker.php
chmod 644 index.html config.php
mkdir -p data/{projects,logs,sitemaps,locks}
chmod -R 775 data
```

---

## 🚀 Running the Background Worker

Workers run outside the browser request via CLI:

### Manual Worker Execution:
```bash
# Execute by numeric scan_id
php worker.php 1
```

### Automatic Worker Spawning:
When you click **Start Checking** in `index.html` or submit `POST api.php?action=start`, the API automatically launches `worker.php` in the background using the configured `PHP_CLI_BINARY`.

On Linux hosts, it runs via `nohup`:
```bash
nohup php worker.php 1 > data/logs/scan_1_worker.log 2>&1 &
```

---

## 🎭 Playwright JavaScript Rendering (Optional)

If scanning single-page applications (React, Vue, Angular) where raw HTML responses contain minimal content:
```bash
cd playwright
npm install
npx playwright install --with-deps chromium
```
Enable Playwright in `config.php`:
```php
'playwright' => [
    'enabled' => true,
    'timeout' => 45000,
    'min_content_length' => 500,
],
```

---

## 🎯 Usage

### Web Interface
1. Open `index.html` in your web browser.
2. Enter the target website URL (e.g., `https://example.com` or `http://localhost:3000`).
3. Click **Start Checking**.
4. Real-time progress updates, logs, and statistics stream automatically every 3 seconds.
5. Export results to CSV or JSON upon completion.

### API
See [API.md](file:///c:/Users/Amal%20Varghese/Downloads/loremipsum-checker/LoremIpsum_checker/API.md) for full documentation of endpoints, request bodies, filters, and responses.

---

## 📁 Project Directory Structure

```
├── api.php                        ← Main REST API gateway & router
├── worker.php                     ← Background CLI worker entrypoint
├── config.php                     ← Central configuration loader
├── index.html                     ← Web interface
├── htaccess                       ← Apache rules (strict CORS delegated to PHP)
├── .env.example                   ← Environment configuration template
│
├── database/
│   ├── migrate.php                ← Migration runner CLI
│   ├── migrate_legacy_json.php    ← Legacy JSON data import tool
│   └── migrations/
│       ├── 001_create_projects.sql
│       ├── 002_create_scans.sql
│       ├── 003_create_discovered_urls.sql
│       ├── 004_create_url_discovery_sources.sql
│       ├── 005_create_page_results.sql
│       ├── 006_create_scan_resources.sql
│       ├── 007_create_findings.sql
│       ├── 008_create_scan_settings.sql
│       ├── 009_create_detection_rules.sql
│       ├── 010_create_scan_logs.sql
│       └── 011_seed_detection_rules.sql
│
├── includes/
│   ├── database.php               ← PDO singleton connection
│   ├── HttpClient.php             ← Central HTTP client & cURL Multi
│   │
│   ├── Discovery/
│   │   ├── UrlNormalizer.php      ← Normalization & scope validation
│   │   ├── DiscoveryManager.php   ← Multi-method orchestrator
│   │   ├── SitemapDiscovery.php   ← XML sitemap & index recursive parser
│   │   ├── RobotsDiscovery.php    ← robots.txt sitemap parser
│   │   ├── LinkHeaderDiscovery.php← HTTP Link header sitemap parser
│   │   ├── FeedDiscovery.php      ← RSS & Atom feed parser
│   │   ├── HtmlSitemapDiscovery.php← HTML sitemap page parser
│   │   ├── WordPressDiscovery.php ← WordPress REST API crawler
│   │   └── InternalCrawler.php    ← BFS internal link crawler
│   │
│   ├── Detection/
│   │   ├── HtmlContentExtractor.php← DOM tree & visibility parser
│   │   ├── DetectionEngine.php    ← Multi-type pattern matcher
│   │   └── AuditModules.php       ← Missing title, alt-text, heading checks
│   │
│   ├── Repository/
│   │   ├── ProjectRepository.php
│   │   ├── ScanRepository.php
│   │   ├── UrlRepository.php
│   │   ├── PageResultRepository.php
│   │   ├── ResourceRepository.php
│   │   ├── FindingRepository.php
│   │   ├── LogRepository.php
│   │   └── RuleRepository.php
│   │
│   ├── Worker/
│   │   ├── ScanWorker.php         ← Worker orchestrator & lease manager
│   │   ├── ScanScheduler.php      ← Concurrent batch executor
│   │   └── ScanRecovery.php       ← Crash & timeout recovery
│   │
│   └── Api/
│       ├── Router.php             ← Action routing
│       ├── Response.php           ← Strict CORS & JSON envelope
│       ├── RequestValidator.php   ← Input & URL validator
│       ├── ProjectController.php
│       ├── ScanController.php
│       ├── ResultsController.php
│       └── ExportController.php
│
├── playwright/
│   ├── package.json
│   └── render-page.js             ← Headless browser renderer
│
└── tests/
    ├── run_tests.php              ← Test runner CLI
    ├── UrlNormalizerTest.php
    ├── DetectionEngineTest.php
    └── HtmlContentExtractorTest.php
```

---

## 🐛 Troubleshooting

### 1. Database Connection Fails
- Verify your credentials in `.env`.
- Ensure MySQL is accessible from PHP:
  ```bash
  php -r "new PDO('mysql:host=127.0.0.1;port=3306', 'root', ''); echo 'Connected';"
  ```

### 2. Worker Not Starting Automatically
- Check your `PHP_CLI_BINARY` setting in `.env` (e.g. `PHP_CLI_BINARY=/usr/bin/php83` or `PHP_CLI_BINARY=php`).
- Verify CLI execution permissions:
  ```bash
  chmod +x worker.php
  ```
- Check worker logs in `data/logs/scan_<id>_worker.log`.

### 3. Migrations or Tables Not Found
- Ensure you have executed:
  ```bash
  php database/migrate.php --seed
  ```
- Check status with:
  ```bash
  php database/migrate.php --status
  ```
