# load_openimages_to_folders.py
import os, random, shutil
from pathlib import Path
from typing import List, Dict
from PIL import Image
import torch
from transformers import CLIPProcessor, CLIPModel
import fiftyone as fo
import fiftyone.zoo as foz

# ---------------- CONFIG ----------------
OUT_ROOT = str(Path(__file__).resolve().parents[2] / "data" / "prepared")   # ปลายทางที่มี train/ และ val/
TRAIN_TARGET_PER_CLASS = 5000                         # ต้องการ "ต้นฉบับ" ต่อคลาส
VAL_TARGET_PER_CLASS   = 800                          # จำนวนสำหรับ val (ห้าม augment)
MIN_SIDE = 512                                         # ขนาดด้านสั้นขั้นต่ำ
CLIP_THRESH_TRAIN = 0.30                               # เกณฑ์คัดรูปให้เข้ม-อ่อน (train)
CLIP_THRESH_VAL   = 0.32                               # val เข้มกว่านิดเพื่อให้สะอาด
RANDOM_SEED = 42

# ชื่อคลาสไทย (ต้องตรงชื่อโฟลเดอร์ของคุณ)
TH_CLASSES = [
    "กระดาษ","กระดาษลัง","แก้วเก็บความเย็น","ขวดแก้วใส","ขวดพลาสติก",
    "แบตเตอรี่","ผ้า","ไม่ใช่ขยะ","รองเท้า","โลหะกระป๋อง","หลอดไฟ"
]

# 1) แมป "คลาสไทย" -> "คลาส Open Images ที่ใกล้เคียง" เพื่อดึงมาก่อน
# ใช้ image-level labels กว้าง ๆ แล้วค่อยให้ CLIP คัดให้ตรงภายหลัง
OI_CLASS_MAP: Dict[str, List[str]] = {
    "โลหะกระป๋อง": ["Tin can", "Aluminum can", "Canned food"],   # มีใน OI
    "ขวดแก้วใส":   ["Glass bottle", "Wine bottle", "Beer bottle", "Bottle"],  # ดาวน์โหลดกว้าง แล้ว CLIP เลือก 'glass'
    "ขวดพลาสติก": ["Water bottle", "Bottle"],                     # ดาวน์โหลดกว้าง แล้ว CLIP เลือก 'plastic/PET'
    "รองเท้า":     ["Shoe"],                                       # มีใน OI
    "หลอดไฟ":      ["Light bulb"],                                 # มีใน OI
    "แบตเตอรี่":   ["Battery (electricity)"],                      # ชื่อคลาสใน OI ใกล้เคียงนี้
    "กระดาษลัง":   ["Cardboard box"],                              # มีใน OI
    "ผ้า":         ["Clothing", "Fabric"],                          # ดึงกว้าง แล้ว CLIP คัด 'cloth/fabric/clothes'
    "กระดาษ":      ["Paper", "Stationery", "Document"],            # ใช้ image-level labels ใกล้เคียง
    "แก้วเก็บความเย็น": ["Thermos", "Flask", "Water bottle"],      # ไม่มี 'tumbler' ตรงตัว ดึงใกล้เคียงแล้วให้ CLIP คัด
    "ไม่ใช่ขยะ":    ["Cat", "Dog", "Tree", "Flower", "Car", "Person", "Landscape (outdoor)", "Food", "Office"],  # negative class
}

# 2) Prompt ภาษา/คำอธิบายที่ใช้ให้ CLIP ตัดสิน "เข้าคลาสไทย" จริงไหม
CLIP_PROMPTS: Dict[str, List[str]] = {
    "โลหะกระป๋อง": ["a photo of aluminum tin can", "metal soda can", "อาหารกระป๋อง"],
    "ขวดแก้วใส":   ["a photo of clear glass bottle", "transparent glass beverage bottle", "ขวดแก้วใส"],
    "ขวดพลาสติก": ["a photo of plastic PET bottle", "transparent plastic water bottle", "ขวดพลาสติก"],
    "รองเท้า":     ["a photo of shoes", "sneakers", "leather shoes", "รองเท้า"],
    "หลอดไฟ":      ["a photo of LED bulb", "incandescent bulb", "fluorescent tube", "หลอดไฟ"],
    "แบตเตอรี่":   ["a photo of AA battery", "lithium battery cell", "battery pack", "ถ่านไฟฉาย"],
    "กระดาษลัง":   ["a photo of cardboard box", "corrugated cardboard box", "กล่องลัง"],
    "ผ้า":         ["a photo of fabric cloth", "pile of clothes", "towel cloth", "เสื้อผ้า ผ้า"],
    "กระดาษ":      ["a photo of paper sheets", "a4 paper stack", "white office paper", "กระดาษ a4"],
    "แก้วเก็บความเย็น": ["a photo of vacuum tumbler", "insulated bottle", "thermos mug", "แก้วเก็บความเย็น"],
    "ไม่ใช่ขยะ":    ["a random landscape photo", "a cat photo", "street view", "office desk", "food dish"],
}

# ---------------------------------------

def _ensure_dir(p: Path):
    p.mkdir(parents=True, exist_ok=True)

def _good_size(img: Image.Image, min_side: int) -> bool:
    w, h = img.size
    return min(w, h) >= min_side

# CLIP
device = "cuda" if torch.cuda.is_available() else "cpu"
clip_model = CLIPModel.from_pretrained("openai/clip-vit-base-patch32").to(device)
clip_proc  = CLIPProcessor.from_pretrained("openai/clip-vit-base-patch32")

def clip_score(img: Image.Image, prompts: List[str]) -> float:
    inputs = clip_proc(text=prompts, images=img, return_tensors="pt", padding=True).to(device)
    with torch.no_grad():
        out = clip_model(**inputs)
        probs = out.logits_per_image.softmax(dim=1).squeeze(0)
    return float(torch.max(probs).item())

def export_samples(samples, dst_dir: Path, cls_th: str, need: int, thresh: float):
    saved = 0
    _ensure_dir(dst_dir / cls_th)
    for s in samples:
        if saved >= need:
            break
        # โหลดรูปจริง
        fp = s.filepath
        try:
            img = Image.open(fp).convert("RGB")
        except Exception:
            continue
        if not _good_size(img, MIN_SIDE):
            continue
        # ให้ CLIP ตัดสินความเข้าคลาสไทยจริงไหม
        score = clip_score(img, CLIP_PROMPTS[cls_th])
        if score < thresh:
            continue
        # เซฟ copy เข้าคลาสไทย
        out_dir = dst_dir / cls_th
        _ensure_dir(out_dir)
        # ตั้งชื่อด้วย id ของ OI + คำสุ่ม
        base = Path(fp).stem
        out_path = out_dir / f"{base}_{random.randint(10000,99999)}.jpg"
        try:
            img.save(out_path, format="JPEG", quality=92, optimize=True)
            saved += 1
        except Exception:
            pass
    return saved

def main():
    random.seed(RANDOM_SEED)

    # เตรียมปลายทาง
    for split in ["train","val"]:
        for c in TH_CLASSES:
            _ensure_dir(Path(OUT_ROOT)/split/c)

    # วนทีละ "คลาสไทย"
    for cls_th in TH_CLASSES:
        approx_oi_classes = OI_CLASS_MAP[cls_th]

        # ใช้ FiftyOne โหลดชุดภาพจาก Open Images V7 เฉพาะคลาสที่ใกล้เคียง
        # ใช้ image-level labels (classifications) เพื่อโหลดเร็วและหลากหลาย
        # เราจะโหลดมากกว่าที่ต้องการเล็กน้อยเพื่อเผื่อโดน CLIP ตัดทิ้ง
        want_train = int(TRAIN_TARGET_PER_CLASS * 1.7)
        want_val   = int(VAL_TARGET_PER_CLASS   * 1.7)

        # ----- TRAIN -----
        ds_tr = foz.load_zoo_dataset(
            "open-images-v7",
            split="train",
            label_types=["classifications"],
            classes=approx_oi_classes,
            max_samples=want_train,
            shuffle=True,
        )

        # ----- VAL -----
        ds_va = foz.load_zoo_dataset(
            "open-images-v7",
            split="validation",
            label_types=["classifications"],
            classes=approx_oi_classes,
            max_samples=want_val,
            shuffle=True,
        )

        # ส่งออกเข้าโฟลเดอร์ โดยให้ CLIP คัดอีกชั้น
        train_saved = export_samples(
            ds_tr, Path(OUT_ROOT)/"train", cls_th, TRAIN_TARGET_PER_CLASS, CLIP_THRESH_TRAIN
        )
        val_saved   = export_samples(
            ds_va, Path(OUT_ROOT)/"val",   cls_th, VAL_TARGET_PER_CLASS,   CLIP_THRESH_VAL
        )

        print(f"[{cls_th}] saved train={train_saved}, val={val_saved}")

        # cleanup FiftyOne datasets (ประหยัดดิสก์)
        fo.delete_dataset(ds_tr.name)
        fo.delete_dataset(ds_va.name)

if __name__ == "__main__":
    main()
