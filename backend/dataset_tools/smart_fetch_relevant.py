# smart_fetch_relevant.py
import os
import io
import json
import time
import math
import random
import hashlib
import requests
from pathlib import Path
from typing import List, Dict
from PIL import Image
from tqdm import tqdm

import torch
from transformers import CLIPProcessor, CLIPModel

# ------------------ CONFIG ------------------
OUT_DIR = str(Path(__file__).resolve().parents[2] / "data" / "prepared" / "train")  # โฟลเดอร์ปลายทาง (เช่น train)
PER_CLASS = 5000                                          # ต้องการกี่ภาพต่อคลาส (ต้นฉบับ)
MIN_SIZE = 512                                            # ด้านสั้นสุดอย่างน้อยเท่านี้พิกเซล
SCORE_THRESH = 0.30                                       # เกณฑ์ CLIP (ยิ่งสูงยิ่งเข้ม)
MAX_PER_QUERY = 80                                        # จำกัดต่อคำค้น/ต่อแหล่ง เพื่อกระจายแหล่งที่มา

# แหล่งข้อมูล: เปิด/ปิดได้
USE_OPENVERSE = True
USE_PIXABAY   = True if os.getenv("PIXABAY_KEY") else False
USE_PEXELS    = True if os.getenv("PEXELS_KEY") else False
USE_FLICKR    = True if os.getenv("FLICKR_KEY") else False

# คำลบ (ช่วยกันหลุดคลาส) – จะเติมเข้า query หลังด้วยสไตล์ -คำ
NEGATIVE_TERMS = ["cartoon", "clipart", "infographic", "diagram", "vector", "mug", "cup", "glass", "toy"]

# รายชื่อคลาส (ต้องตรงกับชื่อโฟลเดอร์)
CLASSES = [
    "กระดาษ","กระดาษลัง","แก้วเก็บความเย็น","ขวดแก้วใส","ขวดพลาสติก",
    "แบตเตอรี่","ผ้า","ไม่ใช่ขยะ","รองเท้า","โลหะกระป๋อง","หลอดไฟ"
]

# Prompt ต่อคลาส (ไทย+อังกฤษ) เพื่อให้ CLIP เข้าใจความหมายคลาส
PROMPTS = {
    "กระดาษ": ["a photo of paper sheets","a stack of a4 paper","white paper","กระดาษ a4"],
    "กระดาษลัง": ["a photo of cardboard box","corrugated cardboard box","brown cardboard box","กล่องลัง"],
    "แก้วเก็บความเย็น": ["a photo of vacuum tumbler","insulated bottle","thermos mug","แก้วเก็บความเย็น"],
    "ขวดแก้วใส": ["a photo of clear glass bottle","transparent glass bottle","ขวดแก้วใส"],
    "ขวดพลาสติก": ["a photo of plastic PET bottle","transparent plastic water bottle","ขวดพลาสติก"],
    "แบตเตอรี่": ["a photo of AA battery","lithium battery cell","battery pack","ถ่านไฟฉาย"],
    "ผ้า": ["a photo of fabric cloth","pile of clothes","towel cloth","เสื้อผ้า"],
    "ไม่ใช่ขยะ": ["a random landscape photo","a cat photo","street view","office desk","food dish"],
    "รองเท้า": ["a photo of shoes","sneakers","leather shoes","รองเท้า"],
    "โลหะกระป๋อง": ["a photo of aluminum can","tin can","soda can","กระป๋องโลหะ"],
    "หลอดไฟ": ["a photo of LED bulb","incandescent bulb","fluorescent tube","หลอดไฟ"]
}

# คำค้นต่อคลาส (มีไทย+อังกฤษ + คำลบ)
SEARCH_QUERIES = {
    "กระดาษ": ["paper sheets", "a4 paper", "white paper", "กระดาษ a4"],
    "กระดาษลัง": ["cardboard box", "corrugated cardboard", "brown cardboard box", "กล่องลัง"],
    "แก้วเก็บความเย็น": ["vacuum tumbler", "insulated bottle", "thermos mug", "แก้วเก็บความเย็น"],
    "ขวดแก้วใส": ["clear glass bottle", "transparent glass bottle", "ขวดแก้วใส"],
    "ขวดพลาสติก": ["plastic bottle", "PET bottle", "water plastic bottle", "ขวดพลาสติก"],
    "แบตเตอรี่": ["AA battery", "battery cell", "lithium battery", "ถ่านไฟฉาย"],
    "ผ้า": ["fabric cloth", "pile of clothes", "towel cloth", "เสื้อผ้า"],
    "ไม่ใช่ขยะ": ["landscape photo", "street view", "office desk", "cat photo", "food dish"],
    "รองเท้า": ["shoes", "sneakers", "leather shoes", "รองเท้า"],
    "โลหะกระป๋อง": ["aluminum can", "tin can", "soda can", "กระป๋องโลหะ"],
    "หลอดไฟ": ["LED bulb", "incandescent bulb", "fluorescent tube", "หลอดไฟ"]
}
# --------------------------------------------

device = "cuda" if torch.cuda.is_available() else "cpu"
model = CLIPModel.from_pretrained("openai/clip-vit-base-patch32").to(device)
processor = CLIPProcessor.from_pretrained("openai/clip-vit-base-patch32")

def clip_score(img: Image.Image, prompts: List[str]) -> float:
    inputs = processor(text=prompts, images=img, return_tensors="pt", padding=True).to(device)
    with torch.no_grad():
        out = model(**inputs)
        probs = out.logits_per_image.softmax(dim=1).squeeze(0)
    return float(torch.max(probs).item())

def valid_min_size(img: Image.Image, min_side: int = 512) -> bool:
    w, h = img.size
    return min(w, h) >= min_side

def hashed_name(url: str, ext: str) -> str:
    h = hashlib.md5(url.encode("utf-8")).hexdigest()
    return f"{h}{ext}"

def ensure_dir(p: Path):
    p.mkdir(parents=True, exist_ok=True)

def add_negatives(q: str) -> str:
    # เติมคำลบแบบ -term ใน query
    neg = " ".join([f"-{t}" for t in NEGATIVE_TERMS])
    return f"{q} {neg}".strip()

# ---------------------- DATA SOURCES ----------------------
def fetch_openverse(q: str, limit=MAX_PER_QUERY):
    # API doc: https://api.openverse.engineering/v1/images/
    url = "https://api.openverse.engineering/v1/images/"
    params = {
        "q": q,
        "page_size": min(limit, 200),
        "license_type": "all",  # หรือ 'cc' ถ้าต้องการ CC เท่านั้น
        "license": "cc0,by,by-sa,by-nc,by-nd,by-nc-sa,by-nc-nd",
        "format": "json"
    }
    r = requests.get(url, params=params, timeout=20)
    r.raise_for_status()
    items = r.json().get("results", [])
    results = []
    for it in items:
        url_full = it.get("url") or it.get("foreign_landing_url") or it.get("thumbnail")
        if not url_full: 
            continue
        # เดาว่าน่าจะเป็น jpg/png
        ext = ".jpg"
        results.append((url_full, ext))
    return results[:limit]

def fetch_pixabay(q: str, limit=MAX_PER_QUERY):
    if not os.getenv("PIXABAY_KEY"):
        return []
    url = "https://pixabay.com/api/"
    params = {
        "key": os.getenv("PIXABAY_KEY"),
        "q": q,
        "image_type": "photo",
        "per_page": min(limit, 200),
        "safesearch": "true",
        "orientation": "all"
    }
    r = requests.get(url, params=params, timeout=20)
    r.raise_for_status()
    hits = r.json().get("hits", [])
    out = []
    for h in hits:
        link = h.get("largeImageURL") or h.get("webformatURL")
        if link:
            ext = ".jpg"
            out.append((link, ext))
    return out[:limit]

def fetch_pexels(q: str, limit=MAX_PER_QUERY):
    if not os.getenv("PEXELS_KEY"):
        return []
    url = "https://api.pexels.com/v1/search"
    headers = {"Authorization": os.getenv("PEXELS_KEY")}
    params = {"query": q, "per_page": min(limit, 80)}
    r = requests.get(url, headers=headers, params=params, timeout=20)
    r.raise_for_status()
    photos = r.json().get("photos", [])
    out = []
    for p in photos:
        link = p.get("src", {}).get("large2x") or p.get("src", {}).get("large")
        if link:
            ext = ".jpg"
            out.append((link, ext))
    return out[:limit]

def fetch_flickr(q: str, limit=MAX_PER_QUERY):
    if not os.getenv("FLICKR_KEY"):
        return []
    url = "https://api.flickr.com/services/rest/"
    params = {
        "method": "flickr.photos.search",
        "api_key": os.getenv("FLICKR_KEY"),
        "text": q,
        "content_type": 1,  # photos only
        "media": "photos",
        "per_page": min(limit, 200),
        "page": 1,
        "safe_search": 1,
        "format": "json",
        "nojsoncallback": 1,
        "license": "0,1,2,3,4,5,6,7,8,9,10",  # รวม CC
        "extras": "url_l,url_c,url_o,url_k,url_h,url_b"
    }
    r = requests.get(url, params=params, timeout=20)
    r.raise_for_status()
    photos = r.json().get("photos", {}).get("photo", [])
    out = []
    for p in photos:
        link = p.get("url_l") or p.get("url_c") or p.get("url_o") or p.get("url_k") or p.get("url_h") or p.get("url_b")
        if link:
            ext = ".jpg"
            out.append((link, ext))
    return out[:limit]

def download_image(url: str) -> Image.Image | None:
    try:
        r = requests.get(url, timeout=25)
        r.raise_for_status()
        data = io.BytesIO(r.content)
        img = Image.open(data).convert("RGB")
        return img
    except Exception:
        return None

# ---------------------- MAIN LOGIC ----------------------
def gather_for_class(cls: str, out_dir: Path, need: int):
    ensure_dir(out_dir)
    count = len([p for p in out_dir.glob("*") if p.suffix.lower() in [".jpg",".jpeg",".png",".webp"]])
    if count >= need:
        return

    prompts = PROMPTS[cls]
    queries = SEARCH_QUERIES[cls][:]
    random.shuffle(queries)

    pbar = tqdm(total=need, initial=count, desc=f"{cls}")
    seen_hashes = set()

    # build existing hashes to avoid duplicates in folder
    for p in out_dir.glob("*"):
        if p.is_file():
            seen_hashes.add(p.name.split(".")[0])

    for q in queries * 10:  # หมุนรอบคำค้นให้เพียงพอ
        if count >= need:
            break
        q_full = add_negatives(q)

        # ดึงจากหลายแหล่ง
        candidates = []
        try:
            if USE_OPENVERSE:
                candidates += fetch_openverse(q_full, limit=MAX_PER_QUERY)
        except Exception:
            pass
        try:
            if USE_PIXABAY:
                candidates += fetch_pixabay(q_full, limit=MAX_PER_QUERY)
        except Exception:
            pass
        try:
            if USE_PEXELS:
                candidates += fetch_pexels(q_full, limit=MAX_PER_QUERY)
        except Exception:
            pass
        try:
            if USE_FLICKR:
                candidates += fetch_flickr(q_full, limit=MAX_PER_QUERY)
        except Exception:
            pass

        random.shuffle(candidates)

        for url, ext in candidates:
            if count >= need:
                break

            img = download_image(url)
            if img is None:
                continue
            if not valid_min_size(img, MIN_SIZE):
                continue

            # ให้คะแนนความเกี่ยวข้องด้วย CLIP
            score = clip_score(img, prompts)
            if score < SCORE_THRESH:
                continue  # ทิ้ง ทันที

            # เซฟ
            fname = hashed_name(url, ext)
            if fname in seen_hashes:
                continue
            seen_hashes.add(fname)
            out_path = out_dir / fname
            try:
                img.save(out_path, format="JPEG", quality=92, optimize=True)
                count += 1
                pbar.update(1)
            except Exception:
                continue

    pbar.close()

def main():
    Path(OUT_DIR).mkdir(parents=True, exist_ok=True)
    for cls in CLASSES:
        target_dir = Path(OUT_DIR) / cls
        gather_for_class(cls, target_dir, PER_CLASS)

if __name__ == "__main__":
    main()
