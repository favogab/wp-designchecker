
import json
import sqlite3
from pathlib import Path

DATABASE_PATH = Path(__file__).resolve().parent / "designchecker.db"


def get_connection():
    connection = sqlite3.connect(
        DATABASE_PATH,
        timeout=30,
    )
    connection.row_factory = sqlite3.Row
    return connection


def initialize_database():
    with get_connection() as connection:
        connection.execute("""
            CREATE TABLE IF NOT EXISTS scans (
                scan_id TEXT PRIMARY KEY,
                state TEXT NOT NULL,
                result TEXT,
                error TEXT,
                created_at TEXT NOT NULL,
                finished_at TEXT
            )
        """)


def save_scan(job):
    result = job.get("result")

    with get_connection() as connection:
        connection.execute("""
            INSERT INTO scans (
                scan_id,
                state,
                result,
                error,
                created_at,
                finished_at
            )
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(scan_id) DO UPDATE SET
                state = excluded.state,
                result = excluded.result,
                error = excluded.error,
                finished_at = excluded.finished_at
        """, (
            job["scan_id"],
            job["state"],
            json.dumps(result) if result is not None else None,
            job.get("error"),
            job["created_at"],
            job.get("finished_at"),
        ))


def deserialize_scan(row):
    job = dict(row)

    if job["result"] is not None:
        job["result"] = json.loads(job["result"])

    return job


def get_saved_scan(scan_id):
    with get_connection() as connection:
        row = connection.execute(
            "SELECT * FROM scans WHERE scan_id = ?",
            (scan_id,),
        ).fetchone()

    return deserialize_scan(row) if row else None


def get_scan_history(limit=20):
    with get_connection() as connection:
        rows = connection.execute("""
            SELECT *
            FROM scans
            ORDER BY created_at DESC
            LIMIT ?
        """, (limit,)).fetchall()

    return [deserialize_scan(row) for row in rows]


def recover_interrupted_scans():
    with get_connection() as connection:
        connection.execute("""
            UPDATE scans
            SET state = 'failed',
                error = 'Scan interrupted by service restart.',
                finished_at = strftime(
                    '%Y-%m-%dT%H:%M:%SZ',
                    'now'
                )
            WHERE state IN ('pending', 'running')
        """)
