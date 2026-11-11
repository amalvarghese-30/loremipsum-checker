# Lorem Ipsum Checker — Migration Guide

This guide describes how to migrate your environment from legacy JSON file-based storage to MySQL.

---

## Prerequisites

1. MySQL / MariaDB server installed and running.
2. PHP with `pdo_mysql`, `curl`, `dom`, `xml`, `simplexml`, `json` extensions.
3. Configure `.env` with your database credentials:
   ```bash
   cp .env.example .env
   ```
   Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`.

---

## Step 1: Run Database Schema Migrations

Run all database migrations using the automated migration runner:

```bash
php database/migrate.php --seed
```

### What this does:
- Creates the database (`lorem_ipsum_checker`) if it doesn't already exist.
- Creates `schema_migrations` tracking table.
- Executes versioned migrations `001_create_projects.sql` through `010_create_scan_logs.sql`.
- Seeds default detection rules via `011_seed_detection_rules.sql`.

### Other Migration Options:
- Check migration status:
  ```bash
  php database/migrate.php --status
  ```
- Dry-run migration:
  ```bash
  php database/migrate.php --dry-run
  ```
- Fresh re-install (drops existing tables and re-migrates):
  ```bash
  php database/migrate.php --fresh --seed
  ```

---

## Step 2: Migrate Existing JSON Project Data

To import historical projects, discovered URLs, results, and logs stored in `data/`:

### A. Run a Dry Run First:
```bash
php database/migrate_legacy_json.php --dry-run
```
This inspects `data/projects/*.json`, `data/sitemaps/*_urls.json`, and `data/logs/*.log` and previews what will be imported without making database modifications.

### B. Execute the Live Migration:
```bash
php database/migrate_legacy_json.php
```

### Safety Features:
1. **Automated Backup**: Automatically creates a timestamped archive copy of `data/` at `data/backup_YYYYMMDD_HHMMSS/` before performing any database transactions.
2. **Never Deletes Original JSON Files**: Existing `.json` files are preserved intact.
3. **Idempotent**: Safe to run multiple times. Skips projects or scans that have already been imported to prevent duplicates.
4. **Detailed Summary**: Generates a breakdown of projects, scans, URLs, results, and logs migrated.

---

## Step 3: Verifying Migration Success

1. Open your browser and navigate to `index.html`.
2. All previous projects (e.g., Caramelly and others) will appear in the dashboard list.
3. Click "Console" to review historical logs.
4. Export CSV or JSON to verify results integrity.

---

## Recovery from Migration Failures

If the JSON migration encounters a network or database interruption:
1. The migration wraps each project file in a dedicated database transaction (`BEGIN ... COMMIT / ROLLBACK`). An error on one project rolls back only that project, without leaving partial records or halting other projects.
2. Inspect the migration error output or `stats['errors']`.
3. Fix the underlying issue (e.g., MySQL disk space or configuration in `.env`).
4. Re-run `php database/migrate_legacy_json.php`. Already-imported records are recognized and safely skipped.
