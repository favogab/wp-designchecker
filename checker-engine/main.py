
import os
import json
import logging
import threading
from contextlib import asynccontextmanager
from datetime import datetime, timezone
from pathlib import Path
from uuid import uuid4

from dotenv import load_dotenv
from fastapi import BackgroundTasks, Depends, FastAPI, HTTPException
from fastapi.security import APIKeyHeader
from starlette.concurrency import run_in_threadpool

from capture import capture_screenshots
from compare import generate_report

from fastapi.responses import FileResponse
from typing import Literal

from database import (
    initialize_database,
    recover_interrupted_scans,
    save_scan,
    get_saved_scan,
    get_scan_history,
)

load_dotenv(Path(__file__).resolve().parent.parent / ".env")

API_KEY = os.getenv("DESIGNCHECK_API_KEY")
api_key_header = APIKeyHeader(name="X-API-Key", auto_error=False)

scan_jobs = {}
jobs_lock = threading.Lock()
scan_lock = threading.Lock()


@asynccontextmanager
async def lifespan(app: FastAPI):
    if not API_KEY:
        raise RuntimeError(
            "DESIGNCHECK_API_KEY is not configured."
        )

    initialize_database()
    recover_interrupted_scans()

    yield


app = FastAPI(
    title="WP DesignChecker API",
    version="0.1.0",
    lifespan=lifespan,
)


def authenticate(api_key: str | None = Depends(api_key_header)):
    if not API_KEY or api_key != API_KEY:
        raise HTTPException(status_code=401, detail="Unauthorized")


def execute_scan():
    # Preserve approved baselines during automated scans.
    capture_screenshots("current")
    return generate_report()

logger = logging.getLogger(__name__)


def process_scan(scan_id):
    if not scan_lock.acquire(blocking=False):
        with jobs_lock:
            job = scan_jobs[scan_id]
            job["state"] = "failed"
            job["error"] = "Another scan is already running."
            job["finished_at"] = datetime.now(
                timezone.utc
            ).isoformat()
            save_scan(dict(job))
        return

    try:
        with jobs_lock:
            job = scan_jobs[scan_id]
            job["state"] = "running"
            save_scan(dict(job))

        result = execute_scan()

        with jobs_lock:
            job = scan_jobs[scan_id]
            job["state"] = "completed"
            job["result"] = result
            job["finished_at"] = datetime.now(
                timezone.utc
            ).isoformat()
            save_scan(dict(job))

    except Exception:
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

        logger.exception("Scan job failed")

    finally:
        scan_lock.release()


@app.get("/health")
def health():
    return {"status": "ok", "service": "WP DesignChecker"}


@app.post("/api/compare", dependencies=[Depends(authenticate)])
async def compare():
    status = await run_in_threadpool(generate_report)

    return {
        "status": status,
        "message": "Comparison completed",
    }


@app.post("/api/scan", dependencies=[Depends(authenticate)])
async def scan():
    status = await run_in_threadpool(execute_scan)

    return {
        "status": status,
        "message": "Scan completed",
    }


@app.post("/api/scans", status_code=202, dependencies=[Depends(authenticate)])
def create_scan(background_tasks: BackgroundTasks):
    with jobs_lock:
        if any(job["state"] in ("pending", "running") for job in scan_jobs.values()):
            raise HTTPException(status_code=409, detail="A scan is already in progress.")

        scan_id = str(uuid4())

        scan_jobs[scan_id] = {
            "scan_id": scan_id,
            "state": "pending",
            "result": None,
            "error": None,
            "created_at": datetime.now(timezone.utc).isoformat(),
        }

    save_scan(dict(scan_jobs[scan_id]))
    background_tasks.add_task(process_scan, scan_id)

    return {
        "scan_id": scan_id,
        "state": "pending",
        "message": "Scan queued successfully",
    }



@app.get(
    "/api/scans",
    dependencies=[Depends(authenticate)]
)
def list_scans():
    return {
        "scans": get_scan_history(limit=20)
    }


@app.get("/api/scans/{scan_id}", dependencies=[Depends(authenticate)])

@app.get(
    "/api/scans/{scan_id}",
    dependencies=[Depends(authenticate)]
)
def get_scan(scan_id: str):
    with jobs_lock:
        job = scan_jobs.get(scan_id)

        if job is not None:
            return dict(job)

    saved_job = get_saved_scan(scan_id)

    if saved_job is None:
        raise HTTPException(
            status_code=404,
            detail="Scan not found."
        )

    return saved_job


@app.get("/api/reports/latest", dependencies=[Depends(authenticate)])
def get_latest_report():
    """Return the latest completed visual comparison report."""

    report_path = Path(__file__).resolve().parent / "reports" / "latest-report.json"

    # Return 404 if no comparison report has been generated.
    if not report_path.is_file():
        raise HTTPException(status_code=404, detail="No report available.")

    try:
        with report_path.open("r", encoding="utf-8") as file:
            return json.load(file)
    except (OSError, json.JSONDecodeError):
        raise HTTPException(
            status_code=500,
            detail="Unable to read the visual comparison report.",
        )    

SCREENSHOT_DIR = Path(__file__).resolve().parent / "screenshots"

@app.get(
    "/api/screenshots/{mode}/{viewport}",
    dependencies=[Depends(authenticate)]
)
def get_screenshot(
    mode: Literal["baselines", "current", "diffs"],
    viewport: Literal["desktop", "tablet", "mobile"],
):
    screenshot_path = SCREENSHOT_DIR / mode / f"{viewport}.png"

    if not screenshot_path.is_file():
        raise HTTPException(
            status_code=404,
            detail="Screenshot not found."
        )

    return FileResponse(
        screenshot_path,
        media_type="image/png",
        headers={"Cache-Control": "no-store"}
    )    