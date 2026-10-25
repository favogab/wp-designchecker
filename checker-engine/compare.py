
from pathlib import Path
from PIL import Image, ImageChops

SCREENSHOT_DIR = Path(__file__).resolve().parent / "screenshots"

BASELINE_DIR = SCREENSHOT_DIR / "baselines"
CURRENT_DIR = SCREENSHOT_DIR / "current"
DIFF_DIR = SCREENSHOT_DIR / "diffs"

VIEWPORTS = ("desktop", "tablet", "mobile")


def compare_images(device):
    baseline_path = BASELINE_DIR / f"{device}.png"
    current_path = CURRENT_DIR / f"{device}.png"

    if not baseline_path.exists() or not current_path.exists():
        print(f"{device}: Missing screenshot.")
        return

    with Image.open(baseline_path) as baseline_image, \
         Image.open(current_path) as current_image:

        baseline = baseline_image.convert("RGB")
        current = current_image.convert("RGB")

        if baseline.size != current.size:
            print(f"{device}: Image dimensions differ.")
            return

        # Count pixels whose RGB values differ.
        difference = ImageChops.difference(baseline, current)
        changed_pixels = sum(
            1 for pixel in difference.get_flattened_data()
            if pixel != (0, 0, 0)
        )

        total_pixels = baseline.width * baseline.height
        difference_percentage = (changed_pixels / total_pixels) * 100

        DIFF_DIR.mkdir(parents=True, exist_ok=True)
        difference.save(DIFF_DIR / f"{device}-diff.png")

        print(
            f"{device.capitalize()}: "
            f"{difference_percentage:.2f}% visual difference"
        )


if __name__ == "__main__":
    for device in VIEWPORTS:
        compare_images(device)