
"""
WP DesignChecker - FastAPI Scanning Engine

Provides authenticated endpoints for screenshot capture,
visual regression comparison, scan management, persistent
scan history, and screenshot retrieval.
"""

import json
import logging
import os
import secrets
import threading

from contextlib import asynccontextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Literal
from uuid import uuid4

from dotenv import load_dotenv
from fastapi import BackgroundTasks, Depends, FastAPI, HTTPException
from fastapi.responses import FileResponse
from fastapi.security import APIKeyHeader
from starlette.concurrency import run_in_threadpool

from capture import capture_screenshots
from compare import generate_report

from database import (
    get_saved_scan,
    get_scan_history,
    initialize_database,
    recover_interrupted_scans,
    save_scan,
)


# ---------------------------------------------------------
# Configuration
# ---------------------------------------------------------

# Resolve paths relative to this file, regardless of
# the directory from which the application is started.
BASE_DIR = Path(__file__).resolve().parent
PROJECT_DIR = BASE_DIR.parent

REPORT_PATH = BASE_DIR / "reports" / "latest-report.json"
SCREENSHOT_DIR = BASE_DIR / "screenshots"

# Load the API key from the project's root .env file.
load_dotenv(PROJECT_DIR / ".env")

API_KEY = os.getenv("DESIGNCHECK_API_KEY")

# Read authentication credentials from the X-API-Key header.
api_key_header = APIKeyHeader(
    name="X-API-Key",
    auto_error=False,
)

# Configure application logging.
logger = logging.getLogger(__name__)

# Store active scan jobs in memory.
# Completed jobs are also persisted in SQLite.
scan_jobs = {}

# Protect shared job data from concurrent modifications.
jobs_lock = threading.Lock()

# Prevent multiple screenshot scans from running simultaneously.
scan_lock = threading.Lock()


# ---------------------------------------------------------
# Application Lifecycle
# ---------------------------------------------------------

@asynccontextmanager
async def lifespan(app: FastAPI):
    """
    Initialize application resources during startup.

    Ensures that:
    - An API key has been configured.
    - The SQLite database exists.
    - Interrupted scans are marked as failed.

    Args:
        app (FastAPI): The application instance.
    """

    # Refuse to start without API authentication.
    if not API_KEY:
        raise RuntimeError(
            "DESIGNCHECK_API_KEY is not configured."
        )

    # Create database tables when necessary.
    initialize_database()

    # Recover records left pending or running
    # after an unexpected service shutdown.
    recover_interrupted_scans()

    logger.info("WP DesignChecker initialized successfully.")

    yield

    logger.info("WP DesignChecker shutting down.")


# Create the FastAPI application.
app = FastAPI(
    title="WP DesignChecker API",
    description="Visual regression testing engine for WordPress.",
    version="0.1.0",
    lifespan=lifespan,
)


# ---------------------------------------------------------
# Authentication
# ---------------------------------------------------------

def authenticate(
    api_key: str | None = Depends(api_key_header)
):
    """
    Authenticate requests using the configured API key.

    Args:
        api_key (str | None): Key supplied in X-API-Key.

    Raises:
        HTTPException: When authentication fails.
    """

    # Compare credentials without ordinary string equality.
    if (
        not API_KEY
        or not api_key
        or not secrets.compare_digest(api_key, API_KEY)
    ):
        raise HTTPException(
            status_code=401,
            detail="Unauthorized",
        )


# ---------------------------------------------------------
# Screenshot Capture and Comparison
# ---------------------------------------------------------

def execute_scan():
    """
    Capture current screenshots and generate a comparison report.

    Approved baseline screenshots are never overwritten.

    Returns:
        str: Overall visual comparison status.
    """

    # Capture current desktop, tablet, and mobile screenshots.
    capture_screenshots("current")

    # Compare captured screenshots against approved baselines.
    return generate_report()


# ---------------------------------------------------------
# Background Scan Processing
# ---------------------------------------------------------

def process_scan(scan_id: str):
    """
    Execute a scan in the background and persist its progress.

    Scan states:
        pending   - Waiting to begin.
        running   - Screenshot capture is underway.
        completed - Capture and comparison finished.
        failed    - An error prevented completion.

    Args:
        scan_id (str): Unique identifier of the scan.
    """

    # Prevent concurrent scans from modifying shared
    # screenshot and report files.
    if not scan_lock.acquire(blocking=False):

        with jobs_lock:
            job = scan_jobs[scan_id]

            job["state"] = "failed"
            job["error"] = "Another scan is already running."
            job["finished_at"] = datetime.now(
                timezone.utc
            ).isoformat()

            # Persist the failed state.
            save_scan(dict(job))

        return

    try:
        # Mark the scan as running.
        with jobs_lock:
            job = scan_jobs[scan_id]

            job["state"] = "running"

            save_scan(dict(job))

        # Perform screenshot capture and comparison.
        result = execute_scan()

        # Save the completed scan and its result.
        with jobs_lock:
            job = scan_jobs[scan_id]

            job["state"] = "completed"
            job["result"] = result
            job["error"] = None
            job["finished_at"] = datetime.now(
                timezone.utc
            ).isoformat()

            save_scan(dict(job))

    except Exception:
        # Record failures without exposing internal
        # exception details to API consumers.
        with jobs_lock:
            job = scan_jobs[scan_id]

            job["state"] = "failed"
            job["error"] = (
                "Screenshot capture or comparison failed."
            )
            job["finished_at"] = datetime.now(
                timezone.utc
            ).isoformat()

            save_scan(dict(job))

        # Preserve the full traceback in server logs.
        logger.exception(
            "Scan job failed: %s",
            scan_id,
        )

    finally:
        # Always release the lock so future scans can run.
        scan_lock.release()


# ---------------------------------------------------------
# Health Check
# ---------------------------------------------------------

@app.get("/health")
def health():
    """
    Check whether the API service is running.

    Returns:
        dict: Service health information.
    """

    return {
        "status": "ok",
        "service": "WP DesignChecker",
    }


# ---------------------------------------------------------
# Manual Comparison Endpoint
# ---------------------------------------------------------

@app.post(
    "/api/compare",
    dependencies=[Depends(authenticate)],
)
async def compare():
    """
    Compare existing screenshots without capturing new ones.

    Returns:
        dict: Overall comparison result and confirmation.
    """

    # Execute blocking image comparison outside
    # FastAPI's asynchronous event loop.
    status = await run_in_threadpool(generate_report)

    return {
        "status": status,
        "message": "Comparison completed",
    }


# ---------------------------------------------------------
# Legacy Synchronous Scan Endpoint
# ---------------------------------------------------------

@app.post(
    "/api/scan",
    dependencies=[Depends(authenticate)],
)
async def scan():
    """
    Run a complete scan and wait for its result.

    This endpoint is retained for compatibility with
    earlier development tests.

    Returns:
        dict: Final comparison status.
    """

    status = await run_in_threadpool(execute_scan)

    return {
        "status": status,
        "message": "Scan completed",
    }


# ---------------------------------------------------------
# Create Background Scan
# ---------------------------------------------------------

@app.post(
    "/api/scans",
    status_code=202,
    dependencies=[Depends(authenticate)],
)
def create_scan(background_tasks: BackgroundTasks):
    """
    Create a new background scan job.

    Generates a unique scan ID, persists the pending job,
    and schedules screenshot capture.

    Args:
        background_tasks (BackgroundTasks):
            FastAPI background task manager.

    Returns:
        dict: New scan identifier and initial state.

    Raises:
        HTTPException: When another scan is already active.
    """

    with jobs_lock:

        # Prevent overlapping background scans.
        if any(
            job["state"] in ("pending", "running")
            for job in scan_jobs.values()
        ):
            raise HTTPException(
                status_code=409,
                detail="A scan is already in progress.",
            )

        # Generate a unique identifier.
        scan_id = str(uuid4())

        # Create the initial scan record.
        scan_jobs[scan_id] = {
            "scan_id": scan_id,
            "state": "pending",
            "result": None,
            "error": None,
            "created_at": datetime.now(
                timezone.utc
            ).isoformat(),
            "finished_at": None,
        }

        # Save the job before starting background processing.
        save_scan(dict(scan_jobs[scan_id]))

    # Schedule the scan after the API response is sent.
    background_tasks.add_task(
        process_scan,
        scan_id,
    )

    return {
        "scan_id": scan_id,
        "state": "pending",
        "message": "Scan queued successfully",
    }


# ---------------------------------------------------------
# List Scan History
# ---------------------------------------------------------

@app.get(
    "/api/scans",
    dependencies=[Depends(authenticate)],
)
def list_scans(limit: int = 20):
    """
    Retrieve recent scan records from SQLite.

    Args:
        limit (int): Maximum number of records to retrieve.
            Accepted range: 1 to 100.

    Returns:
        dict: Record count and scan history.

    Raises:
        HTTPException: If the requested limit is invalid.
    """

    # Restrict the maximum number of returned records.
    if not 1 <= limit <= 100:
        raise HTTPException(
            status_code=400,
            detail="Limit must be between 1 and 100.",
        )

    # Retrieve saved scan records from SQLite.
    scans = get_scan_history(limit=limit)

    return {
        "count": len(scans),
        "scans": scans,
    }


# ---------------------------------------------------------
# Retrieve Individual Scan
# ---------------------------------------------------------

@app.get(
    "/api/scans/{scan_id}",
    dependencies=[Depends(authenticate)],
)
def get_scan(scan_id: str):
    """
    Retrieve an individual scan using its scan ID.

    Checks active in-memory jobs first, then SQLite.

    Args:
        scan_id (str): Unique scan identifier.

    Returns:
        dict: Scan details and execution status.

    Raises:
        HTTPException: When the scan cannot be found.
    """

    # Check active jobs first.
    with jobs_lock:
        job = scan_jobs.get(scan_id)

        if job is not None:
            return dict(job)

    # Fall back to persistent scan history.
    saved_job = get_saved_scan(scan_id)

    if saved_job is None:
        raise HTTPException(
            status_code=404,
            detail="Scan not found.",
        )

    return saved_job


# ---------------------------------------------------------
# Latest Visual Comparison Report
# ---------------------------------------------------------

@app.get(
    "/api/reports/latest",
    dependencies=[Depends(authenticate)],
)
def get_latest_report():
    """
    Retrieve the most recently generated comparison report.

    Returns:
        dict: JSON report containing viewport results.

    Raises:
        HTTPException: If the report is missing or unreadable.
    """

    # Check whether a report exists.
    if not REPORT_PATH.is_file():
        raise HTTPException(
            status_code=404,
            detail="No report available.",
        )

    try:
        # Read and deserialize the saved JSON report.
        with REPORT_PATH.open(
            "r",
            encoding="utf-8",
        ) as file:
            return json.load(file)

    except (OSError, json.JSONDecodeError):
        logger.exception(
            "Unable to read the latest visual comparison report."
        )

        raise HTTPException(
            status_code=500,
            detail="Unable to read the visual comparison report.",
        )


# ---------------------------------------------------------
# Screenshot Retrieval
# ---------------------------------------------------------

@app.get(
    "/api/screenshots/{mode}/{viewport}",
    dependencies=[Depends(authenticate)],
)
def get_screenshot(
    mode: Literal["baselines", "current", "diffs"],
    viewport: Literal["desktop", "tablet", "mobile"],
):
    """
    Retrieve a screenshot for a selected viewport and mode.

    Supported modes:
        baselines - Approved reference screenshots.
        current   - Most recently captured screenshots.
        diffs     - Generated visual difference images.

    Supported viewports:
        desktop, tablet, mobile.

    Args:
        mode (str): Screenshot category.
        viewport (str): Device viewport.

    Returns:
        FileResponse: Requested PNG screenshot.

    Raises:
        HTTPException: When the screenshot does not exist.
    """

    # Construct the screenshot path from validated parameters.
    screenshot_path = (
        SCREENSHOT_DIR / mode / f"{viewport}.png"
    )

    # Return a clear error when the file is unavailable.
    if not screenshot_path.is_file():
        raise HTTPException(
            status_code=404,
            detail="Screenshot not found.",
        )

    # Prevent browsers from displaying stale screenshots.
    return FileResponse(
        screenshot_path,
        media_type="image/png",
        headers={
            "Cache-Control": "no-store",
        },
    )
