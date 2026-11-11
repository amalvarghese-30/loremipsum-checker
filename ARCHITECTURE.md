# Lorem Ipsum Checker — Architecture Reference (v2.0)

## High-Level Overview

```
┌──────────────────────────────────────────────────────────────────────┐
│                           Browser (SPA)                              │
│                           index.html                                 │
└──────────────────────────────┬───────────────────────────────────────┘
                               │  REST JSON over HTTP
                               │
┌──────────────────────────────▼───────────────────────────────────────┐
│                            api.php                                   │
│                                                                      │
│  ┌─────────────┐  ┌─────────────────┐  ┌────────────────────────┐   │
│  │ CORS Guard  │  │ Request Router  │  │ Worker Launcher        │   │
│  │ (env-config)│  │ (action param)  │  │ (PHP_BINARY from env)  │   │
│  └─────────────┘  └────────┬────────┘  └───────────┬────────────┘   │
│                            │                       │ proc_open       │
│           ┌────────────────┼──────────────────┐    │                 │
│           ▼                ▼                  ▼    ▼                 │
│  ProjectRepository  ScanRepository      UrlRepository               │
│  FindingRepository  LogRepository                                    │
└──────────────────────────────────────────────────────────────────────┘
           │  PDO (InnoDB)
┌──────────▼───────────────────────────────────────────────────────────┐
│                        MySQL Database                                │
│                                                                      │
│   projects          scans            discovered_urls                 │
│   page_results      findings         scan_resources                  │
│   url_discovery_sources              scan_settings                   │
│   scan_logs         detection_rules  schema_migrations               │
└──────────────────────────────────────────────────────────────────────┘

                        ┌──────────────────────────────────────────┐
                        │              worker.php                  │
                        │                                          │
                        │  1. Mark scan 'initializing'             │
                        │  2. DiscoveryManager.discover()          │
                        │     ├─ RobotsDiscovery                   │
                        │     ├─ SitemapDiscovery                  │
                        │     ├─ FeedDiscovery                     │
                        │     ├─ HtmlSitemapDiscovery              │
                        │     ├─ WordPressDiscovery                 │
                        │     ├─ LinkHeaderDiscovery               │
                        │     └─ InternalCrawler                   │
                        │  3. Batch-insert discovered URLs         │
                        │  4. HttpClient.fetchMulti() loop         │
                        │     ├─ Scan HTML + linked assets         │
                        │     ├─ Detect Lorem Ipsum patterns       │
                        │     ├─ Save findings incrementally       │
                        │     └─ Heartbeat every 15 s              │
                        │  5. Mark scan 'completed'                │
                        └──────────────────────────────────────────┘
```

---

## Directory Structure

```
LoremIpsum_checker/
├── api.php                          # Main HTTP entry point
├── worker.php                       # Background scan worker
├── config.php                       # Returns config array (env-driven)
├── index.html                       # Frontend SPA
├── htaccess                         # Apache rewrite + CORS headers
├── .env                             # Local secrets (not committed)
├── .env.example                     # Template for .env
│
├── includes/
│   ├── database.php                 # PDO singleton
│   ├── HttpClient.php               # cURL multi fetch service
│   ├── checker_helper.php           # Legacy HTTP helper (preserved)
│   ├── sitemap_helper.php           # Legacy sitemap helper (preserved)
│   │
│   ├── Discovery/
│   │   ├── DiscoveryManager.php     # Orchestrates all discovery methods
│   │   ├── UrlNormalizer.php        # URL normalization + dedup hashing
│   │   ├── SitemapDiscovery.php     # XML sitemap + sitemapindex parser
│   │   ├── RobotsDiscovery.php      # robots.txt → sitemap URLs
│   │   ├── FeedDiscovery.php        # RSS / Atom feed parser
│   │   ├── HtmlSitemapDiscovery.php # HTML sitemap page link extractor
│   │   ├── InternalCrawler.php      # BFS internal link crawler
│   │   ├── WordPressDiscovery.php   # WordPress REST API pages/posts
│   │   └── LinkHeaderDiscovery.php  # HTTP Link: header rel=sitemap
│   │
│   └── Repository/
│       ├── ProjectRepository.php    # CRUD for projects table
│       ├── ScanRepository.php       # CRUD + status for scans table
│       ├── UrlRepository.php        # discovered_urls management
│       ├── FindingRepository.php    # findings read/write
│       └── LogRepository.php        # scan_logs read/write
│
├── database/
│   ├── migrate.php                  # CLI migration runner
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
└── data/                            # Legacy JSON file storage (preserved)
    ├── projects/
    ├── logs/
    ├── sitemaps/
    └── locks/
```

---

## Data Flow: Starting a Scan

```
1. POST api.php?action=start_scan
   │
   ├─ ProjectRepository::createProject() or getProject()
   ├─ ScanRepository::createScan()       → scan row (status=queued)
   ├─ ScanRepository::saveScanSettings() → scan_settings row
   └─ proc_open("php worker.php <scan_id>")

2. worker.php <scan_id>
   │
   ├─ ScanRepository::updateScanStatus(initializing)
   ├─ DiscoveryManager::discover(baseUrl)
   │   └─ All discovery methods run in sequence (or parallel in future)
   │       Each returns: [{url, normalized_url, source, depth, metadata}]
   │
   ├─ UrlRepository::addDiscoveredUrl() per URL (SHA-256 dedup, batch)
   ├─ ScanRepository::updateScanStatus(scanning)
   │
   └─ SCAN LOOP:
       ├─ UrlRepository::getPendingUrls(limit=10)
       ├─ HttpClient::fetchMulti(urls) → concurrent fetch
       │   ├─ For each URL:
       │   │   ├─ Fetch HTML
       │   │   ├─ Detect Lorem Ipsum patterns
       │   │   ├─ Fetch linked JS/CSS/JSON (scan_resources)
       │   │   ├─ FindingRepository::saveFinding() per match
       │   │   └─ UrlRepository::updateUrlStatus(completed)
       │   └─ ScanRepository::updateScanCounters()
       ├─ ScanRepository::updateHeartbeat()
       └─ Check pause/cancel flags, loop or exit
```

---

## Data Flow: Polling Scan Status

```
GET api.php?action=get_status&scan_id=<id>
│
├─ ScanRepository::getScan(id)
├─ ScanRepository check last_heartbeat_at vs now
│   └─ If > stale_threshold: mark as 'interrupted'
├─ UrlRepository::getUrlStats(scan_id)
└─ Return JSON: {status, counts, elapsed, latest_logs}
```

---

## Data Flow: Retrieving Findings

```
GET api.php?action=get_findings&scan_id=<id>&page=1&per_page=50
│
├─ FindingRepository::getFindings(scan_id, filters, page, pageSize)
├─ FindingRepository::getFindingsCount(scan_id, filters)
└─ Return JSON: {findings: [...], total, page, pages}
```

---

## URL Discovery Flow

```
DiscoveryManager::discover(baseUrl, scanId)
│
├─ LinkHeaderDiscovery::discover()      → sitemap URLs
├─ RobotsDiscovery::discover()          → sitemap URLs
│   └─ SitemapDiscovery::parseSitemaps(urls)
├─ SitemapDiscovery::discover()         → page URLs (all common paths)
├─ FeedDiscovery::discover()            → page URLs (RSS/Atom items)
├─ HtmlSitemapDiscovery::discover()     → page URLs
├─ WordPressDiscovery::discover()       → page URLs (REST API)
└─ InternalCrawler::discover()          → page URLs (BFS, up to max_urls)
    │
    └─ All results merged, deduplicated by SHA-256(normalizedUrl)
       Returned as: [
         {
           'url'            => string,
           'normalized_url' => string,
           'url_hash'       => string,
           'source'         => string,
           'depth'          => int,
           'metadata'       => array
         }, ...
       ]
```

---

## HttpClient Concurrency Model

```
fetchMulti(urls = [u1, u2, ... uN], onComplete, options)
│
├─ Split into chunks by per_domain_concurrency
├─ Maintain global active slot count ≤ concurrency (default 5)
├─ curl_multi_init() + curl_multi_add_handle() per slot
├─ curl_multi_exec() / curl_multi_select() event loop
└─ onComplete($response) called per finished handle
    └─ onComplete may call: FindingRepository, UrlRepository, etc.
```

---

## Security Decisions

| Concern | Decision |
|---------|----------|
| CORS | Strict allowlist from `APP_ALLOWED_ORIGINS` env var |
| SQL Injection | PDO prepared statements throughout |
| SSRF | **Not blocked** — tool scans internal/dev sites by design |
| File paths | No user-controlled file path traversal in new code |
| Credentials | `.env` file, never in code or version control |
| PHP binary | `PHP_BINARY` constant (or env override) — no hardcoded path |

---

## Configuration Reference

All configuration is in `config.php` (returned as array) and driven by environment variables loaded from `.env`.

| Env Var | Default | Description |
|---------|---------|-------------|
| `DB_HOST` | `127.0.0.1` | MySQL host |
| `DB_PORT` | `3306` | MySQL port |
| `DB_NAME` | `lorem_ipsum_checker` | Database name |
| `DB_USER` | `root` | MySQL user |
| `DB_PASSWORD` | `` | MySQL password |
| `APP_ALLOWED_ORIGINS` | `http://localhost` | Comma-separated CORS origins |
| `PHP_CLI_BINARY` | (PHP_BINARY const) | PHP executable for worker spawn |
| `APP_ENV` | `development` | Environment tag |
