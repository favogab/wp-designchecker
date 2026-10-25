
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

from PIL import Image, ImageChops

# ---------------------------------------------------------
# Project directories
# ---------------------------------------------------------

SCREENSHOT_DIR = Path(__file__).resolve().parent / "screenshots"

BASELINE_DIR = SCREENSHOT_DIR / "baselines"
CURRENT_DIR = SCREENSHOT_DIR / "current"
DIFF_DIR = SCREENSHOT_DIR / "diffs"

REPORT_DIR = Path(__file__).resolve().parent / "reports"

# Viewports supported by WP DesignChecker.
VIEWPORTS = ("desktop", "tablet", "mobile")

# Maximum allowed percentage of changed pixels.
THRESHOLD = 5.0


def compare_images(device):
    """
    Compare an approved baseline with the latest screenshot.

    A visual comparison fails when:
    1. The percentage of changed pixels exceeds THRESHOLD.
    2. The baseline and current screenshot dimensions differ.

    Dimension mismatches are treated as visual regressions,
    not image-processing errors.

    Returns:
        dict: Comparison result for one viewport.
    """

    baseline_path = BASELINE_DIR / f"{device}.png"
    current_path = CURRENT_DIR / f"{device}.png"

    # -----------------------------------------------------
    # Step 1: Confirm both screenshots exist.
    # -----------------------------------------------------

    if not baseline_path.exists() or not current_path.exists():
        return {
            "viewport": device,
            "status": "ERROR",
            "difference": None,
            "message": "Missing screenshot",
        }

    try:
        # -------------------------------------------------
        # Step 2: Open screenshots and normalize color mode.
        # -------------------------------------------------

        with Image.open(baseline_path) as baseline_image, \
             Image.open(current_path) as current_image:

            baseline = baseline_image.convert("RGB")
            current = current_image.convert("RGB")

        # -------------------------------------------------
        # Step 3: Check whether image dimensions differ.
        # -------------------------------------------------

        dimensions_changed = baseline.size != current.size

        # Determine the largest width and height.
        #
        # Example:
        # Baseline: 1440 x 1677
        # Current:  1440 x 1731
        #
        # Canvas:  1440 x 1731

        canvas_width = max(baseline.width, current.width)
        canvas_height = max(baseline.height, current.height)

        # -------------------------------------------------
        # Step 4: Create equal-sized comparison canvases.
        # -------------------------------------------------

        # Both canvases use black backgrounds.
        # Original screenshot pixels are not resized.
        baseline_canvas = Image.new(
            "RGB",
            (canvas_width, canvas_height),
            (0, 0, 0),
        )

        current_canvas = Image.new(
            "RGB",
            (canvas_width, canvas_height),
            (0, 0, 0),
        )

        # Align both screenshots at the top-left corner.
        baseline_canvas.paste(baseline, (0, 0))
        current_canvas.paste(current, (0, 0))

        # -------------------------------------------------
        # Step 5: Calculate the image difference.
        # -------------------------------------------------

        difference_image = ImageChops.difference(
            baseline_canvas,
            current_canvas,
        )

        # Count pixels whose RGB values are different.
        changed_pixels = sum(
            1
            for pixel in difference_image.get_flattened_data()
            if pixel != (0, 0, 0)
        )

        total_pixels = canvas_width * canvas_height

        percentage = round(
            (changed_pixels / total_pixels) * 100,
            2,
        )

        # -------------------------------------------------
        # Step 6: Highlight regions outside either image.
        # -------------------------------------------------

        # Black padding can occasionally hide differences
        # when the actual page background is also black.
        #
        # To prevent this, explicitly highlight areas
        # present in only one screenshot.

        if dimensions_changed:

            # Determine the common overlapping region.
            shared_width = min(
                baseline.width,
                current.width,
            )

            shared_height = min(
                baseline.height,
                current.height,
            )

            # Highlight additional or missing rows.
            if baseline.height != current.height:
                from PIL import ImageDraw

                draw = ImageDraw.Draw(difference_image)

                draw.rectangle(
                    (
                        0,
                        shared_height,
                        canvas_width - 1,
                        canvas_height - 1,
                    ),
                    fill=(255, 0, 255),
                )

            # Highlight additional or missing columns.
            if baseline.width != current.width:
                from PIL import ImageDraw

                draw = ImageDraw.Draw(difference_image)

                draw.rectangle(
                    (
                        shared_width,
                        0,
                        canvas_width - 1,
                        canvas_height - 1,
                    ),
                    fill=(255, 0, 255),
                )

            # Count the highlighted pixels as changed,
            # including regions that would otherwise
            # appear black on both comparison canvases.
            changed_pixels = sum(
                1
                for pixel in difference_image.get_flattened_data()
                if pixel != (0, 0, 0)
            )

            percentage = round(
                (changed_pixels / total_pixels) * 100,
                2,
            )

        # -------------------------------------------------
        # Step 7: Save the visual difference image.
        # -------------------------------------------------

        DIFF_DIR.mkdir(parents=True, exist_ok=True)

        difference_image.save(
            DIFF_DIR / f"{device}-diff.png"
        )

        # -------------------------------------------------
        # Step 8: Determine the comparison result.
        # -------------------------------------------------

        # A dimension mismatch is always a FAIL because
        # page width or height has changed.
        #
        # Otherwise, use the existing 5% threshold.

        if dimensions_changed:
            status = "FAIL"

            message = (
                "Image dimensions changed: "
                f"{baseline.width}x{baseline.height} to "
                f"{current.width}x{current.height}"
            )

        elif percentage > THRESHOLD:
            status = "FAIL"
            message = "Visual difference exceeds threshold"

        else:
            status = "PASS"
            message = "Comparison completed"

        return {
            "viewport": device,
            "status": status,
            "difference": percentage,
            "message": message,
        }

    except (OSError, ValueError) as error:
        return {
            "viewport": device,
            "status": "ERROR",
            "difference": None,
            "message": (
                "Image processing failed: "
                f"{type(error).__name__}"
            ),
        }


def generate_report(detailed=False):
    """
    Generate the visual regression report.

    Compares approved baselines against current screenshots
    for desktop, tablet, and mobile viewports.

    Args:
        detailed (bool):
            False returns only the overall status.
            True returns the complete report.

    Returns:
        str | dict:
            Overall status or full comparison report.
    """

    # Compare every supported viewport.
    results = [
        compare_images(device)
        for device in VIEWPORTS
    ]

    # Extract viewport statuses.
    statuses = [
        result["status"]
        for result in results
    ]

    # ERROR takes priority over FAIL.
    if "ERROR" in statuses:
        overall_status = "ERROR"

    elif "FAIL" in statuses:
        overall_status = "FAIL"

    else:
        overall_status = "PASS"

    # Build the report using the existing format.
    report = {
        "project": "WP DesignChecker",
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "threshold": THRESHOLD,
        "overall_status": overall_status,
        "results": results,
    }

    # Save the latest report.
    REPORT_DIR.mkdir(parents=True, exist_ok=True)

    report_path = REPORT_DIR / "latest-report.json"

    with report_path.open("w", encoding="utf-8") as file:
        json.dump(report, file, indent=4)

    # Display results in the terminal.
    print("\nWP DesignChecker — Visual Regression Report\n")

    for result in results:
        difference = result["difference"]

        percentage = (
            f"{difference:.2f}%"
            if difference is not None
            else "N/A"
        )

        print(
            f"{result['viewport'].capitalize()}: "
            f"{result['status']} | {percentage}"
        )

    print(f"\nOverall: {overall_status}")
    print(f"Report saved: {report_path}")

    if detailed:
        return report

    return overall_status


if __name__ == "__main__":
    """
    Run the comparison engine from the terminal.

    Exit codes:
        0 = PASS
        1 = FAIL
        2 = ERROR
    """

    status = generate_report(detailed=False)

    exit_codes = {
        "PASS": 0,
        "FAIL": 1,
        "ERROR": 2,
    }

    if not isinstance(status, str):
        raise TypeError("Expected a string comparison status.")

    sys.exit(exit_codes[status])
