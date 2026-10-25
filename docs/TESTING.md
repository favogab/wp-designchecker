# WP DesignChecker — Testing and Quality Assurance

## 1. Overview

WP DesignChecker uses manual functional testing, security checks, and automated Python tests to verify its visual regression testing workflow.

Testing focuses on five areas:

- Screenshot capture and visual comparison.
- WordPress dashboard functionality.
- Scan history and archived screenshots.
- API authentication and access control.
- Application reliability and data persistence.

This document records the testing completed during development of version 0.1.0 and identifies areas requiring further verification.

## 2. Testing Environment

The application was tested in a local Windows development environment.

| Component | Test environment |
|---|---|
| Operating system | Windows |
| WordPress | Local development installation |
| Python backend | FastAPI |
| Browser automation | Playwright with Chromium |
| Image comparison | Pillow |
| Database | SQLite |
| Automated test framework | pytest |
| Test website | `wp-designchecker.local` |
| API address | `http://127.0.0.1:8000` |

The WordPress test page was used to introduce intentional design changes and verify whether the comparison engine detected them.

## 3. Functional Testing

Eight functional testing stages were completed.

| Test ID | Test scenario | Result |
|---|---|---|
| FT-01 | Run an authenticated scan and detect an intentional visual change | PASS |
| FT-02 | Approve a new baseline and verify an unchanged design | PASS |
| FT-03 | Handle screenshot dimension differences and verify comparison behavior | PASS |
| FT-04 | Verify screenshot dimensions and responsive viewport captures | PASS |
| FT-05 | Verify baseline, current, and difference screenshot previews | PASS |
| FT-06 | Verify historical scan records and screenshot preservation | PASS |
| FT-07 | Verify historical viewport switching and side-by-side review | PASS |
| FT-08 | Verify recovery after the Python service becomes unavailable | PASS |

### FT-01 — Visual Regression Detection

**Objective:** Verify that WP DesignChecker detects intentional design changes.

The test page was modified after an approved baseline had been captured.

A new scan was executed from the WordPress dashboard.

**Observed results:**

- FastAPI was accessible.
- The scan completed.
- The dashboard updated automatically.
- The comparison engine reported FAIL.

**Outcome:** PASS — the intentional visual regression was detected.

### FT-02 — Approved Baseline Verification

**Objective:** Verify that the application can produce a PASS result when the current design matches the approved baseline.

The previous baseline screenshots were backed up before the new design was approved.

New baseline images were captured and compared against the current design.

**Observed results:**

- The current design was approved.
- Baseline screenshots were preserved through a backup.
- Matching screenshots produced PASS results.
- Desktop, tablet, and mobile reported 0.00% difference during the successful verification.

**Outcome:** PASS.

### FT-03 — Screenshot Dimension Handling

**Objective:** Verify the application's behavior when screenshot dimensions differ.

During testing, full-page screenshot heights changed while the configured viewport widths remained consistent.

The comparison engine initially returned ERROR for dimension mismatches.

The comparison logic was subsequently improved to handle dimension changes as visual comparison results rather than automatically treating every size difference as an image-processing error.

**Observed results:**

- Dimension differences were identified.
- The comparison report displayed meaningful diagnostic information.
- The updated comparison logic could produce FAIL results for significant differences.
- A later unchanged-design scan returned PASS.

**Outcome:** PASS after corrective development and verification.

### FT-04 — Responsive Screenshot Verification

**Objective:** Confirm that screenshots are captured and displayed correctly for all supported viewports.

**Observed results:**

- Desktop screenshot width was correct.
- Tablet screenshot width was correct.
- Mobile screenshot width was correct.
- Baseline and current image dimensions matched during the test.
- The WordPress screenshot viewer displayed all three viewports.

**Outcome:** PASS.

### FT-05 — Screenshot Review Interface

**Objective:** Verify that administrators can review different screenshot types without unwanted page reloads.

**Observed results:**

- Approved baseline displayed correctly.
- Current design displayed correctly.
- Visual difference displayed correctly.
- Switching screenshot types worked.
- No unwanted reloads or stale images were observed.

**Outcome:** PASS.

### FT-06 — Historical Scan Reliability

**Objective:** Verify that new scans do not overwrite previous scan records or archived screenshots.

**Observed results:**

- The original scan ID and results were recorded.
- A new scan completed and appeared in history.
- The previous scan ID, result, and percentages remained unchanged.
- Archived screenshots from the previous scan remained accessible.

**Outcome:** PASS.

### FT-07 — Historical Viewport Switching

**Objective:** Verify the historical screenshot review interface.

**Observed results:**

- Desktop archived screenshots loaded.
- Tablet archived screenshots loaded.
- Mobile archived screenshots loaded.
- The selected scan ID remained unchanged.
- Viewport switching did not reload the entire WordPress page.
- The side-by-side screenshot layout remained usable.

**Outcome:** PASS.

### FT-08 — Service Recovery

**Objective:** Verify that the application recovers after the Python scanning service becomes unavailable.

**Observed results:**

- The WordPress dashboard displayed an understandable service-unavailable message.
- FastAPI Swagger documentation became accessible after restarting the service.
- Previous scan history remained available.
- Archived screenshots were retrieved successfully.
- A new recovery scan completed with PASS.

**Outcome:** PASS.

## 4. Security Testing

Security checks were performed against the FastAPI service and WordPress administration interface.

| Security area | Verified behavior |
|---|---|
| Missing API key | FastAPI rejected the request with HTTP 401 |
| Invalid API key | FastAPI rejected the request with HTTP 401 |
| Invalid key with malformed scan ID | Authentication was enforced before the request could access protected scan data |
| Invalid scan ID with valid authentication | The endpoint returned HTTP 404 |
| Missing WordPress nonce | Request denied |
| Invalid WordPress nonce | Request denied |
| Invalid historical screenshot nonce | Request denied |
| Unauthenticated dashboard access | Access denied |
| Unauthenticated screenshot access | Access denied |
| API secret tracking | Local environment file was ignored by Git |

The API client uses an `X-API-Key` header for communication between WordPress and FastAPI.

WordPress uses permission checks and nonce verification to protect administration actions.

These tests provide evidence of specific security controls. They do not constitute a complete penetration test or independent security audit.

## 5. Automated Testing

The Python backend uses pytest.

The automated suite contains 22 tests across three areas.

| Test module | Tests passed |
|---|---:|
| `tests/test_compare.py` | 6 |
| FastAPI test module | 8 |
| Database test module | 8 |
| **Total** | **22** |

### 5.1 Image Comparison Tests

The comparison tests cover:

1. Identical images producing PASS.
2. Significant visual changes producing FAIL.
3. Missing screenshots producing ERROR.
4. Different image dimensions being handled as visual changes.
5. Corrupted images producing controlled errors.
6. JSON report generation.

Tests use temporary screenshot directories rather than modifying approved project baselines.

### 5.2 FastAPI Tests

Eight automated API tests passed.

These tests provide regression coverage for the Python API. The individual test names and assertions should be consulted in the FastAPI test module for the exact endpoint and authentication scenarios covered.

### 5.3 SQLite Database Tests

Eight automated database tests passed.

The tests cover:

1. Database table initialization.
2. Saving and retrieving scan records.
3. JSON result serialization and deserialization.
4. Updating existing scans without creating duplicates.
5. Historical scan ordering and record limits.
6. Handling unknown scan identifiers.
7. Persistence across database connections.
8. Recovery of interrupted scans.

Each database test uses an isolated temporary SQLite database.

The application's real `designchecker.db` file is not modified by these tests.

## 6. Running Automated Tests

Open a terminal at the project root.

Activate the virtual environment:

```powershell
.\.venv\Scripts\Activate.ps1
```

Navigate to the Python backend:

```powershell
cd checker-engine
```

Run the complete test suite:

```powershell
python -m pytest -q
```

### Latest recorded result

```text
22 passed
```

All 22 tests passed following the final type-annotation cleanup in the database test helper.

A dependency deprecation warning was observed during an earlier run. That warning did not cause test failures and should be reviewed during future dependency maintenance.

## 7. Test Isolation and Data Safety

Automated comparison tests use temporary screenshots.

Database tests redirect SQLite operations to temporary database files.

This prevents test execution from overwriting production-like scan history or approved baseline screenshots.

Manual tests involving baseline approval were performed with backups of the previous approved screenshots.

## 8. Known Testing Limitations

The current testing process does not establish compatibility with every WordPress version, PHP version, browser engine, operating system, or hosting environment.

Additional future testing should include:

- Cross-browser screenshot consistency.
- Multiple WordPress themes.
- Different page structures and content lengths.
- Larger screenshot files.
- Concurrent scan requests.
- Extended service uptime.
- Production deployment configurations.
- Broader security and performance testing.

## 9. Release Testing Conclusion

WP DesignChecker successfully completed eight functional testing stages and 22 automated Python tests.

The tested features include visual regression detection, approved baseline comparison, responsive screenshots, historical scan preservation, screenshot review, service recovery, API authentication, and database persistence.

The application is ready to proceed to final release-readiness checks for its local development release.

Successful testing does not imply that the application is production-hardened or compatible with every WordPress environment.