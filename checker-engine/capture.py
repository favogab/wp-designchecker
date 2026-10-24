
from pathlib import Path
from playwright.sync_api import sync_playwright

OUTPUT_DIR = Path(__file__).resolve().parent / "screenshots"
OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

PAGE_URL = "http://wp-designchecker.local/designcheck-test-page/"

VIEWPORTS = {
    "desktop": {"width": 1440, "height": 900},
    "tablet": {"width": 768, "height": 1024},
    "mobile": {"width": 390, "height": 844},
}


def capture_screenshots():
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)

        try:
            for device, viewport in VIEWPORTS.items():
                page = browser.new_page(
                    viewport=viewport,
                    device_scale_factor=1,
                )

                try:
                    page.goto(PAGE_URL, wait_until="load", timeout=30000)
                    page.screenshot(
                        path=str(OUTPUT_DIR / f"{device}.png"),
                        full_page=True,
                        animations="disabled",
                    )

                    print(f"{device.capitalize()} screenshot captured!")

                finally:
                    page.close()

        finally:
            browser.close()


if __name__ == "__main__":
    capture_screenshots()
