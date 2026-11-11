# Lorem Ipsum Checker — Database Architecture & Schema

The storage system uses **MySQL (InnoDB, utf8mb4)** with foreign key constraints, relational indexing, and transactions.

---

## Entity Relationship Overview

```
projects (1)
  │
  └──< scans (N)
         │
         ├───< scan_settings (1)
         ├───< scan_logs (N)
         ├───< discovered_urls (N)
         │       │
         │       └───< url_discovery_sources (N)
         │
         ├───< page_results (N)
         │       │
         │       └───< findings (N)
         │
         └───< scan_resources (N)
                 │
                 └───< findings (N)
```

---

## Tables

### 1. `projects`
Stores website audit projects. A project represents a website domain / audit target across multiple historical scans.
- `id` (BIGINT UNSIGNED, PK, AUTO_INCREMENT)
- `project_name` (VARCHAR(255))
- `website_url` (TEXT)
- `normalized_website_url` (VARCHAR(2048), indexed prefix)
- `created_at` (DATETIME)
- `updated_at` (DATETIME)
- `deleted_at` (DATETIME, NULL default, indexed for soft deletes)

### 2. `scans`
Stores individual audit executions.
- `id` (BIGINT UNSIGNED, PK, AUTO_INCREMENT)
- `project_id` (BIGINT UNSIGNED, FK -> projects.id ON DELETE CASCADE)
- `scan_number` (INT UNSIGNED, sequential scan counter for project)
- `status` (ENUM: `queued`, `initializing`, `discovering`, `scanning`, `pause_requested`, `paused`, `cancel_requested`, `cancelled`, `completed`, `failed`, `interrupted`)
- `started_at`, `paused_at`, `completed_at`, `last_heartbeat_at` (DATETIME)
- `worker_pid` (INT)
- `discovered_count`, `processed_count`, `clean_count`, `affected_page_count`, `failed_count`, `total_findings` (INT UNSIGNED)
- `elapsed_time` (INT UNSIGNED, seconds)
- `error_message` (TEXT)

### 3. `discovered_urls`
Stores all URLs discovered during an individual scan.
- `id` (BIGINT UNSIGNED, PK, AUTO_INCREMENT)
- `scan_id` (BIGINT UNSIGNED, FK -> scans.id ON DELETE CASCADE)
- `original_url` (TEXT)
- `normalized_url` (VARCHAR(2048))
- `canonical_url` (TEXT)
- `final_url` (TEXT)
- `url_hash` (CHAR(64), SHA-256 of normalized_url)
- `discovery_source` (VARCHAR(100): `sitemap_xml`, `robots_txt`, `internal_crawler`, `pagination`, `rss_feed`, `wordpress_api`, etc.)
- `crawl_depth` (TINYINT UNSIGNED)
- `status` (ENUM: `pending`, `queued`, `processing`, `retry_wait`, `completed`, `failed`, `needs_review`)
- `retry_count` (TINYINT UNSIGNED)
- `discovered_at`, `processed_at` (DATETIME)
- `last_error` (TEXT)
- **Constraint:** `UNIQUE KEY uq_scan_url_hash (scan_id, url_hash)` — prevents duplicate URLs within the same scan.

### 4. `url_discovery_sources`
Tracks multi-source lineage. A single URL might be found via Sitemap XML, internal crawler, and RSS simultaneously.
- `id` (BIGINT UNSIGNED, PK, AUTO_INCREMENT)
- `discovered_url_id` (BIGINT UNSIGNED, FK -> discovered_urls.id ON DELETE CASCADE)
- `scan_id` (BIGINT UNSIGNED)
- `source_type` (VARCHAR(100))
- `source_url` (TEXT)
- `crawl_depth` (TINYINT UNSIGNED)
- `discovered_at` (DATETIME)

### 5. `page_results`
Stores the result of fetching and auditing each webpage.
- `id` (BIGINT UNSIGNED, PK, AUTO_INCREMENT)
- `scan_id` (BIGINT UNSIGNED, FK -> scans.id ON DELETE CASCADE)
- `discovered_url_id` (BIGINT UNSIGNED, FK -> discovered_urls.id ON DELETE CASCADE)
- `requested_url` (TEXT)
- `final_url` (TEXT)
- `http_status` (SMALLINT)
- `content_type` (VARCHAR(255))
- `processing_status` (ENUM: `clean`, `findings_detected`, `failed`, `needs_review`, `cloudflare_blocked`, `bot_blocked`)
- `finding_count` (INT UNSIGNED)
- `duration_ms` (INT UNSIGNED)
- `retry_count` (TINYINT UNSIGNED)
- `error_type` (VARCHAR(100))
- `error_message` (TEXT)
- `page_title` (VARCHAR(1000))
- `word_count` (INT UNSIGNED)
- `has_lorem_ipsum` (TINYINT(1))
- `started_at`, `completed_at` (DATETIME)

### 6. `scan_resources`
Deduplicates external JavaScript, CSS, JSON, and XML resources across a scan.
- `id` (BIGINT UNSIGNED, PK, AUTO_INCREMENT)
- `scan_id` (BIGINT UNSIGNED, FK -> scans.id ON DELETE CASCADE)
- `page_result_id` (BIGINT UNSIGNED, NULL default)
- `resource_url` (TEXT)
- `normalized_resource_url` (VARCHAR(2048))
- `resource_hash` (CHAR(64))
- `resource_type` (ENUM: `javascript`, `css`, `json`, `xml`, `text`, `other`)
- `content_type` (VARCHAR(255))
- `http_status` (SMALLINT)
- `processing_status` (ENUM: `pending`, `completed`, `failed`, `skipped`)
- `response_size` (INT UNSIGNED)
- `duration_ms` (INT UNSIGNED)
- `finding_count` (INT UNSIGNED)
- `error_message` (TEXT)
- `fetched_at` (DATETIME)
- **Constraint:** `UNIQUE KEY uq_scan_resource_hash (scan_id, resource_hash)`

### 7. `findings`
Stores every individual detected placeholder match.
- `id` (BIGINT UNSIGNED, PK, AUTO_INCREMENT)
- `scan_id` (BIGINT UNSIGNED, FK -> scans.id ON DELETE CASCADE)
- `page_result_id` (BIGINT UNSIGNED, FK -> page_results.id ON DELETE CASCADE)
- `resource_id` (BIGINT UNSIGNED, NULL, FK -> scan_resources.id ON DELETE SET NULL)
- `page_url` (TEXT)
- `resource_url` (TEXT)
- `resource_type` (VARCHAR(50): `html`, `javascript`, `css`, `json`, `audit`)
- `matched_text` (TEXT)
- `surrounding_context` (TEXT)
- `html_element` (VARCHAR(100))
- `css_selector` (VARCHAR(500))
- `section_name` (VARCHAR(500))
- `line_number` (INT UNSIGNED)
- `character_position` (INT UNSIGNED)
- `is_visible` (TINYINT(1))
- `detection_rule_id` (BIGINT UNSIGNED)
- `detection_category` (VARCHAR(100))
- `severity` (ENUM: `high`, `medium`, `low`)
- `confidence` (ENUM: `high`, `medium`, `low`)
- `finding_fingerprint` (CHAR(64), indexed): `SHA-256(normalized_page_url + resource_type + matched_text_lower)`
- `created_at` (DATETIME)

### 8. `scan_settings`
Stores a snapshot of settings for a specific scan.
- `id` (BIGINT UNSIGNED, PK, AUTO_INCREMENT)
- `scan_id` (BIGINT UNSIGNED, UNIQUE, FK -> scans.id ON DELETE CASCADE)
- `settings_json` (LONGTEXT)
- `created_at` (DATETIME)

### 9. `detection_rules`
Stores built-in and custom placeholder detection regex rules.
- `id` (BIGINT UNSIGNED, PK, AUTO_INCREMENT)
- `rule_name` (VARCHAR(255))
- `pattern` (VARCHAR(1000))
- `detection_category` (VARCHAR(100))
- `enabled` (TINYINT(1))
- `case_sensitive` (TINYINT(1))
- `severity` (ENUM: `high`, `medium`, `low`)
- `confidence` (ENUM: `high`, `medium`, `low`)
- `source_types` (VARCHAR(500))
- `is_builtin` (TINYINT(1))
- `created_at`, `updated_at` (DATETIME)

### 10. `scan_logs`
Structured activity logs per scan.
- `id` (BIGINT UNSIGNED, PK, AUTO_INCREMENT)
- `scan_id` (BIGINT UNSIGNED, FK -> scans.id ON DELETE CASCADE)
- `project_id` (BIGINT UNSIGNED)
- `event_type` (VARCHAR(100))
- `log_level` (ENUM: `DEBUG`, `INFO`, `WARNING`, `ERROR`, `SUCCESS`)
- `message` (TEXT)
- `url` (TEXT)
- `discovery_source` (VARCHAR(100))
- `metadata_json` (TEXT)
- `created_at` (DATETIME(3))

### 11. `schema_migrations`
Tracks applied SQL migration scripts.
- `id` (INT UNSIGNED, PK, AUTO_INCREMENT)
- `migration` (VARCHAR(255), UNIQUE)
- `batch` (INT UNSIGNED)
- `executed_at` (DATETIME)
