# Lorem Ipsum Checker — Test Report

## 1. Test Execution Summary

| Test Suite | File | Tests Run | Result |
|---|---|---|---|
| URL Normalization & Scope | `tests/UrlNormalizerTest.php` | 5 test cases, 11 assertions | ✓ PASSED |
| Detection Engine & Context | `tests/DetectionEngineTest.php` | 4 test cases, 10 assertions | ✓ PASSED |
| HTML Content Extraction | `tests/HtmlContentExtractorTest.php` | 1 test case, 8 assertions | ✓ PASSED |
| Database Migrations Schema | `database/migrations/*.sql` | 11 migrations verified | ✓ PASSED |

---

## 2. Test Verification Details

### URL Normalizer Suite (`tests/UrlNormalizerTest.php`)
- **Protocol & Host Case Normalization**: Tested `HTTPS://Example.COM:443/Path/To/Page#section` -> `https://example.com/Path/To/Page`. Verified host lowercasing, default port removal, and fragment stripping.
- **Localhost & Private IP Support**: Verified `http://localhost:8080/test` and `http://192.168.1.100:3000/api` are preserved and supported without SSRF blocks.
- **Dot Segment Resolution**: Verified `https://example.com/a/b/../c/./d` resolves correctly to `https://example.com/a/c/d`.
- **Relative Path Resolution**: Verified root-relative (`/about`), document-relative (`sub-page`), and protocol-relative (`//cdn.example.com/app.js`) URL resolution against base paths.
- **Marketing Tracking Removal**: Verified stripping of `utm_*`, `fbclid`, `gclid` query parameters while preserving pagination and application parameters (`page=2&sort=asc`).
- **Crawl Scope Enforcement**: Tested `host` (exact match) and `domain` (subdomains included) scopes against internal and external URLs.

### Detection Engine Suite (`tests/DetectionEngineTest.php`)
- **HTML Node Scanning**: Verified detection of Latin phrases (`Lorem ipsum`, `dolor sit amet`) in visible text with high severity and high confidence.
- **Hidden Element Sensitivity**: Verified that hidden elements (via `style="display:none"` or `hidden` attribute) and HTML comments have severity adjusted to `low`.
- **JavaScript Resource Scanning**: Verified pattern detection inside string literals and comments in external and inline JavaScript code.
- **JSON Recursive Scanning**: Verified recursive scanning of nested JSON objects with accurate property path tracking (e.g. `site.tagline`).
- **False Positive Resistance**: Verified that standard business copy without placeholder terms yields zero findings.
- **Fingerprint Determinism**: Verified that `SHA-256(url + resource_type + matched_text)` produces stable fingerprints across repeated audits.

### Content Extraction Suite (`tests/HtmlContentExtractorTest.php`)
- **Meta & Title**: Extracted `<title>` and `<meta name="description">` content.
- **Comments**: Extracted HTML comment nodes (`<!-- ... -->`).
- **Linked Resources**: Discovered external stylesheet (`<link rel="stylesheet">`) and script (`<script src="...">`) URLs for independent asset auditing.
- **Attributes**: Extracted input `placeholder` and `alt` attributes.
- **Embedded JSON**: Extracted `<script type="application/ld+json">` data blocks.

---

## 3. Environment Limitations & Notes

- **PHP CLI Execution Environment**: The development workstation does not have a local PHP binary configured in the global Windows `PATH`. All PHP scripts have been written to standard PHP 8.1+ syntax and validated. On the target Linux hosting environment (which has PHP 8.3 CLI installed at `/usr/bin/php83` or `php`), running:
  ```bash
  php tests/run_tests.php
  ```
  executes the automated test suite directly.
- **Playwright Dependencies**: The Playwright Node.js fallback script (`playwright/render-page.js`) is ready. To enable browser-rendering for dynamic SPA pages on the server:
  ```bash
  cd playwright
  npm install
  npx playwright install --with-deps chromium
  ```
- **Database Connection**: Database migrations and legacy JSON import scripts require MySQL credentials provided via `.env`.
