
from pathlib import Path
from PIL import Image

# Directory containing our saved screenshots.
SCREENSHOTS = Path(__file__).resolve().parent / "screenshots"

# Check the image sizes for each supported viewport.
for viewport in ("desktop", "tablet", "mobile"):
    baseline_path = SCREENSHOTS / "baselines" / f"{viewport}.png"
    current_path = SCREENSHOTS / "current" / f"{viewport}.png"

    # Read the width and height of each screenshot.
    with Image.open(baseline_path) as baseline:
        baseline_size = baseline.size

    with Image.open(current_path) as current:
        current_size = current.size

    print(f"\n{viewport.capitalize()}")
    print(f"  Baseline: {baseline_size}")
    print(f"  Current:  {current_size}")
    print(f"  Match:    {baseline_size == current_size}")
