# Lorem Ipsum Checker — Implementation Plan (v2.0)

## 1. Existing Problems

### Critical Issues
| # | Problem | Location | Impact |
|---|---------|----------|--------|
| 1 | Hardcoded PHP binary `/usr/bin/php83` | `api.php:371` | Breaks on any non-Alpine Linux host |
| 2 | JSON file storage | `api.php`, `worker.php` | Race conditions, data loss on crash, slow at scale |
| 3 | Sequential URL processing | `worker.php` | Scanning 1000 URLs takes ~30× longer than necessary |
| 4 | Wildcard CORS (`*`) | `api.php:44`, `htaccess:81` | Security risk in any authenticated context |
| 5 | Sitemap-only discovery stops on first found | `sitemap_helper.php` | Misses most of large sites |
| 6 | No database — results lost if worker crashes | Entire app | No scan history, no incremental saves |
| 7 | Fake pause (just sets JSON status) | `api.php` | Worker keeps running, wastes resources |
| 8 | No scan history — new scan overwrites old | `api.php` | Cannot compare scans over time |
| 9 | Results collected in memory, written at end | `worker.php` | Total data loss on timeout or OOM |
| 10 | Only raw HTML text scanned | `worker.php` | Misses Lorem Ipsum in JS/CSS/JSON assets |
| 11 | No URL normalization — duplicates possible | Discovery layer | Same page scanned multiple times |
| 12 | No robots.txt sitemap discovery | `sitemap_helper.php` | Misses sitemaps declared in robots.txt |
| 13 | Resume just changes status flag | `api.php` | Worker never actually restarts |
| 14 | Worker detection via log file (unreliable) | `api.php` | Ghost "running" state if log file exists |
| 15 | Missing DB config in `config.php` | `config.php` | Cannot add database without code changes |

---

## 2. Proposed Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                        index.html (SPA)                     │
└───────────────────────────────┬─────────────────────────────┘
                                │ JSON / HTTP
┌───────────────────────────────▼─────────────────────────────┐
│                           api.php                           │
│  - Strict CORS (env-configured allowed origins)             │
│  - Thin routing layer, delegates to Repositories            │
│  - Spawns worker via PHP_BINARY (from env)                  │
└──────┬───────────────────────────────────────┬──────────────┘
       │ PDO                                   │ proc_open
┌──────▼────────┐                    ┌─────────▼─────────────┐
│  MySQL (InnoDB│                    │      worker.php        │
│  utf8mb4)     │                    │  - Heartbeat loop      │
│               │◄───────────────────│  - DiscoveryManager    │
│  11 tables    │                    │  - HttpClient (multi)  │
│  (see §4)     │                    │  - Incremental saves   │
└───────────────┘                    └────────────────────────┘
```

### Layer Responsibilities
- **`config.php`** — returns single config array, reads from `.env`
- **`includes/database.php`** — PDO singleton, parses `.env`
- **`includes/HttpClient.php`** — cURL multi, retries, bot-detection
- **`includes/Discovery/*`** — pluggable discovery strategies
- **`includes/Repository/*`** — all DB access, PDO prepared statements
- **`database/migrations/`** — ordered SQL migration files
- **`database/migrate.php`** — migration runner (tracks applied migrations)

---

## 3. Implementation Phases

### Phase 0 — Documentation ✅
- `IMPLEMENTATION_PLAN.md` (this file)
- `ARCHITECTURE.md`

### Phase 1 — Central Configuration + Database Connection ✅
- `.env.example` (never committed with real credentials)
- `includes/database.php` — PDO singleton, manual `.env` parser
- `config.php` — complete config array driven by env vars

### Phase 2 — Database Schema + Migrations ✅
- `database/migrations/001_create_projects.sql`
- `database/migrations/002_create_scans.sql`
- `database/migrations/003_create_discovered_urls.sql`
- `database/migrations/004_create_url_discovery_sources.sql`
- `database/migrations/005_create_page_results.sql`
- `database/migrations/006_create_scan_resources.sql`
- `database/migrations/007_create_findings.sql`
- `database/migrations/008_create_scan_settings.sql`
- `database/migrations/009_create_detection_rules.sql`
- `database/migrations/010_create_scan_logs.sql`
- `database/migrations/011_seed_detection_rules.sql`
- `database/migrate.php` — idempotent runner with `--fresh` / `--seed`

### Phase 3 — HTTP Fetching Service ✅
- `includes/HttpClient.php`
  - cURL multi for concurrency
  - Exponential backoff + jitter
  - `Retry-After` header respect
  - Size-limited streaming
  - Content-type detection (header + sniff)
  - Cloudflare / WAF detection
  - Full redirect chain tracking
  - Private/localhost IP allowed (no SSRF blocking)

### Phase 4 — URL Normalization ✅
- `includes/Discovery/UrlNormalizer.php`
  - Absolute / relative / protocol-relative
  - Fragment stripping
  - Tracking-param removal
  - Lowercase hostname, remove default ports
  - Path dot-segment resolution
  - Scope checking (`host` or `domain`)

### Phase 5 — Modular Discovery Engine ✅
- `includes/Discovery/DiscoveryManager.php` — orchestrator
- `includes/Discovery/SitemapDiscovery.php` — XML sitemap + index
- `includes/Discovery/RobotsDiscovery.php` — robots.txt Sitemap: lines
- `includes/Discovery/FeedDiscovery.php` — RSS/Atom
- `includes/Discovery/HtmlSitemapDiscovery.php` — HTML sitemap pages
- `includes/Discovery/InternalCrawler.php` — BFS link crawler
- `includes/Discovery/WordPressDiscovery.php` — WP REST API
- `includes/Discovery/LinkHeaderDiscovery.php` — HTTP Link headers

### Phase 6 — Repository Pattern ✅
- `includes/Repository/ProjectRepository.php`
- `includes/Repository/ScanRepository.php`
- `includes/Repository/UrlRepository.php`
- `includes/Repository/FindingRepository.php`
- `includes/Repository/LogRepository.php`

---

## 4. Database Schema Overview

```
projects ─┐
          ├─► scans ─┬─► scan_settings
          │           ├─► scan_logs
          │           ├─► discovered_urls ──► url_discovery_sources
          │           ├─► page_results ──────► findings
          │           └─► scan_resources ────► findings
          └─► (soft-deleted via deleted_at)

detection_rules  (global, referenced by findings.detection_rule_id)
schema_migrations (migration tracker)
```

---

## 5. Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| PDO + prepared statements | SQL injection prevention, portability |
| Namespaces `LoremChecker\*` | Avoids global namespace pollution, autoloadable |
| `.env` file (manual parse) | No Composer dependency required |
| Keep `data/` directory | Backward compat with existing JSON projects |
| No SSRF blocking | Tool is for scanning internal/dev sites |
| All discovery methods run in parallel | Maximise URL coverage |
| SHA-256 URL hash for dedup | O(1) duplicate detection at DB level |
| `finding_fingerprint` on findings | Prevents duplicate findings across retries |
| Heartbeat + stale detection | Auto-recover from crashed workers |
| `scan_number` on scans | Multiple scans per project, history preserved |

---

## 6. Migration & Upgrade Path for Existing Data

The 13 existing JSON project files in `data/projects/` remain untouched.  
A future `database/import_legacy.php` script (Phase 7+) will:
1. Read each JSON project file
2. Create a `projects` row
3. Create a `scans` row (status = completed)
4. Import URL results into `page_results`
5. Import findings into `findings`

This keeps backward compatibility while the new DB-backed path is active.
