# Lorem Ipsum Checker — API Documentation (v2.0)

All requests and responses use JSON (`application/json; charset=utf-8`).
Strict CORS is enforced based on `APP_ALLOWED_ORIGINS` in `.env`.

---

## Base URL
`/api.php`

---

## Standard Response Format

### Success Response
```json
{
  "success": true,
  "data": { ... },
  "message": "Operation completed successfully"
}
```

### Error Response
```json
{
  "success": false,
  "error": "Error message for frontend display",
  "error_code": "INVALID_REQUEST",
  "error_details": {
    "code": "INVALID_REQUEST",
    "message": "Error message for frontend display"
  }
}
```

---

## Endpoints

### 1. Start a New Scan
- **Method:** `POST`
- **Action:** `action=start`
- **Parameters (Form Data / POST):**
  - `website_url` *(string, required)*: Target URL (`http://` or `https://`). Supports public domains, internal hostnames, localhost, private IPs, and custom ports.
  - `project_name` *(string, optional)*: Human-readable name. Defaults to website hostname.
  - `use_sitemap` *(string, optional)*: `'auto'` to discover sitemaps.
  - `max_urls` *(int, optional)*: Overrides maximum URL discovery limit.
  - `max_depth` *(int, optional)*: Overrides maximum crawling depth.
  - `concurrency` *(int, optional)*: Overrides concurrent request limit.
- **Response:**
```json
{
  "success": true,
  "data": {
    "project_id": "1",
    "scan_id": 1,
    "status": "initializing",
    "message": "Scan initialized and worker dispatched",
    "worker_started": true
  }
}
```

---

### 2. Get Scan Status & Real-Time Progress
- **Method:** `GET`
- **Action:** `action=status`
- **Query Parameters:**
  - `project_id` *(int, required)*: Project ID.
  - `limit` *(int, optional)*: Number of recent log entries to return (default: 25).
- **Response:**
```json
{
  "success": true,
  "data": {
    "project": {
      "id": "1",
      "scan_id": 1,
      "project_name": "Caramelly",
      "website_url": "https://caramelly.in/",
      "status": "scanning",
      "created_at": "2026-11-11 02:00:00",
      "updated_at": "2026-11-11 02:05:00",
      "progress": {
        "total_urls": 323,
        "processed": 140,
        "clean": 138,
        "lorem_found": 2,
        "errors": 0,
        "percentage": 43.34
      }
    },
    "logs": [
      {
        "timestamp": "2026-11-11 02:05:00",
        "level": "INFO",
        "message": "Clean: https://caramelly.in/about"
      }
    ]
  }
}
```

---

### 3. List All Projects
- **Method:** `GET`
- **Action:** `action=list`
- **Response:**
```json
{
  "success": true,
  "data": {
    "projects": [
      {
        "id": "1",
        "project_name": "Caramelly",
        "website_url": "https://caramelly.in/",
        "status": "completed",
        "created_at": "2026-11-11 02:00:00",
        "updated_at": "2026-11-11 02:06:00",
        "progress": {
          "total_urls": 323,
          "processed": 323,
          "clean": 321,
          "lorem_found": 2,
          "errors": 0,
          "percentage": 100
        },
        "latest_scan_id": 1
      }
    ]
  }
}
```

---

### 4. Pause an Active Scan
- **Method:** `POST` or `GET`
- **Action:** `action=pause`
- **Parameters:**
  - `project_id` *(int, required)*
- **Response:**
```json
{
  "success": true,
  "data": {
    "message": "Pause signal issued to worker"
  }
}
```

---

### 5. Resume a Paused Scan
- **Method:** `POST` or `GET`
- **Action:** `action=resume`
- **Parameters:**
  - `project_id` *(int, required)*
- **Response:**
```json
{
  "success": true,
  "data": {
    "message": "Scan resumed",
    "worker_started": true
  }
}
```

---

### 6. Cancel a Scan
- **Method:** `POST` or `GET`
- **Action:** `action=cancel`
- **Parameters:**
  - `project_id` *(int, required)*
- **Response:**
```json
{
  "success": true,
  "data": {
    "message": "Cancellation signal issued to worker"
  }
}
```

---

### 7. Delete a Project
- **Method:** `POST` or `GET`
- **Action:** `action=delete`
- **Parameters:**
  - `project_id` *(int, required)*
- **Response:**
```json
{
  "success": true,
  "data": {
    "message": "Project deleted successfully"
  }
}
```

---

### 8. Get Paginated Page Results
- **Method:** `GET`
- **Action:** `action=results`
- **Parameters:**
  - `scan_id` *(int, required)*
  - `page` *(int, optional, default: 1)*
  - `limit` *(int, optional, default: 50, max: 200)*
  - `status` *(string, optional)*: Filter by `clean`, `findings_detected`, `failed`, `cloudflare_blocked`
  - `has_lorem` *(int, optional)*: `1` for pages with matches, `0` for clean
  - `search` *(string, optional)*: URL or page title search term
  - `sort_by` *(string, optional)*: `id`, `duration_ms`, `finding_count`, `http_status`
  - `sort_dir` *(string, optional)*: `ASC` or `DESC`
- **Response:**
```json
{
  "success": true,
  "data": {
    "results": [...],
    "pagination": {
      "page": 1,
      "limit": 50,
      "total_items": 323,
      "total_pages": 7
    }
  }
}
```

---

### 9. Get Paginated Findings
- **Method:** `GET`
- **Action:** `action=findings`
- **Parameters:**
  - `scan_id` *(int, required)*
  - `page` *(int, optional, default: 1)*
  - `limit` *(int, optional, default: 50, max: 200)*
  - `category` *(string, optional)*: `lorem_ipsum` or `placeholder`
  - `severity` *(string, optional)*: `high`, `medium`, `low`
  - `confidence` *(string, optional)*: `high`, `medium`, `low`
  - `resource_type` *(string, optional)*: `html`, `javascript`, `css`, `json`
  - `search` *(string, optional)*: Filter by matched text or URL
- **Response:**
```json
{
  "success": true,
  "data": {
    "findings": [
      {
        "id": 14,
        "scan_id": 1,
        "page_url": "https://caramelly.in/products/sample",
        "resource_url": null,
        "resource_type": "html",
        "matched_text": "Lorem ipsum dolor sit amet",
        "surrounding_context": "...Welcome to our store. Lorem ipsum dolor sit amet, consectetur...",
        "html_element": "p",
        "css_selector": "div.description > p",
        "section_name": "Main Content",
        "is_visible": 1,
        "severity": "high",
        "confidence": "high",
        "finding_fingerprint": "a3f5c9e..."
      }
    ],
    "pagination": { ... }
  }
}
```

---

### 10. Compare Scans Across Time
- **Method:** `GET`
- **Action:** `action=compare`
- **Parameters:**
  - `previous_scan_id` *(int, required)*
  - `current_scan_id` *(int, required)*
- **Response:**
```json
{
  "success": true,
  "data": {
    "comparison": {
      "previous_scan_id": 1,
      "current_scan_id": 2,
      "new_count": 0,
      "persisting_count": 4,
      "resolved_count": 27,
      "unverified_count": 0,
      "new": [],
      "persisting": [...],
      "resolved": [...],
      "unverified": []
    }
  }
}
```

---

### 11. Export Results
- **Method:** `GET`
- **Action:** `action=export`
- **Parameters:**
  - `project_id` *(int, required)*
  - `format` *(string, optional)*: `'csv'` or `'json'` (default: `'csv'`)
- **Headers Returned:**
  - `Content-Type: text/csv` or `Content-Type: application/json`
  - `Content-Disposition: attachment; filename="ProjectName_results.csv"`
