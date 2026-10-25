
"""
Automated API security tests for WP DesignChecker.

These tests use FastAPI's TestClient and mock database lookups
so they do not modify real scans, screenshots, or SQLite records.
"""

import pytest
from fastapi.testclient import TestClient

import main


@pytest.fixture
def api_client(monkeypatch):
    """
    Create an isolated API test client.

    Replace database initialization and recovery functions
    so application startup cannot modify real scan history.
    """

    monkeypatch.setattr(main, "API_KEY", "test-only-secret-key")

    monkeypatch.setattr(
        main,
        "initialize_database",
        lambda: None,
    )

    monkeypatch.setattr(
        main,
        "recover_interrupted_scans",
        lambda: None,
    )

    # Prevent tests from reading the real SQLite database.
    monkeypatch.setattr(
        main,
        "get_scan_history",
        lambda limit=20: [],
    )

    monkeypatch.setattr(
        main,
        "get_saved_scan",
        lambda scan_id: None,
    )

    with TestClient(main.app) as client:
        yield client


def test_health_endpoint_is_accessible(api_client):
    """The public health endpoint should respond successfully."""

    response = api_client.get("/health")

    assert response.status_code == 200
    assert response.json()["status"] == "ok"


def test_missing_api_key_is_rejected(api_client):
    """Protected endpoints must reject requests without a key."""

    response = api_client.get("/api/scans")

    assert response.status_code == 401
    assert response.json()["detail"] == "Unauthorized"


def test_invalid_api_key_is_rejected(api_client):
    """An incorrect API key must not grant access."""

    response = api_client.get(
        "/api/scans",
        headers={
            "X-API-Key": "incorrect-key",
        },
    )

    assert response.status_code == 401
    assert response.json()["detail"] == "Unauthorized"


def test_valid_api_key_allows_scan_history(api_client):
    """A valid key should allow access to scan history."""

    response = api_client.get(
        "/api/scans",
        headers={
            "X-API-Key": "test-only-secret-key",
        },
    )

    assert response.status_code == 200
    assert response.json() == {
        "count": 0,
        "scans": [],
    }


def test_invalid_history_limit_is_rejected(api_client):
    """Scan history requests must respect the 1–100 limit."""

    response = api_client.get(
        "/api/scans?limit=101",
        headers={
            "X-API-Key": "test-only-secret-key",
        },
    )

    assert response.status_code == 400
    assert response.json()["detail"] == (
        "Limit must be between 1 and 100."
    )


def test_unknown_scan_returns_404(api_client):
    """An unknown scan should return a controlled 404 response."""

    response = api_client.get(
        "/api/scans/not-a-valid-uuid",
        headers={
            "X-API-Key": "test-only-secret-key",
        },
    )

    assert response.status_code == 404
    assert response.json()["detail"] == "Scan not found."


def test_invalid_historical_scan_id_returns_400(api_client):
    """Historical screenshots must reject malformed scan IDs."""

    response = api_client.get(
        "/api/scans/not-a-valid-uuid/screenshots/current/desktop",
        headers={
            "X-API-Key": "test-only-secret-key",
        },
    )

    assert response.status_code == 400
    assert response.json()["detail"] == "Invalid scan ID."


def test_historical_screenshot_requires_authentication(api_client):
    """Archived images must not be publicly accessible."""

    response = api_client.get(
        "/api/scans/not-a-valid-uuid/screenshots/current/desktop"
    )

    assert response.status_code == 401
    assert response.json()["detail"] == "Unauthorized"
