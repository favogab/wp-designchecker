
import os
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI, HTTPException
from fastapi.security import APIKeyHeader
from starlette.concurrency import run_in_threadpool

from capture import capture_screenshots
from compare import generate_report

from pathlib import Path
from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent.parent / ".env")

API_KEY = os.getenv("DESIGNCHECK_API_KEY")
api_key_header = APIKeyHeader(name="X-API-Key", auto_error=False)


@asynccontextmanager
async def lifespan(app: FastAPI):
    if not API_KEY:
        raise RuntimeError("DESIGNCHECK_API_KEY is not configured.")
    yield


app = FastAPI(
    title="WP DesignChecker API",
    version="0.1.0",
    lifespan=lifespan,
)


def authenticate(api_key: str | None = Depends(api_key_header)):
    if not API_KEY or api_key != API_KEY:
        raise HTTPException(status_code=401, detail="Unauthorized")


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
    def execute_scan():
        # Never overwrite approved baseline screenshots during a scan.
        capture_screenshots("current")
        return generate_report()

    status = await run_in_threadpool(execute_scan)

    return {
        "status": status,
        "message": "Scan completed",
    }