
"""
Automated database tests for WP DesignChecker.

Tests SQLite scan persistence, updates, history retrieval,
and recovery after interrupted scans.

All tests use an isolated temporary database.
The real designchecker.db file is never modified.
"""

import sqlite3

import pytest

import database


@pytest.fixture
def test_database(tmp_path, monkeypatch):
    """
    Create an isolated SQLite database for each test.

    tmp_path:
        Creates a temporary directory managed by pytest.

    monkeypatch:
        Redirects the database module to our test database,
        protecting real WP DesignChecker scan history.
    """

    temporary_database = tmp_path / "test_designchecker.db"

    monkeypatch.setattr(
        database,
        "DATABASE_PATH",
        temporary_database,
    )

    # Create the scans table inside the temporary database.
    database.initialize_database()

    return temporary_database


def create_test_scan(
    scan_id: str = "test-scan-001",
    state: str = "completed",
    result: dict | None = None,
    error: str | None = None,
    created_at: str = "2026-10-25T10:00:00Z",
    finished_at: str | None = "2026-10-25T10:00:10Z",
) -> dict:
    """
    Build a reusable scan record for database tests.

    This keeps test data consistent and makes each
    individual test easier to understand.

    Args:
        scan_id: Unique identifier for the test scan.
        state: Scan execution state.
        result: Optional visual comparison report.
        error: Optional error message.
        created_at: Timestamp when the scan was created.
        finished_at: Completion timestamp, or None when unfinished.

    Returns:
        dict: A scan record suitable for database tests.
    """

    return {
        "scan_id": scan_id,
        "state": state,
        "result": result,
        "error": error,
        "created_at": created_at,
        "finished_at": finished_at,
    }


def test_database_initialization(test_database):
    """
    Verify that initialization creates the scans table.
    """

    with sqlite3.connect(test_database) as connection:
        row = connection.execute(
            """
            SELECT name
            FROM sqlite_master
            WHERE type = 'table' AND name = 'scans'
            """
        ).fetchone()

    assert row is not None
    assert row[0] == "scans"


def test_save_and_retrieve_scan(test_database):
    """
    Verify that a scan can be saved and retrieved by ID.
    """

    scan = create_test_scan()

    database.save_scan(scan)

    saved_scan = database.get_saved_scan(scan["scan_id"])

    assert saved_scan is not None
    assert saved_scan["scan_id"] == "test-scan-001"
    assert saved_scan["state"] == "completed"
    assert saved_scan["created_at"] == scan["created_at"]


def test_scan_result_json_round_trip(test_database):
    """
    Verify that structured comparison results survive
    JSON serialization and deserialization.
    """

    comparison_result = {
        "overall_status": "PASS",
        "results": [
            {
                "viewport": "desktop",
                "status": "PASS",
                "difference": 0.0,
            }
        ],
    }

    scan = create_test_scan(result=comparison_result)

    database.save_scan(scan)

    saved_scan = database.get_saved_scan(scan["scan_id"])

    assert saved_scan is not None
    assert saved_scan["result"] == comparison_result
    assert isinstance(saved_scan["result"], dict)


def test_update_existing_scan(test_database):
    """
    Verify that updating a scan changes its state without
    creating a duplicate or changing its creation time.
    """

    original_scan = create_test_scan(
        state="pending",
        result=None,
        finished_at=None,
    )

    database.save_scan(original_scan)

    updated_scan = create_test_scan(
        state="completed",
        result={"overall_status": "PASS"},
    )

    database.save_scan(updated_scan)

    saved_scan = database.get_saved_scan("test-scan-001")
    history = database.get_scan_history()

    assert saved_scan is not None
    assert saved_scan["state"] == "completed"
    assert saved_scan["result"]["overall_status"] == "PASS"
    assert saved_scan["created_at"] == original_scan["created_at"]

    # The update must not create another scan record.
    assert len(history) == 1


def test_scan_history_order_and_limit(test_database):
    """
    Verify that history returns newest scans first
    and respects the requested maximum record count.
    """

    scans = [
        create_test_scan(
            scan_id="scan-001",
            created_at="2026-10-25T10:00:00Z",
        ),
        create_test_scan(
            scan_id="scan-002",
            created_at="2026-10-25T11:00:00Z",
        ),
        create_test_scan(
            scan_id="scan-003",
            created_at="2026-10-25T12:00:00Z",
        ),
    ]

    for scan in scans:
        database.save_scan(scan)

    history = database.get_scan_history(limit=2)

    assert len(history) == 2
    assert history[0]["scan_id"] == "scan-003"
    assert history[1]["scan_id"] == "scan-002"


def test_missing_scan_returns_none(test_database):
    """
    Verify that an unknown scan ID returns None
    instead of causing an exception.
    """

    result = database.get_saved_scan("does-not-exist")

    assert result is None


def test_scan_persists_across_connections(test_database):
    """
    Verify that saved records survive closing and
    reopening SQLite database connections.

    This tests disk persistence, not a full FastAPI restart.
    """

    scan = create_test_scan()

    database.save_scan(scan)

    # Read the saved scan through a new SQLite connection.
    with sqlite3.connect(test_database) as connection:
        row = connection.execute(
            "SELECT state FROM scans WHERE scan_id = ?",
            ("test-scan-001",),
        ).fetchone()

    assert row is not None
    assert row[0] == "completed"


def test_recover_interrupted_scans(test_database):
    """
    Verify that pending and running scans become failed
    after recovery, while completed scans remain unchanged.
    """

    database.save_scan(
        create_test_scan(
            scan_id="pending-scan",
            state="pending",
            finished_at=None,
        )
    )

    database.save_scan(
        create_test_scan(
            scan_id="running-scan",
            state="running",
            finished_at=None,
        )
    )

    database.save_scan(
        create_test_scan(
            scan_id="completed-scan",
            state="completed",
        )
    )

    database.recover_interrupted_scans()

    pending = database.get_saved_scan("pending-scan")
    running = database.get_saved_scan("running-scan")
    completed = database.get_saved_scan("completed-scan")

    assert pending is not None
    assert running is not None
    assert completed is not None

    assert pending["state"] == "failed"
    assert running["state"] == "failed"

    assert (
        pending["error"]
        == "Scan interrupted by service restart."
    )
    assert (
        running["error"]
        == "Scan interrupted by service restart."
    )

    assert pending["finished_at"] is not None
    assert running["finished_at"] is not None

    # A completed scan must not be changed by recovery.
    assert completed["state"] == "completed"
    assert completed["error"] is None
