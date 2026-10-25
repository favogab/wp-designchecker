# WP DesignChecker — Known Limitations

## 1. Overview

WP DesignChecker is a WordPress visual regression testing application built with a Python FastAPI backend, Playwright screenshot automation, Pillow image comparison, and SQLite scan history.

The initial version focuses on providing a working local visual regression testing workflow, including screenshot capture, baseline comparison, visual difference reporting, and historical scan review.

This document describes the current limitations, their practical implications, and potential improvements for future releases.

These limitations are disclosed intentionally to make the project's current capabilities and release scope clear.

## 2. Local Development Architecture

**Current limitation:** WP DesignChecker is designed primarily for a local development environment.

The WordPress plugin communicates with a separately running Python FastAPI service, currently configured to use:

```text
http://127.0.0.1:8000
```

The Python service must be running for scan operations and backend-dependent dashboard functionality to work.

**Impact:** The application is not currently a plug-and-play WordPress plugin suitable for arbitrary hosting environments.

**Future improvement:** Introduce configurable service endpoints, deployment instructions, and secure remote service communication.

## 3. Separately Managed Python Service

**Current limitation:** The FastAPI backend must be started independently of WordPress.

The initial version does not provide automatic installation or lifecycle management for the Python service.

**Impact:** Users must configure Python dependencies, install Playwright's Chromium browser, and start the service manually.

**Future improvement:** Provide a simplified installation workflow and automated service management.

## 4. Single Configured Target Page

**Current limitation:** Screenshot capture uses a configured target page URL in the Python capture module.

The application does not currently provide a dashboard interface for selecting multiple website pages.

**Impact:** Testing another page requires changing the capture configuration.

**Future improvement:** Support configurable target URLs, multiple pages, and reusable scan configurations.

## 5. Fixed Responsive Viewports

**Current limitation:** The application captures screenshots at three predefined viewport sizes.

| Viewport | Width | Height setting |
|---|---:|---:|
| Desktop | 1440 px | 900 px |
| Tablet | 768 px | 1024 px |
| Mobile | 390 px | 844 px |

Screenshots use full-page capture, so the final screenshot height may exceed the configured viewport height.

**Impact:** Users cannot currently define additional device sizes through the dashboard.

**Future improvement:** Add custom viewport profiles and device presets.

## 6. Chromium-Based Screenshot Capture

**Current limitation:** Screenshot capture uses Playwright with Chromium.

The application does not currently perform equivalent comparisons using Firefox or WebKit.

**Impact:** Browser-specific visual differences outside Chromium are not covered.

**Future improvement:** Introduce cross-browser screenshot testing and comparison.

## 7. Pixel-Based Image Comparison

**Current limitation:** The comparison engine uses Pillow to compare screenshot pixels.

The current visual difference threshold is 5%.

Pixel comparison can detect changes caused by layout differences, typography, images, animations, and other rendered content.

However, it does not understand whether a visual difference is intentional or harmful.

**Impact:** Dynamic content, font rendering differences, animations, and minor rendering variations may produce visual differences even when the website is functioning correctly.

**Future improvement:** Add configurable thresholds, ignored regions, animation handling, and optional perceptual or AI-assisted analysis.

## 8. Baseline Management

**Current limitation:** Approved baseline screenshots are stored as image files.

Existing baselines are protected from accidental replacement, but the initial version does not provide a complete dashboard-based baseline approval and version-management system.

**Impact:** Approving a new design may require a controlled manual baseline update.

**Future improvement:** Add baseline approval workflows, version history, and rollback functionality.

## 9. Historical Baseline Preservation

**Current limitation:** WP DesignChecker archives current screenshots and difference images for historical scans.

It does not currently archive a separate copy of the approved baseline used for every scan.

**Impact:** If the approved baseline changes later, the original baseline used during an older scan may no longer be available for direct historical comparison.

**Future improvement:** Preserve a baseline snapshot or immutable baseline reference for each historical scan.

## 10. Scan History Storage

**Current limitation:** Scan history is stored in a local SQLite database.

SQLite provides lightweight persistence suitable for the initial development environment.

However, the application does not currently provide database administration, retention policies, or advanced multi-user data management.

**Impact:** Long-term storage, backup, and cleanup require additional operational planning.

**Future improvement:** Add configurable retention policies, database backups, and support for more advanced database deployments where necessary.

## 11. Scan Execution and Concurrency

**Current limitation:** The initial release has not been comprehensively tested for high-volume or concurrent scanning workloads.

**Impact:** Running many scans simultaneously may introduce resource contention, overlapping operations, or unexpected behavior.

**Future improvement:** Introduce a managed scan queue, concurrency controls, and load testing.

## 12. Screenshot Storage Growth

**Current limitation:** Historical screenshots are stored on the local filesystem.

The application does not currently implement a documented automatic cleanup policy for archived images.

**Impact:** Disk usage may increase as additional scans are performed.

**Future improvement:** Add configurable screenshot retention periods, storage limits, and archive cleanup tools.

## 13. Dynamic Website Content

**Current limitation:** The application does not currently provide configurable exclusion regions for dynamic page elements.

Examples include:

- Rot