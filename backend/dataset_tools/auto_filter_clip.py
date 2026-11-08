import os, shutil, math
from pathlib import Path
from PIL import Image
import torch
from torchvision import transforms
from transformers import CLIPProcessor, CLIPModel
from tqdm import tqdm

# พาธรากของคุณ
BASE = str(Path(__file__).resolve().parents[2] / "data" / "prepared")  # มี train/ และ val/ ภายใน
SETS = ["train", "val"]  # จะคัดทั้งสองชุด ถ้าอยากคัดเฉพาะ train ก็แก้ได้

# รายชื่อคลาส (ต้องตรงชื่อโฟลเดอร์)
CLASSES = [
    "กระดาษ","กระดาษลัง","แก้วเก็บความเย็น","ขวดแก้วใส","ขวดพลาสติก",
    "แบตเตอรี่","ผ้า","ไม่ใช่ขยะ","รองเท้า","โลหะกระป๋อง","หลอดไฟ"
]

# prompt ต่อคลาส (ไทย+อังกฤษ) — ปรับ/เพิ่มคำช่วยแยกแยะได้
PROMPTS = {
    "กระดาษ": [
        "a photo of paper sheets", "a4 paper stack", "flat white paper", "กระดาษ a4", "กระดาษสีขาว"
    ],
    "กระดาษลัง": [
        "a photo of cardboard box", "corrugated box", "brown cardboard", "กล่องลัง", "กระดาษลูกฟูก"
    ],
    "แก้วเก็บความเย็น": [
        "a photo of vacuum tumbler", "insulated bottle", "thermos mug", "แก้วเก็บความเย็น", "ขวดเก็บอุณหภูมิ"
    ],
    "ขวดแก้วใส": [
        "a photo of clear glass bottle", "transparent glass bottle", "ขวดแก้วใส", "glass beverage bottle"
    ],
    "ขวดพลาสติก": [
        "a photo of plastic PET bottle", "transparent plastic bottle", "ขวดพลาสติก", "water plastic bottle"
    ],
    "แบตเตอรี่": [
        "a photo of AA battery", "battery pack", "ถ่านไฟฉาย", "lithium battery"
    ],
    "ผ้า": [
        "a photo of fabric cloth", "pile of clothes", "เสื้อผ้า", "ผ้าขนหนู", "folded towel"
    ],
    "ไม่ใช่ขยะ": [
        "a random landscape photo", "a cat photo", "street view", "office desk", "food dish"
    ],
    "รองเท้า": [
        "a photo of shoes", "sneakers", "leather shoes", "รองเท้า"
    ],
    "โลหะกระป๋อง": [
        "a photo of aluminum can", "tin can", "soda can", "กระป๋องโลหะ"
    ],
    "หลอดไฟ": [
        "a photo of LED bulb", "incandescent bulb", "fluorescent tube", "หลอดไฟ"
    ],
}

# เกณฑ์ตัดสิน (เริ่มที่ 0.27 แล้วค่อยปรับ)
THRESH = 0.27

device = "cuda" if torch.cuda.is_available() else "cpu"
model = CLIPModel.from_pretrained("openai/clip-vit-base-patch32").to(device)
processor = CLIPProcessor.from_pretrained("openai/clip-vit-base-patch32")

def score_image_against_prompts(img: Image.Image, text_prompts):
    inputs = processor(text=text_prompts, images=img, return_tensors="pt", padding=True).to(device)
    with torch.no_grad():
        out = model(**inputs)
        logits = out.logits_per_image.softmax(dim=1).squeeze(0)  # probs
    # คืนคะแนนสูงสุด และ index
    best_score, best_idx = torch.max(logits, dim=0)
    return float(best_score), int(best_idx)

def ensure_dir(p: Path):
    p.mkdir(parents=True, exist_ok=True)

def main():
    for s in SETS:
        for cls in CLASSES:
            cls_dir = Path(BASE) / s / cls
            if not cls_dir.exists(): 
                continue

            rejects_dir = Path(BASE) / f"{s}_rejects" / cls
            ensure_dir(rejects_dir)

            # เตรียม prompt รวมทุกคลาส สำหรับ “โหวตย้ายคลาส”
            all_cls_names = CLASSES
            all_prompts_flat = []
            for cname in all_cls_names:
                all_prompts_flat += PROMPTS[cname][:2]  # เอา 1–2 prompt ที่ชัดที่สุดต่อคลาส

            files = [p for p in cls_dir.glob("*") if p.suffix.lower() in [".jpg",".jpeg",".png",".webp"]]

            for fp in tqdm(files, desc=f"[{s}] {cls}"):
                try:
                    img = Image.open(fp).convert("RGB")
                except Exception:
                    # เปิดไม่ได้ = ทิ้ง
                    shutil.move(str(fp), str((rejects_dir / fp.name)))
                    continue

                # 1) ตรวจว่า “เข้ากับคลาสปัจจุบัน” ไหม
                my_prompts = PROMPTS[cls]
                my_score, _ = score_image_against_prompts(img, my_prompts)

                if my_score >= THRESH:
                    continue  # ผ่าน

                # 2) ไม่ผ่าน → ลองจัดเข้าคลาสที่เหมาะกว่า
                all_score, best_idx = score_image_against_prompts(img, all_prompts_flat)
                # คำนวณ mapping จาก index -> ชื่อคลาส
                # เพราะเราเอา 2 prompt แรกของแต่ละคลาสมาต่อกัน
                best_class = all_cls_names[best_idx // 2]  # 2 prompt ต่อคลาส

                if all_score >= THRESH and best_class != cls:
                    # ย้ายเข้าคลาสใหม่
                    dest = Path(BASE) / s / best_class / fp.name
                    ensure_dir(dest.parent)
                    shutil.move(str(fp), str(dest))
                else:
                    # คะแนนไม่พอ → โยนใส่ rejects เพื่อตรวจมือ
                    shutil.move(str(fp), str((rejects_dir / fp.name)))

if __name__ == "__main__":
    main()
