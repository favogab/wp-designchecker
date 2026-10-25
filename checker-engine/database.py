"""
WP DesignChecker — SQLite Database Layer

Manages persistent scan records, including scan results,
execution status, errors, and historical scan retrieval.

SQLite allows scan history to survive Python service restarts.
"""

import json
import sqlite3
from pathlib import Path


# Store the SQLite database inside the checker-engine directory.
DATABASE_PATH = Path(__file__).resolve().parent / "designchecker.db"


def get_connection():
    """
    Create and configure a SQLite database connection.

    Returns:
        sqlite3.Connection: Connection configured to return
        rows accessible by column names.
    """
    connection = sqlite3.connect(
        DATABASE_PATH,
        timeout=30,
    )

    connection.row_factory = sqlite3.Row

    return connection


def initialize_database():
    """
    Create the scans table if it does not already exist.

    Stores scan identifiers, execution states, visual results,
    errors, and timestamps.
    """
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
    """
    Insert a new scan or update an existing scan record.

    Args:
        job (dict): Scan information containing its identifier,
            state, result, error, and timestamps.

    The result is serialized to JSON before storage.
    """
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
    """
    Convert a SQLite scan record into a Python dictionary.

    JSON-encoded results are converted back into their
    original Python data types.

    Args:
        row (sqlite3.Row): Database record.

    Returns:
        dict: Deserialized scan information.
    """
    job = dict(row)

    if job["result"] is not None:
        job["result"] = json.loads(job["result"])

    return job


def get_saved_scan(scan_id):
    """
    Retrieve a previously saved scan using its unique identifier.

    Args:
        scan_id (str): Unique scan identifier.

    Returns:
        dict | None: Scan information or None if not found.
    """
    with get_connection() as connection:
        row = connection.execute(
            "SELECT * FROM scans WHERE scan_id = ?",
            (scan_id,),
        ).fetchone()

    return deserialize_scan(row) if row else None


def get_scan_history(limit=20):
    """
    Retrieve the most recent scan records.

    Args:
        limit (int): Maximum number of records to retrieve.

    Returns:
        list[dict]: Scan records ordered from newest to oldest.
    """
    with get_connection() as connection:
        rows = connection.execute("""
            SELECT *
            FROM scans
            ORDER BY created_at DESC
            LIMIT ?
        """, (limit,)).fetchall()

    return [deserialize_scan(row) for row in rows]


def recover_interrupted_scans():
    """
    Mark unfinished scans as failed after a service restart.

    Prevents scans left in pending or running states from
    appearing permanently active after an unexpected shutdown.
    """
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