import os
import io
import sys
import json
import time
import math
import hashlib
import random
import argparse
from pathlib import Path
from typing import Iterable, List, Dict, Tuple, Optional, Union

import requests
from PIL import Image


# Classes can be a list of search queries OR an object with {include:[], exclude:[]}
DEFAULT_CLASSES: Dict[str, Union[List[str], Dict[str, List[str]]]] = {
    "กระดาษลัง": ["กระดาษลัง", "cardboard box", "corrugated cardboard"],
    "ขวดแก้วใส": ["ขวดแก้วใส", "clear glass bottle"],
    "ผ้า": ["ผ้า", "fabric", "cloth"],
    "รองเท้า": ["รองเท้า", "shoes", "sneakers"],
    "หลอดไฟ": ["หลอดไฟ", "fluorescent bulb", "light bulb"],
    "แก้วเก็บความเย็น": ["แก้วเก็บความเย็น", "tumbler", "insulated cup"],
    "แบตเตอรี่": ["แบตเตอรี่", "battery", "AA battery", "lithium battery"],
    "โลหะกระป๋อง": ["โลหะกระป๋อง", "aluminum can", "tin can"],
    "กระดาษ": ["กระดาษ", "paper"],
    "ขวดพลาสติก": ["ขวดพลาสติก", "plastic bottle", "PET bottle", "plastic container"],
    "ไม่ใช่ขยะ": ["ของใช้ทั่วไป", "household items", "not waste"],
}


def md5_bytes(data: bytes) -> str:
    return hashlib.md5(data).hexdigest()


def jpeg_bytes_from_url(url: str, timeout: int = 20, min_size: int = 512) -> Optional[bytes]:
    try:
        r = requests.get(url, timeout=timeout)
        if r.status_code != 200:
            return None
        ctype = (r.headers.get("content-type") or "").lower()
        if "image" not in ctype and not url.lower().endswith((".jpg", ".jpeg", ".png", ".webp", ".bmp")):
            return None
        b = r.content
        im = Image.open(io.BytesIO(b)).convert("RGB")
        w, h = im.size
        if min(w, h) < min_size:
            return None
        bio = io.BytesIO()
        im.save(bio, format="JPEG", quality=92)
        return bio.getvalue()
    except Exception:
        return None


# -------------------- Providers --------------------

def search_openverse(query: str, page: int = 1, page_size: int = 50) -> List[str]:
    url = "https://api.openverse.engineering/v1/images/"
    params = {
        "q": query,
        "license_type": "commercial",
        "extension": "jpg",
        "page": page,
        "page_size": page_size,
    }
    try:
        r = requests.get(url, params=params, timeout=20, headers={"User-Agent":"Mozilla/5.0"})
        if r.status_code != 200:
            return []
        data = r.json()
        items = []
        for it in data.get("results", []):
            u = it.get("url") or it.get("foreign_landing_url") or it.get("thumbnail")
            text = (it.get("title") or "")
            # Openverse may include tags array
            tags = it.get("tags") or []
            if isinstance(tags, list):
                text += " " + " ".join([t.get("name") if isinstance(t, dict) else str(t) for t in tags])
            if u:
                items.append({"url": u, "text": text})
        return items
    except Exception:
        return []


def search_pixabay(query: str, page: int, key: Optional[str]) -> List[dict]:
    if not key:
        return []
    url = "https://pixabay.com/api/"
    params = {
        "key": key,
        "q": query,
        "image_type": "photo",
        "per_page": 200,
        "page": page,
        "min_width": 512,
        "min_height": 512,
    }
    try:
        r = requests.get(url, params=params, timeout=20, headers={"User-Agent":"Mozilla/5.0"})
        if r.status_code != 200:
            return []
        data = r.json()
        items: List[dict] = []
        for h in data.get("hits", []):
            u = h.get("largeImageURL") or h.get("imageURL") or h.get("webformatURL")
            tags = h.get("tags") or ""
            if u:
                items.append({"url": u, "text": str(tags)})
        return items
    except Exception:
        return []


def search_pexels(query: str, page: int, key: Optional[str]) -> List[dict]:
    if not key:
        return []
    url = "https://api.pexels.com/v1/search"
    headers = {"Authorization": key}
    params = {"query": query, "per_page": 80, "page": page}
    try:
        r = requests.get(url, headers=headers, params=params, timeout=20)
        if r.status_code != 200:
            return []
        data = r.json()
        items: List[dict] = []
        for p in data.get("photos", []):
            src = p.get("src", {})
            u = src.get("original") or src.get("large2x") or src.get("large")
            alt = p.get("alt") or ""
            if u: items.append({"url": u, "text": alt})
        return items
    except Exception:
        return []


def search_flickr(query: str, page: int, key: Optional[str]) -> List[dict]:
    if not key:
        return []
    url = "https://api.flickr.com/services/rest/"
    params = {
        "method": "flickr.photos.search",
        "api_key": key,
        "text": query,
        "license": "4,5,6,9,10",  # CC-BY / CC-BY-SA / CC0 / Public Domain
        "extras": "url_o,url_l,url_c,url_m,tags",
        "per_page": 200,
        "page": page,
        "format": "json",
        "nojsoncallback": 1,
        "content_type": 1,  # photos only
        "media": "photos",
        "safe_search": 1,
    }
    try:
        r = requests.get(url, params=params, timeout=20, headers={"User-Agent":"Mozilla/5.0"})
        if r.status_code != 200:
            return []
        data = r.json()
        items: List[dict] = []
        for p in data.get("photos", {}).get("photo", []):
            u = p.get("url_o") or p.get("url_l") or p.get("url_c") or p.get("url_m")
            tags = p.get("tags") or ""
            if u: items.append({"url": u, "text": str(tags)})
        return items
    except Exception:
        return []


def collect_for_class(
    class_name: str,
    queries_spec: Union[List[str], Dict[str, List[str]]],
    out_raw: Path,
    per_class: int,
    min_size: int,
    sources: List[str],
    keys: Dict[str, Optional[str]],
) -> int:
    out_raw.mkdir(parents=True, exist_ok=True)
    # continue from existing files
    existing = list(out_raw.glob("*.jpg"))
    got = len(existing)
    seen_hashes = {p.stem for p in existing}
    print(f"[class={class_name}] existing={got} target={per_class}")
    if got >= per_class:
        return got

    page_counters = {s: 1 for s in sources}
    # build include/exclude tokens
    if isinstance(queries_spec, dict):
        include_tokens = [t.lower() for t in (queries_spec.get("include") or [])]
        exclude_tokens = [t.lower() for t in (queries_spec.get("exclude") or [])]
        class_queries = queries_spec.get("include") or []
    else:
        include_tokens = [t.lower() for t in queries_spec]
        exclude_tokens = []
        class_queries = queries_spec

    GLOBAL_URL_EXCLUDES = ("vector","illustration","clipart","icon","logo","3d","render","ai_generated")

    def pass_text_filter(text: str) -> bool:
        t = (text or "").lower()
        if any(x in t for x in exclude_tokens):
            return False
        # require at least one include token if we have includes
        return (not include_tokens) or any(x in t for x in include_tokens)
    fail_rounds = 0

    while got < per_class and fail_rounds < 50:
        progress_this_round = 0
        random.shuffle(sources)
        for src in sources:
            q = random.choice(class_queries) if class_queries else class_name
            items: List[dict] = []
            if src == "openverse":
                items = search_openverse(q, page=page_counters[src])
            elif src == "pixabay":
                items = search_pixabay(q, page=page_counters[src], key=keys.get("PIXABAY_KEY"))
            elif src == "pexels":
                items = search_pexels(q, page=page_counters[src], key=keys.get("PEXELS_KEY"))
            elif src == "flickr":
                items = search_flickr(q, page=page_counters[src], key=keys.get("FLICKR_KEY"))
            page_counters[src] += 1

            for it in items:
                u = it.get("url")
                meta_text = it.get("text","")
                if got >= per_class:
                    break
                # basic URL heuristics
                if any(bad in (u or "").lower() for bad in GLOBAL_URL_EXCLUDES):
                    continue
                # textual filter
                if not pass_text_filter(meta_text):
                    continue
                b = jpeg_bytes_from_url(u, min_size=min_size)
                if not b:
                    continue
                h = md5_bytes(b)
                if h in seen_hashes:
                    continue
                (out_raw / f"{h}.jpg").write_bytes(b)
                seen_hashes.add(h)
                got += 1
                progress_this_round += 1
        if progress_this_round == 0:
            fail_rounds += 1
        else:
            fail_rounds = 0
        print(f"  -> {got}/{per_class} (round progress {progress_this_round})")
    return got


def split_train_val(raw_dir: Path, train_dir: Path, val_dir: Path, val_ratio: float = 0.2) -> None:
    train_dir.mkdir(parents=True, exist_ok=True)
    val_dir.mkdir(parents=True, exist_ok=True)
    files = list(raw_dir.glob("*.jpg"))
    random.shuffle(files)
    n = len(files)
    n_val = max(200, int(n * val_ratio))
    for i, p in enumerate(files):
        dst = val_dir if i < n_val else train_dir
        target = dst / p.name
        if target.exists():
            continue
        p.replace(target)


def main():
    ap = argparse.ArgumentParser(description="Aggregate images from free sources to build waste dataset")
    ap.add_argument("--out-dir", required=True, help="prepared data root (contains train/ and val/)")
    ap.add_argument("--per-class", type=int, default=5000)
    ap.add_argument("--min-size", type=int, default=512)
    ap.add_argument("--sources", type=str, default="openverse,pixabay,pexels,flickr",
                    help="comma-separated: openverse,pixabay,pexels,flickr")
    ap.add_argument("--class-json", type=str, default=None, help="optional class->queries JSON path")
    ap.add_argument("--seed", type=int, default=42)
    args = ap.parse_args()

    random.seed(args.seed)
    root = Path(args.out_dir)
    raw_root = root / "raw"
    raw_root.mkdir(parents=True, exist_ok=True)

    classes = DEFAULT_CLASSES
    if args.class_json and Path(args.class_json).exists():
        try:
            classes = json.loads(Path(args.class_json).read_text(encoding="utf-8"))
        except Exception as e:
            print("failed to load class json:", e)

    sources = [s.strip().lower() for s in args.sources.split(',') if s.strip()]
    keys = {
        "PIXABAY_KEY": os.getenv("PIXABAY_KEY"),
        "PEXELS_KEY": os.getenv("PEXELS_KEY"),
        "FLICKR_KEY": os.getenv("FLICKR_KEY"),
    }

    for cls, queries in classes.items():
        got = collect_for_class(
            class_name=cls,
            queries_spec=queries,
            out_raw=raw_root / cls,
            per_class=int(args.per_class),
            min_size=int(args.min_size),
            sources=sources,
            keys=keys,
        )
        print(f"[class={cls}] collected total: {got}")

    # split all classes (only move new files; existing train/val kept)
    for cls in classes.keys():
        split_train_val(
            raw_dir=raw_root / cls,
            train_dir=root / "train" / cls,
            val_dir=root / "val" / cls,
            val_ratio=0.2,
        )
    print("All done. Train/Val populated.")


if __name__ == "__main__":
    main()
