Dataset Aggregator (Free APIs) and Augmentation

This folder contains helper scripts to build a waste‑classification dataset without Bing:

- aggregator.py — downloads images for each class from free sources (Openverse, Pixabay, Pexels, Flickr) and splits into train/val.
- augment_to_target.py — augments train images (crop/rotate/flip/color/blur) to reach a target count per class.

Requirements
- Python 3.9+
- pip install: requests pillow

Optional API keys (set via environment variables)
- Pixabay: PIXABAY_KEY
- Pexels:  PEXELS_KEY
- Flickr:  FLICKR_KEY
Openverse requires no key.

Default Classes (Thai)
กระดาษลัง, ขวดแก้วใส, ผ้า, รองเท้า, หลอดไฟ, แก้วเก็บความเย็น, แบตเตอรี่, โลหะกระป๋อง, กระดาษ, พลาสติก, ไม่ใช่ขยะ

Usage
1) Aggregate images (5k/class) into prepared/raw/<class> and split 80/20 into train/ and val/
   python backend/dataset_tools/aggregator.py --out-dir data/prepared --per-class 5000 --sources openverse,pixabay,pexels,flickr

   On Windows PowerShell, set keys when available:
   $env:PIXABAY_KEY = "YOUR_PIXABAY_KEY"
   $env:PEXELS_KEY  = "YOUR_PEXELS_KEY"
   $env:FLICKR_KEY  = "YOUR_FLICKR_KEY"

2) Augment train to 10k/class (val is kept as is)
   python backend/dataset_tools/augment_to_target.py --data-dir data/prepared --img-size 288 --target-per-class 10000

3) Train as usual from the admin panel (train_model.php)
   - Version: v3
   - Epochs: 10–20
   - The backend saves to models/v3/best.pt; switch model to v3 from the UI.

Notes
- The aggregator resumes from existing files (deduplicated by MD5 of the normalized JPEG).
- Some APIs have rate limits; the script automatically rotates sources and queries.
- Licenses: Openverse and Flickr expose CC licenses; keep metadata if you plan to redistribute images.

