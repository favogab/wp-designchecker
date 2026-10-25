# WP DesignChecker — Installation Guide

## Overview

WP DesignChecker consists of a WordPress administration plugin and a separately running Python FastAPI screenshot service. This version is designed for **local Windows development**, not public internet deployment.

## Prerequisites

- Python **3.13** (tested with 3.13.15)
- Git and a local WordPress installation (for example, Local)
- Windows PowerShell
- Administrator access to the local WordPress installation

## 1. Clone and set up Python

```powershell
git clone https://github.com/favogab/wp-designchecker.git
cd wp-designchecker
python -m venv .venv
.\.venv\Scripts\Activate.ps1
cd checker-engine
python -m pip install -r requirements.txt
python -m playwright install chromium
```

If PowerShell prevents activation, consult its execution-policy documentation rather than disabling system-wide protections.

## 2. Configure the Python API key

Create a file named `.env` in the **repository root** (next to `README.md`, not inside `checker-engine`). The backend loads this location explicitly.

Generate a secret with:

```powershell
python -c "import secrets; print(secrets.token_urlsafe(32))"
```

Add the generated value to `.env`:

```dotenv
DESIGNCHECK_API_KEY=replace-with-your-generated-secret
```

The exact name is **`DESIGNCHECK_API_KEY`**. The `.env` file is ignored by Git. Do not commit it or paste the actual secret into issues or screenshots.

## 3. Configure the WordPress test page

The screenshot target is currently hardcoded in `checker-engine/capture.py`:

```python
PAGE_URL = "http://wp-designchecker.local/designcheck-test-page/"
```

Start your local WordPress site and ensure this URL exists, or change `PAGE_URL` to the URL of your local test page. The target must be reachable from the machine running Playwright.

## 4. Install the WordPress plugin

Copy or link the `checker-plugin` directory into the WordPress site's `wp-content/plugins/` directory. In WordPress Admin, go to **Plugins** and activate **WP DesignChecker**.

In the site's local `wp-config.php`, before the WordPress bootstrap line, configure the **same secret**:

```php
define('WP_DESIGNCHECKER_API_KEY', 'replace-with-your-generated-secret');
```

The WordPress PHP client currently connects to `http://127.0.0.1:8000`. This works only when WordPress can reach the Python service at that loopback address; containers, remote hosting, and separate machines need additional configuration not covered by this release.

## 5. Start the backend

From `checker-engine` with the virtual environment active:

```powershell
python -m uvicorn main:app --host 127.0.0.1 --port 8000
```

Leave this terminal running. The service exposes interactive API documentation at `http://127.0.0.1:8000/docs`. Protected endpoints require the `X-API-Key` header.

## 6. Capture initial approved baselines

In a separate activated terminal, from `checker-engine`:

```powershell
python capture.py --mode baseline
```

The command creates screenshots for desktop (1440 × 900 viewport), tablet (768 × 1024), and mobile (390 × 844). Full-page screenshot heights can exceed viewport heights. Existing baselines are **skipped**, not overwritten.

## 7. Run a scan

Open the **WP DesignChecker** page in WordPress Admin and start a scan. The backend captures current screenshots, compares them with baselines, saves a report and scan history, and returns PASS, FAIL, or ERROR for each viewport. The dashboard also provides current and historical screenshot review.

## 8. Run automated tests

From `checker-engine`:

```powershell
python -m pytest -q
```

The reviewed project contains **22 tests** across API, comparison, and database modules. Database tests use isolated temporary files.

## Troubleshooting

- **Backend unavailable:** Confirm Uvicorn is running and WordPress can reach `127.0.0.1:8000`.
- **401 Unauthorized:** Verify that `DESIGNCHECK_API_KEY` in the root `.env` matches `WP_DESIGNCHECKER_API_KEY` in the local WordPress configuration. Restart the backend after changing `.env`.
- **Capture failure:** Confirm the configured WordPress test-page URL loads and run `python -m playwright install chromium`.
- **Baseline already exists:** This is expected; the capture script protects approved screenshots. Back up and intentionally remove the relevant old baseline only when approving a new design.
- **FAIL:** Review the generated difference image and percentage. Dynamic content or page-height changes can trigger visual differences.

## Security and scope

The backend binds to loopback and uses HTTP. **Do not expose this configuration directly to the internet.** This release is a local development MVP; see [Security](SECURITY.md) and [Known Limitations](KNOWN_LIMITATIONS.md).
