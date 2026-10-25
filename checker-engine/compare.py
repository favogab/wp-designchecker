
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

from PIL import Image, ImageChops

SCREENSHOT_DIR = Path(__file__).resolve().parent / "screenshots"
BASELINE_DIR = SCREENSHOT_DIR / "baselines"
CURRENT_DIR = SCREENSHOT_DIR / "current"
DIFF_DIR = SCREENSHOT_DIR / "diffs"
REPORT_DIR = Path(__file__).resolve().parent / "reports"

VIEWPORTS = ("desktop", "tablet", "mobile")
THRESHOLD = 5.0


def compare_images(device):
    baseline_path = BASELINE_DIR / f"{device}.png"
    current_path = CURRENT_DIR / f"{device}.png"

    if not baseline_path.exists() or not current_path.exists():
        return {
            "viewport": device,
            "status": "ERROR",
            "difference": None,
            "message": "Missing screenshot",
        }

    try:
        with Image.open(baseline_path) as baseline_image, \
             Image.open(current_path) as current_image:

            baseline = baseline_image.convert("RGB")
            current = current_image.convert("RGB")

            if baseline.size != current.size:
                return {
                    "viewport": device,
                    "status": "ERROR",
                    "difference": None,
                    "message": "Image dimensions differ",
                }

            # Calculate the percentage of changed pixels.
            difference = ImageChops.difference(baseline, current)

            changed_pixels = sum(
                1 for pixel in difference.get_flattened_data()
                if pixel != (0, 0, 0)
            )

            total_pixels = baseline.width * baseline.height
            percentage = round(
                (changed_pixels / total_pixels) * 100, 2
            )

            DIFF_DIR.mkdir(parents=True, exist_ok=True)
            difference.save(DIFF_DIR / f"{device}-diff.png")

            return {
                "viewport": device,
                "status": "FAIL" if percentage > THRESHOLD else "PASS",
                "difference": percentage,
                "message": "Comparison completed",
            }

    except (OSError, ValueError) as error:
        return {
            "viewport": device,
            "status": "ERROR",
            "difference": None,
            "message": f"Image processing failed: {type(error).__name__}",
        }


def generate_report():
    results = [compare_images(device) for device in VIEWPORTS]

    statuses = [result["status"] for result in results]

    if "ERROR" in statuses:
        overall_status = "ERROR"
    elif "FAIL" in statuses:
        overall_status = "FAIL"
    else:
        overall_status = "PASS"

    report = {
        "project": "WP DesignChecker",
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "threshold": THRESHOLD,
        "overall_status": overall_status,
        "results": results,
    }

    REPORT_DIR.mkdir(parents=True, exist_ok=True)

    report_path = REPORT_DIR / "latest-report.json"

    with report_path.open("w", encoding="utf-8") as file:
        json.dump(report, file, indent=4)

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

    return overall_status


if __name__ == "__main__":
    status = generate_report()

    exit_codes = {
        "PASS": 0,
        "FAIL": 1,
        "ERROR": 2,
    }

    sys.exit(exit_codes[status])
