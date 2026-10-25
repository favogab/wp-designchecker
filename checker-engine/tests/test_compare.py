
"""
Automated tests for WP DesignChecker's image comparison engine.

These tests use temporary screenshots so that the project's real
approved baselines and scan results are never modified.
"""

import json

import pytest
from PIL import Image

import compare


@pytest.fixture
def screenshot_workspace(tmp_path, monkeypatch):
    """
    Create isolated screenshot and report directories.

    pytest creates tmp_path automatically and removes the need
    to work with the real application's screenshot folders.
    """

    baseline_dir = tmp_path / "screenshots" / "baselines"
    current_dir = tmp_path / "screenshots" / "current"
    diff_dir = tmp_path / "screenshots" / "diffs"
    report_dir = tmp_path / "reports"

    baseline_dir.mkdir(parents=True)
    current_dir.mkdir(parents=True)

    # Redirect the comparison engine to our temporary directories.
    monkeypatch.setattr(compare, "BASELINE_DIR", baseline_dir)
    monkeypatch.setattr(compare, "CURRENT_DIR", current_dir)
    monkeypatch.setattr(compare, "DIFF_DIR", diff_dir)
    monkeypatch.setattr(compare, "REPORT_DIR", report_dir)

    return {
        "baselines": baseline_dir,
        "current": current_dir,
        "diffs": diff_dir,
        "reports": report_dir,
    }


def create_test_image(path, color, size=(100, 100)):
    """Create a small PNG screenshot with a known color and size."""

    image = Image.new("RGB", size, color)
    image.save(path)


def test_identical_images_pass(screenshot_workspace):
    """Identical screenshots should pass with zero differences."""

    workspace = screenshot_workspace

    create_test_image(
        workspace["baselines"] / "desktop.png",
        "white",
    )

    create_test_image(
        workspace["current"] / "desktop.png",
        "white",
    )

    result = compare.compare_images("desktop")

    assert result["status"] == "PASS"
    assert result["difference"] == 0.0
    assert (workspace["diffs"] / "desktop-diff.png").exists()


def test_significant_visual_change_fails(screenshot_workspace):
    """A large visual change should exceed the 5% threshold."""

    workspace = screenshot_workspace

    create_test_image(
        workspace["baselines"] / "desktop.png",
        "white",
    )

    create_test_image(
        workspace["current"] / "desktop.png",
        "black",
    )

    result = compare.compare_images("desktop")

    assert result["status"] == "FAIL"
    assert result["difference"] == 100.0
    assert result["message"] == "Visual difference exceeds threshold"


def test_missing_screenshot_returns_error(screenshot_workspace):
    """A missing screenshot should produce a controlled ERROR."""

    result = compare.compare_images("desktop")

    assert result["status"] == "ERROR"
    assert result["difference"] is None
    assert result["message"] == "Missing screenshot"


def test_dimension_change_fails(screenshot_workspace):
    """
    Different screenshot dimensions should count as a regression.

    The comparison engine should not crash or return ERROR.
    """

    workspace = screenshot_workspace

    create_test_image(
        workspace["baselines"] / "desktop.png",
        "white",
        size=(100, 100),
    )

    create_test_image(
        workspace["current"] / "desktop.png",
        "white",
        size=(100, 120),
    )

    result = compare.compare_images("desktop")

    assert result["status"] == "FAIL"
    assert result["difference"] is not None
    assert "Image dimensions changed" in result["message"]

    # Confirm the diff uses the larger image dimensions.
    diff_path = workspace["diffs"] / "desktop-diff.png"

    assert diff_path.exists()

    with Image.open(diff_path) as diff_image:
        assert diff_image.size == (100, 120)


def test_corrupted_image_returns_error(screenshot_workspace):
    """Unreadable PNG files should not crash the comparison engine."""

    workspace = screenshot_workspace

    create_test_image(
        workspace["baselines"] / "desktop.png",
        "white",
    )

    # Write invalid image contents into a .png file.
    (workspace["current"] / "desktop.png").write_text(
        "This is not a PNG image.",
        encoding="utf-8",
    )

    result = compare.compare_images("desktop")

    assert result["status"] == "ERROR"
    assert result["difference"] is None
    assert "Image processing failed" in result["message"]


def test_generate_report_creates_json(screenshot_workspace):
    """
    Verify that the comparison engine generates a complete JSON report.

    All three viewport screenshots must exist before generating
    the report, because generate_report() checks every viewport.
    """

    workspace = screenshot_workspace

    # STEP 1: Create baseline and current screenshots
    # for desktop, tablet, and mobile.
    for viewport in compare.VIEWPORTS:
        create_test_image(
            workspace["baselines"] / f"{viewport}.png",
            "white",
        )

        create_test_image(
            workspace["current"] / f"{viewport}.png",
            "white",
        )

    # STEP 2: Generate the report AFTER all screenshots exist.
    # This line must be OUTSIDE the for loop.
    report = compare.generate_report(detailed=True)

    # STEP 3: Confirm the report is a dictionary.
    # This also helps Pylance understand the return type.
    assert isinstance(report, dict)

    # STEP 4: Verify the overall result.
    assert report["overall_status"] == "PASS"

    # STEP 5: Verify all three viewports were checked.
    assert len(report["results"]) == 3

    # STEP 6: Confirm the JSON report was saved.
    report_path = workspace["reports"] / "latest-report.json"

    assert report_path.exists()

    # STEP 7: Read the saved JSON report.
    saved_report = json.loads(
        report_path.read_text(encoding="utf-8")
    )

    # STEP 8: Verify the saved results.
    assert saved_report["overall_status"] == "PASS"
    assert len(saved_report["results"]) == 3