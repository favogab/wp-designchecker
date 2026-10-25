
from pathlib import Path
import argparse

from playwright.sync_api import sync_playwright

PAGE_URL = "http://wp-designchecker.local/designcheck-test-page/"

SCREENSHOT_DIR = Path(__file__).resolve().parent / "screenshots"

VIEWPORTS = {
    "desktop": {"width": 1440, "height": 900},
    "tablet": {"width": 768, "height": 1024},
    "mobile": {"width": 390, "height": 844},
}


def capture_screenshots(mode):
    output_dir = SCREENSHOT_DIR / (
        "baselines" if mode == "baseline" else "current"
    )
    output_dir.mkdir(parents=True, exist_ok=True)

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)

        try:
            for device, viewport in VIEWPORTS.items():
                print(f"Starting {device} capture...", flush=True)
                output_file = output_dir / f"{device}.png"

                # Protect approved baselines from accidental replacement.
                if mode == "baseline" and output_file.exists():
                    print(f"Baseline already exists: {device}. Skipping.")
                    continue

                page = browser.new_page(
                    viewport=viewport,
                    device_scale_factor=1,
                )

                try:
                    print(f"Navigating to {PAGE_URL} for {device}...", flush=True)
                    page.goto(PAGE_URL, wait_until="domcontentloaded", timeout=60000)
                    page.locator("body").wait_for(state="visible", timeout=15000)
                    page.screenshot(
                        path=str(output_file),
                        full_page=True,
                        animations="disabled",
                    )

                    print(f"{device.capitalize()} {mode} captured.")

                finally:
                    page.close()

        finally:
            browser.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="Capture WordPress design screenshots."
    )

    parser.add_argument(
        "--mode",
        choices=["baseline", "current"],
        default="current",
        help="Choose approved baseline or current screenshot.",
    )

    args = parser.parse_args()
    capture_screenshots(args.mode)
