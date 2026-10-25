# WP DesignChecker

**Visual Regression Testing & Quality Assurance for WordPress**

WP DesignChecker is a WordPress-integrated visual regression testing application built with PHP, Python, FastAPI, Playwright, and Pillow.

It helps website designers, developers, and administrators identify unexpected visual changes following theme updates, plugin updates, CSS modifications, and other website changes.

The application captures responsive screenshots, compares them against approved visual baselines, highlights differences, and provides a WordPress administration dashboard for reviewing results and scan history.

**Current version:** 0.1.0 — Local development MVP

**Status:** Functional MVP | 22 automated tests passing

## The Problem

WordPress websites can experience unintended visual changes during routine maintenance.

A theme update might alter spacing. A plugin update might affect a page component. A CSS modification might unexpectedly change the mobile layout.

These issues are not always obvious until someone manually checks the website—or a visitor reports the problem.

WP DesignChecker provides a repeatable visual testing workflow to help identify these changes earlier.

## How It Works

```text
WordPress Admin Dashboard
          |
          v
     FastAPI Backend
          |
          v
   Playwright Chromium
          |
          v
 Responsive Screenshots
          |
          v
    Pillow Comparison
          |
          +----> Approved Baselines
          |
          v
  PASS / FAIL / ERROR
          |
          v
 Screenshot Review + Scan History
```

The WordPress plugin communicates with a locally running Python service.

Playwright captures screenshots of a configured test page at desktop, tablet, and mobile viewport sizes.

Pillow compares the current screenshots against approved baselines and generates visual difference images.

Results are stored in SQLite and displayed through the WordPress dashboard.

## Implemented Features

### Responsive Screenshot Capture

Automated full-page screenshot capture using Playwright and Chromium.

Supported viewport configurations:

| Viewport | Width | Height setting |
|---|---:|---:|
| Desktop | 1440 px | 900 px |
| Tablet | 768 px | 1024 px |
| Mobile | 390 px | 844 px |

### Visual Regression Detection

- Comparison against approved baseline screenshots.
- Pixel-based visual difference calculation.
- Configured 5% difference threshold.
- PASS, FAIL, and ERROR results.
- Generated visual difference images.
- Handling of screenshot dimension changes.

### WordPress Administration Dashboard

- Start visual scans from WordPress.
- View scan execution status.
- Review comparison results and difference percentages.
- Switch between desktop, tablet, and mobile.
- View baseline, current, and difference screenshots.
- Review historical scans and archived screenshots.
- Switch historical viewports without reloading the page.

### Scan History and Recovery

- Persistent scan records using SQLite.
- Scan identifiers and timestamps.
- Archived current screenshots and difference images.
- Recovery of interrupted scan records after service restart.

### Authentication and Access Control

- API-key authentication between WordPress and FastAPI.
- WordPress capability checks.
- WordPress nonce verification.
- Screenshot parameter validation.
- Protected screenshot retrieval.

## Technology Stack

| Technology | Purpose |
|---|---|
| PHP | WordPress plugin development and API integration |
| Python | Backend processing and automation |
| FastAPI | Authenticated scanning API |
| Playwright | Browser automation and screenshot capture |
| Chromium | Browser rendering |
| Pillow | Screenshot comparison and visual differences |
| SQLite | Scan history and result persistence |
| JavaScript | Interactive WordPress dashboard |
| CSS | Dashboard styling |
| pytest | Automated Python testing |

## Project Structure

```text
wp-designchecker/
├── checker-plugin/
│   ├── admin/
│   │   └── dashboard.php
│   ├── assets/
│   │   ├── css/
│   │   │   └── dashboard.css
│   │   └── js/
│   │       └── dashboard.js
│   ├── includes/
│   │   └── class-api-client.php
│   └── wp-designchecker.php
│
├── checker-engine/
│   ├── tests/
│   │   ├── test_api.py
│   │   ├── test_compare.py
│   │   └── test_database.py
│   ├── capture.py
│   ├── compare.py
│   ├── database.py
│   ├── main.py
│   └── requirements.txt
│
├── docs/
│   ├── ARCHITECTURE.md
│   ├── INSTALLATION.md
│   ├── TESTING.md
│   ├── SECURITY.md
│   └── KNOWN_LIMITATIONS.md
│
├── .gitignore
├── LICENSE
└── README.md
```

Runtime-generated screenshots, scan reports, the local SQLite database, virtual environments, and secret configuration files are omitted from this simplified structure.

## Getting Started

WP DesignChecker currently runs in a local development environment.

### Prerequisites

- Python 3.13 (tested with 3.13.15).
- A local WordPress installation.
- Git.
- Playwright Chromium.
- Windows PowerShell for the commands below.

### 1. Clone the Repository

```powershell
git clone https://github.com/favogab/wp-designchecker
cd wp-designchecker
```

### 2. Create and Activate a Virtual Environment

```powershell
python -m venv .venv
.\.venv\Scripts\Activate.ps1
```

### 3. Install Python Dependencies

```powershell
cd checker-engine
python -m pip install -r requirements.txt
python -m playwright install chromium
```

### 4. Configure the Application

Configure the FastAPI API key and WordPress plugin authentication using matching secret values.

The Python backend also requires a configured target WordPress page.

See the [Installation Guide](docs/INSTALLATION.md) for the full configuration procedure.

### 5. Start the Python Backend

From `checker-engine`:

```powershell
python -m uvicorn main:app --host 127.0.0.1 --port 8000
```

The local API is available at:

`http://127.0.0.1:8000`

### 6. Install the WordPress Plugin

Place the `checker-plugin` directory inside the WordPress installation's `wp-content/plugins/` directory.

Activate the plugin through **WordPress Admin → Plugins**.

Configure the matching API key in WordPress.

### 7. Run a Visual Scan

Open the WP DesignChecker administration dashboard.

Start a scan and review the resulting comparison status and screenshots.

Approved baseline screenshots must be initialized before meaningful regression comparisons can be performed.

## Testing

WP DesignChecker has completed eight manual functional testing stages and 22 automated Python tests.

| Test area | Passing tests |
|---|---:|
| Image comparison | 6 |
| FastAPI | 8 |
| SQLite persistence | 8 |
| **Total** | **22** |

Run the automated suite from `checker-engine`:

```powershell
python -m pytest -q
```

Latest verified result: **22 passed**.

Manual testing has also covered visual regression detection, baseline verification, screenshot review, scan history, service recovery, and access controls.

See [Testing and Quality Assurance](docs/TESTING.md) for details.

## Documentation

| Document | Description |
|---|---|
| [Architecture](docs/ARCHITECTURE.md) | System components, responsibilities, and data flow |
| [Installation](docs/INSTALLATION.md) | Local setup and configuration |
| [Testing](docs/TESTING.md) | Functional, security, and automated testing |
| [Security](docs/SECURITY.md) | Authentication, authorization, and security limitations |
| [Known Limitations](docs/KNOWN_LIMITATIONS.md) | Current constraints and potential improvements |

## Current Limitations

WP DesignChecker is a functional local development MVP, not a production-ready hosted service.

Current limitations include:

- A separately running Python service is required.
- Screenshot capture targets one configured page.
- Three predefined viewport sizes are supported.
- Screenshot capture currently uses Chromium.
- Comparison is pixel-based and may detect harmless rendering differences.
- Baseline approval is not yet managed through a complete dashboard workflow.
- Historical baseline snapshots are not archived.
- Broad hosting compatibility and concurrent scan behavior have not been established.

For further details, see [Known Limitations](docs/KNOWN_LIMITATIONS.md).

## Future Development

Potential future improvements include multi-page scanning, configurable viewport profiles, dashboard-based baseline approval, dynamic content masking, scheduled scans, CI integration, and optional AI-assisted visual analysis.

These features are not included in the current release.

## Development Status

**Version:** 0.1.0 — Local development MVP

**Stage:** Functional local development MVP.

The screenshot capture engine, comparison engine, FastAPI service, SQLite persistence layer, WordPress dashboard, and historical screenshot review functionality have been implemented and tested.

The initial release is intended for local development, technical demonstration, and further engineering improvement.

## Author

Developed by **Favogab**.