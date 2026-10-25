# WP DesignChecker — System Architecture

## 1. Overview

WP DesignChecker is a WordPress visual regression testing application designed to identify unexpected changes in website appearance.

It combines a WordPress administration plugin with a Python-based screenshot capture and comparison service.

The application captures screenshots at three responsive viewport sizes, compares them against approved baseline images, and presents the results through a WordPress dashboard.

## 2. Technology Stack

| Component | Technology | Responsibility |
|---|---|---|
| Administration interface | WordPress, PHP, JavaScript, CSS | Display scan controls, results, screenshots, and history |
| Backend API | Python, FastAPI | Handle authenticated requests and manage scans |
| Screenshot capture | Playwright, Chromium | Capture website screenshots |
| Image comparison | Pillow | Compare current screenshots with approved baselines |
| Data persistence | SQLite | Store scan records and results |
| Automated testing | pytest | Validate comparison, API, and database behavior |

## 3. System Architecture

```text
WordPress Administration Dashboard
               |
               | Authenticated HTTP requests
               v
       WordPress PHP API Client
               |
               | X-API-Key header
               v
          FastAPI Service
               |
       +-------+-------+
       |               |
       v               v
   Scan Engine     SQLite Database
       |               |
       v               v
    Playwright      Scan History
       |
       v
 Current Screenshots
       |
       v
 Pillow Comparison Engine
       |
       +----> Approved Baselines
       |
       v
 Comparison Results and Diff Images
       |
       v
 WordPress Dashboard
```

## 4. WordPress Plugin

The WordPress plugin provides the user-facing administration interface.

Its main responsibilities include:

- Starting visual regression scans.
- Displaying scan execution status.
- Presenting PASS, FAIL, and ERROR results.
- Showing percentage differences for desktop, tablet, and mobile.
- Displaying baseline, current, and difference screenshots.
- Retrieving historical scan records.
- Reviewing archived screenshots for individual scans.

The plugin communicates with the Python service through a dedicated PHP API client.

## 5. Python Backend

The Python backend provides the scanning and comparison functionality.

### Screenshot Capture

Playwright launches Chromium and captures full-page screenshots using three viewport widths:

| Viewport | Width | Height setting |
|---|---|---|
| Desktop | 1440 px | 900 px |
| Tablet | 768 px | 1024 px |
| Mobile | 390 px | 844 px |

Because screenshots use full-page capture, the resulting image height may exceed the configured viewport height.

### Image Comparison

Pillow compares current screenshots against approved baseline images.

The comparison engine calculates the percentage of pixels that differ and generates visual difference images.

The current comparison threshold is 5%.

### Scan Execution

FastAPI accepts authenticated scan requests and manages scan execution.

Scan records include execution states, results, timestamps, and error information.

## 6. Database Architecture

WP DesignChecker uses SQLite to persist scan history.

The `scans` table contains:

| Field | Purpose |
|---|---|
| `scan_id` | Unique scan identifier |
| `state` | Execution state |
| `result` | JSON-serialized comparison report |
| `error` | Error information |
| `created_at` | Scan creation timestamp |
| `finished_at` | Scan completion timestamp |

The database allows historical scan records to remain available after the Python service restarts.

Interrupted pending or running scans are marked as failed during recovery.

## 7. Screenshot Storage

The application maintains separate screenshot directories for approved baselines, current captures, and difference images.

It also maintains scan-specific historical screenshot directories.

This allows administrators to review screenshots from previous scans without replacing those archived images when another scan runs.

Historical approved baseline snapshots are not currently archived.

## 8. Authentication and Access Control

Communication with FastAPI uses an API key supplied through the `X-API-Key` request header.

The WordPress administration interface uses capability checks and nonce verification to protect privileged actions.

The application is currently designed around a locally running Python service.

## 9. Automated Testing

The Python application uses pytest for automated verification.

The current suite includes:

- 6 image comparison tests.
- 8 FastAPI tests.
- 8 SQLite database tests.

**Total: 22 passing tests.**

Database and comparison tests use isolated temporary resources to avoid modifying real application records and approved screenshots.

## 10. Current Architecture Limitations

The initial version has several limitations:

- The Python scanning service must be started separately.
- The WordPress API client uses a local service address.
- Screenshot capture targets a configured test-page URL.
- Historical baseline images are not archived.
- The system is not yet packaged as a standalone hosted service.
- Broader cross-browser and production-environment testing remains future work.

These limitations are documented as part of the initial release scope rather than presented as completed capabilities.