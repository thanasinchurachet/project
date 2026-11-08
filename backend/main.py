import os, io, json
from pathlib import Path
from typing import List, Tuple, Optional, Dict, Set, Any

from fastapi import FastAPI, UploadFile, File, HTTPException, Query, Form, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from fastapi.responses import Response
from pydantic import BaseModel
import threading
import re

from PIL import Image, ImageFile
ImageFile.LOAD_TRUNCATED_IMAGES = True  # กันรูปเสียบางส่วน

import torch
import torch.nn as nn
import torch.nn.functional as F
from torchvision import transforms

import requests
import math
import sys
import time
import shutil
import subprocess
from zipfile import ZipFile
import os, sys, time, json, subprocess, shutil
from pathlib import Path
from fastapi import Form, HTTPException
import base64
try:
    import mysql.connector as mysql_connector
    from mysql.connector import Error as MySQLError
except Exception:
    mysql_connector = None
    class MySQLError(Exception):
        pass

# =========================
# .env loader (simple) + Paths / ENV
# =========================
def _load_env_from_file(path: Path):
    try:
        if not path.exists():
            return
        for raw in path.read_text(encoding="utf-8").splitlines():
            line = raw.strip()
            if not line or line.startswith("#"):
                continue
            if "=" not in line:
                continue
            k, v = line.split("=", 1)
            k = k.strip()
            v = v.strip().strip('"\'')
            if k and v and (os.getenv(k) is None):
                os.environ[k] = v
    except Exception:
        pass

BASE_DIR = Path(__file__).resolve().parents[1]              # .../project
# try load env from backend/.env then project .env (non-fatal)
_load_env_from_file(BASE_DIR / "backend" / ".env")
_load_env_from_file(BASE_DIR / ".env")
MODELS_DIR = Path(os.getenv("MODELS_DIR", BASE_DIR / "models"))
# Default to the new v2 ConvNeXt-Tiny weights unless overridden by env
WEIGHTS_PATH = Path(os.getenv("WEIGHTS_PATH", MODELS_DIR / "v2" / "best.pt"))

# Paths for training pipeline (can be overridden by ENV)
TRAIN_SCRIPT = Path(os.getenv("TRAIN_SCRIPT", BASE_DIR / "backend" / "train.py"))
DATA_ROOT    = Path(os.getenv("DATA_ROOT",    BASE_DIR / "data" / "prepared"))
ADMIN_TOKEN  = os.getenv("ADMIN_TOKEN", "changeme")
N8N_WEBHOOK_URL = os.getenv("N8N_WEBHOOK_URL", "").strip()
# Optional extra CLI args for train.py, e.g. "--arch convnextv2_large" or "--model convnextv2_large"
TRAIN_EXTRA_ARGS = os.getenv("TRAIN_EXTRA_ARGS", "").strip()

UNSURE_ENABLED = os.getenv("UNSURE_ENABLED", "1") == "1"     # เปิดโหมด "ไม่แน่ใจ"
PROB_TH = float(os.getenv("PROB_TH", "0.65"))
MARGIN  = float(os.getenv("MARGIN", "0.15"))
TOPK_DEFAULT = int(os.getenv("TOPK", "3"))

GOOGLE_MAPS_API_KEY = os.getenv("GOOGLE_MAPS_API_KEY", "").strip()   # set in .env

DEVICE = torch.device("cuda" if torch.cuda.is_available() else "cpu")

# =========================
# FastAPI app
# =========================
app = FastAPI(title="Waste Classifier API (PyTorch)", version="1.0.0")

# CORS (ถ้าเรียกจากเว็บ/โดเมนอื่น)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # โปรดจำกัดในโปรดักชัน
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Log ทุกคำขอ—ช่วยดีบักหน้าเว็บค้าง
@app.middleware("http")
async def log_requests(request, call_next):
    print(f">>> {request.method} {request.url}")
    resp = await call_next(request)
    print(f"<<< {resp.status_code} {request.url}")
    return resp


# =========================
# Loader (PyTorch)
# =========================
def _load_torch_model(weights_path: str, device: torch.device):
    """โหลดโมเดล (timm) + transforms + ชื่อคลาส โดยพยายามเดา architecture ให้ตรงกับ checkpoint

    รองรับ ckpt ที่เป็น:
    - { 'model_state': ..., 'num_classes': N, 'img_size': S, 'idx_to_class': {...}, 'arch'?: 'convnext_large' }
    - { 'state_dict': ... }
    - หรือ state_dict ตรง ๆ
    ต้องมี idx_to_class ใน ckpt หรือไฟล์ labels.json ข้าง ๆ .pt
    """
    weights_path = str(weights_path)
    if not os.path.exists(weights_path):
        raise RuntimeError(f"ไม่พบโมเดล: {weights_path}")

    ckpt = torch.load(weights_path, map_location=device)

    # ดึง state_dict + meta
    state = None
    num_classes = None
    img_size = 224
    idx_to_class = None
    arch_hint = None

    if isinstance(ckpt, dict) and "model_state" in ckpt:
        state = ckpt["model_state"]
        num_classes = ckpt.get("num_classes")
        img_size = ckpt.get("img_size", 224)
        idx_to_class = ckpt.get("idx_to_class")
    elif isinstance(ckpt, dict) and "state_dict" in ckpt:
        state = ckpt["state_dict"]
        num_classes = ckpt.get("num_classes")
        img_size = ckpt.get("img_size", 224)
        idx_to_class = ckpt.get("idx_to_class")
    else:
        # assume เป็น state_dict โดยตรง
        state = ckpt if isinstance(ckpt, dict) else None
        if state is None:
            raise RuntimeError("รูปแบบ checkpoint ไม่รองรับ (คาดหวัง dict/state_dict)")

    # พยายามอ่านสัญญาณ architecture จาก ckpt ถ้ามี (เช่น 'arch', 'model_name', ฯลฯ)
    if isinstance(ckpt, dict):
        for key in ("arch", "model", "model_name", "arch_name", "backbone", "backbone_name"):
            v = ckpt.get(key)
            if isinstance(v, str) and v.strip():
                arch_hint = v.strip()
                break

    # อ่าน labels.json ถ้าไม่ได้มากับ ckpt
    if idx_to_class is None:
        labels_json = Path(weights_path).parent / "labels.json"
        if not labels_json.exists():
            raise RuntimeError("ไม่พบ labels.json และไม่มี idx_to_class ในไฟล์โมเดล")
        idx_to_class = json.loads(labels_json.read_text(encoding="utf-8"))

    # สรุปจำนวนคลาส
    if num_classes is None and isinstance(idx_to_class, dict):
        try:
            idx2class_tmp = {int(k): v for k, v in idx_to_class.items()}
            num_classes = len(idx2class_tmp)
        except Exception:
            raise RuntimeError("ไม่สามารถระบุจำนวนคลาสจาก idx_to_class ได้")

    # สร้างโมเดลด้วย timm โดยเลือกสถาปัตยกรรมให้เข้ากับ checkpoint
    try:
        import timm
    except Exception as e:
        raise RuntimeError("ต้องติดตั้งแพ็กเกจ 'timm' เพื่อใช้งาน ConvNeXt‑Tiny (pip install timm)") from e

    def _build_model_for_state(n_classes: int, st: dict, hint: Optional[str] = None):
        # จัดลำดับ candidate: ให้ความสำคัญกับ hint ก่อน
        archs: list[str] = []
        if hint:
            archs.append(hint)
        # เผื่อมีการตั้งค่าผ่าน ENV ระบุ candidates เอง (คั่นด้วยช่องว่าง)
        extra = os.getenv("LOAD_ARCH_CANDIDATES", "").strip()
        if extra:
            archs += [a for a in extra.split() if a]
        # ค่ามาตรฐานที่รองรับในโปรเจ็กต์นี้
        archs += [
            "convnext_tiny", "convnext_small", "convnext_base", "convnext_large",
            "convnextv2_tiny", "convnextv2_small", "convnextv2_base", "convnextv2_large",
            "resnet18", "resnet34", "resnet50", "resnet101",
        ]
        # ตัดซ้ำ แต่คงลำดับเดิม
        seen = set()
        candidates = []
        for a in archs:
            if a not in seen:
                candidates.append(a)
                seen.add(a)

        best = None
        best_name = None
        best_m = -1
        best_msd = None
        for name in candidates:
            try:
                m = timm.create_model(name, pretrained=False, num_classes=int(n_classes))
                msd = m.state_dict()
                # นับจำนวน key ที่ชื่อและ shape ตรงกัน เพื่อวัดความเข้ากันได้
                match = 0
                for k, v in st.items():
                    if k in msd:
                        try:
                            if getattr(v, 'shape', None) == msd[k].shape:
                                match += 1
                        except Exception:
                            pass
                if match > best_m:
                    best = m
                    best_name = name
                    best_m = match
                    best_msd = msd
            except Exception:
                continue

        if best is None or best_msd is None:
            raise RuntimeError("ไม่พบสถาปัตยกรรม timm ที่เข้ากับ checkpoint นี้")

        # กรอง state เฉพาะ key ที่ shape ตรงกันเพื่อเลี่ยง error ขณะโหลด
        filtered = {}
        for k, v in st.items():
            if k in best_msd:
                try:
                    if getattr(v, 'shape', None) == best_msd[k].shape:
                        filtered[k] = v
                except Exception:
                    pass
        try:
            best.load_state_dict(filtered, strict=False)
        except Exception:
            # อนุโลม: ถ้าโหลดบางส่วนไม่ได้ ให้ไปต่อด้วยน้ำหนักที่ลงได้
            pass
        return best, best_name

    model, chosen_arch = _build_model_for_state(int(num_classes), state, arch_hint)

    # เคลียร์ prefix ที่มักติดมาจาก DDP/Lightning: 'module.' หรือ 'model.'
    def _clean_state_dict(s: dict) -> dict:
        cleaned = {}
        for k, v in s.items():
            if k.startswith("module."):
                k = k[7:]
            if k.startswith("model."):
                k = k[6:]
            cleaned[k] = v
        return cleaned

    state = _clean_state_dict(state)

    model.eval().to(device)

    # transforms (ImageNet mean/std + ขนาดจาก ckpt ถ้ามี)
    tf = transforms.Compose([
        transforms.Resize((img_size, img_size)),
        transforms.ToTensor(),
        transforms.Normalize(mean=[0.485, 0.456, 0.406], std=[0.229, 0.224, 0.225]),
    ])

    idx2class = {int(k): v for k, v in idx_to_class.items()}
    class_names = [idx2class[i] for i in range(len(idx2class))]
    return model, tf, class_names, img_size


# จะเซ็ตค่าจริงใน startup
MODEL = None
TF = None
CLASS_NAMES: List[str] = []
IMG_SIZE = 256
MODEL_LAST_LOADED = None

@app.on_event("startup")
def _startup():
    """โหลดโมเดลตอนแอปเริ่มทำงาน (ปลอดภัยกับ --reload)"""
    global MODEL, TF, CLASS_NAMES, IMG_SIZE, WEIGHTS_PATH
    # Load persisted active model path if available
    try:
        persisted = _read_persisted_active_path()
    except Exception:
        persisted = None
    if persisted:
        try:
            print(f"[startup] found persisted active: {persisted}")
        except Exception:
            pass
        WEIGHTS_PATH = persisted
    print(f"[startup] loading model from: {WEIGHTS_PATH}")
    MODEL, TF, CLASS_NAMES, IMG_SIZE = _load_torch_model(str(WEIGHTS_PATH), DEVICE)
    print(f"[startup] model ready: classes={len(CLASS_NAMES)}, img_size={IMG_SIZE}, device={DEVICE}")
    global MODEL_LAST_LOADED
    MODEL_LAST_LOADED = str(WEIGHTS_PATH)


# =========================
# Utils
IMG_EXTS = {".jpg", ".jpeg", ".png", ".bmp", ".gif", ".webp"}

def _is_image_file(p: Path) -> bool:
    return p.is_file() and p.suffix.lower() in IMG_EXTS

# Persist the currently active model weights path so it survives restarts
ACTIVE_PATH_FILE = MODELS_DIR / "_active.txt"

def _persist_active_path(p: Path) -> None:
    try:
        MODELS_DIR.mkdir(parents=True, exist_ok=True)
        ACTIVE_PATH_FILE.write_text(str(p), encoding="utf-8")
    except Exception:
        # non-fatal
        pass

def _read_persisted_active_path() -> Optional[Path]:
    try:
        if ACTIVE_PATH_FILE.exists():
            t = ACTIVE_PATH_FILE.read_text(encoding="utf-8").strip()
            if t:
                q = Path(t)
                if q.exists():
                    return q
    except Exception:
        pass
    return None

def _verify_trainval_structure(root: Path) -> dict:
    """
    ตรวจว่ามีโครงสร้าง:
      root/train/<class>/*   และ   root/val/<class>/*
    และต้องมีอย่างน้อย 2 คลาสที่ซ้ำกันทั้ง train/val
    """
    tdir = root / "train"
    vdir = root / "val"
    if not tdir.exists() or not vdir.exists():
        raise HTTPException(400, detail=f"ไม่พบโฟลเดอร์ train/val ใต้ {root}")

    classes_t = sorted([d.name for d in tdir.iterdir() if d.is_dir()])
    classes_v = sorted([d.name for d in vdir.iterdir() if d.is_dir()])
    common = [c for c in classes_t if c in classes_v]
    if len(common) < 2:
        raise HTTPException(400, detail=f"ต้องมีอย่างน้อย 2 คลาสที่ซ้ำกันทั้ง train และ val (เจอ: {common})")

    def _count(d: Path) -> int:
        return sum(1 for p in d.rglob("*") if _is_image_file(p))

    return {
        "root": str(root),
        "classes": common,
        "counts": {
            "train": {c: _count(tdir / c) for c in common},
            "val":   {c: _count(vdir / c) for c in common},
        }
    }
# =========================
def _softmax_topk(img_pil: Image.Image, topk: int = TOPK_DEFAULT) -> List[Tuple[str, float]]:
    x = TF(img_pil.convert("RGB")).unsqueeze(0).to(DEVICE)
    with torch.no_grad():
        logits = MODEL(x)
        probs = F.softmax(logits, dim=1)[0]
    k = min(topk, probs.numel())
    topk_prob, topk_idx = torch.topk(probs, k=k)
    topk_prob = topk_prob.cpu().tolist()
    topk_idx = topk_idx.cpu().tolist()
    return [(CLASS_NAMES[i], float(p)) for i, p in zip(topk_idx, topk_prob)]


def _get_category(waste_type: str) -> str:
    if waste_type == "ไม่แน่ใจ":
        return "ไม่แน่ใจ"
    if waste_type == "ไม่ใช่ขยะ":
        return "ไม่ใช่ขยะ"
    recycle = {"กระดาษ", "กระดาษลัง", "โลหะกระป๋อง", "ผ้า", "ขวดแก้วใส", "พลาสติก"}
    hazard  = {"แบตเตอรี่", "หลอดไฟ"}
    if waste_type in recycle:
        return "ขยะรีไซเคิล"
    if waste_type in hazard:
        return "ขยะอันตราย"
    return "ขยะทั่วไป"


def _haversine_km(lat1, lon1, lat2, lon2):
    R = 6371.0
    p1 = math.radians(lat1)
    p2 = math.radians(lat2)
    dlat = math.radians(lat2 - lat1)
    dlon = math.radians(lon2 - lon1)
    a = math.sin(dlat/2)**2 + math.cos(p1) * math.cos(p2) * math.sin(dlon/2)**2
    c = 2 * math.atan2(math.sqrt(a), math.sqrt(1-a))
    return R * c


def _load_centers():
    centers_path = BASE_DIR / "centers.json"
    if centers_path.exists():
        try:
            return json.loads(centers_path.read_text(encoding="utf-8"))
        except Exception:
            pass
    # fallback ตัวอย่าง
    return [
        {"name": "ศูนย์รีไซเคิลตัวอย่าง A", "lat": 13.7563, "lng": 100.5018, "type": "recycle", "address": "Bangkok"},
        {"name": "จุดรับขยะอันตราย B", "lat": 13.7367, "lng": 100.5232, "type": "hazard", "address": "Bangkok"},
    ]

CENTERS = _load_centers()

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "localhost"),
    "user": os.getenv("DB_USER", "root"),
    "password": os.getenv("DB_PASS", ""),
    "database": os.getenv("DB_NAME", "waste_system"),
    "port": int(os.getenv("DB_PORT", "3306")),
}
CACHE_TTL_SECONDS = 300
KEYWORD_CACHE: dict[str, set[str]] = {}
KEYWORD_CACHE_TS = 0.0
CENTER_CACHE: list[dict] = []
CENTER_CACHE_TS = 0.0


def _resolve_waste_tag(raw: Optional[str]) -> str:
    text = (raw or "").strip().lower()
    if not text or text in {"all", "ทั้งหมด"}:
        return "all"
    if "รีไซ" in text or "recycle" in text:
        return "recycle"
    if "อันตราย" in text or "hazard" in text or "พิษ" in text or "toxic" in text:
        return "hazardous"
    if "ทั่วไป" in text or "general" in text:
        return "general"
    return "all"

KEYWORD_PATTERNS = {
    "recycle": (
        "ขยะ", "รีไซ", "reuse", "recycle", "รับซื้อ", "เศษ", "ของเก่า",
        "ศูนย์", "คัดแยก", "พลาสติก", "กระดาษ", "แก้ว", "โลหะ",
    ),
    # ย้อนกลับเป็นค่าเดิม แต่จะแก้ตรรกะคัดกรองใน _is_relevant แทน
    "hazardous": (
        "ขยะ", "อันตราย", "พิษ", "ของเสีย", "สาร", "เคมี", "เวช", "medical",
        "hazard", "hazardous", "toxic", "danger", "dispose", "กำจัด",
        "แบต", "battery", "ถ่าน", "fluor", "fluorescent", "หลอด",
        "สารพิษ", "สารปนเปื้อน", "อุตสาหกรรม", "โรงงาน",
    ),
    "general": (
        "ขยะ", "กำจัด", "ทิ้ง", "ศูนย์", "สถานี", "สถานที่",
        "ศูนย์บริหาร", "รวบรวม", "ศูนย์จัดการ", "waste", "เทศบาล",
    ),
}

def _should_use_keyword(tag: str, word: str) -> bool:
    w = (word or "").strip().lower()
    if not w or len(w) < 3:
        return False
    allow_tokens = KEYWORD_PATTERNS.get(tag, ())
    return any(token in w for token in allow_tokens)

def _add_keyword(store: dict[str, set[str]], tag: str, word: str) -> None:
    if tag == "all":
        return
    w = (word or "").strip()
    if not _should_use_keyword(tag, w):
        return
    store.setdefault(tag, set()).add(w.lower())

def _default_keywords() -> dict[str, set[str]]:
    raw = {
        "recycle": [
            "ศูนย์รีไซเคิล",
            "สถานีรีไซเคิล",
            "ร้านรับซื้อของเก่า",
            "รับซื้อขวดพลาสติก",
            "รับซื้อเศษโลหะ",
            "รับซื้อกระดาษ",
            "recycling center",
            "waste recycle",
        ],
        "hazardous": [
            "ศูนย์ขยะอันตราย",
            "จุดรับขยะอันตราย",
            "ศูนย์รับ e-waste",
            "กำจัดของเสียอันตราย",
            "กำจัดสารพิษ",
            "กำจัดสารเคมี",
            "รับหลอดไฟ",
            "รับแบตเตอรี่",
            "hazardous waste center",
            "hazardous waste disposal",
            "toxic waste disposal",
            "medical waste disposal",
        ],
        "general": [
            "ขยะทั่วไป",
            "ศูนย์บริหารจัดการขยะ",
            "สถานที่กำจัดขยะ",
            "จุดทิ้งขยะ",
            "สถานีขนถ่ายขยะ",
            "municipal waste",
        ],
    }
    result = {"recycle": set(), "hazardous": set(), "general": set()}
    for tag, words in raw.items():
        for w in words:
            _add_keyword(result, tag, w)
    return result


@app.get("/debug/keywords")
def debug_keywords(refresh: bool = False):
    return _get_keywords(force_refresh=refresh)

def _open_db_connection():
    if mysql_connector is None:
        return None
    try:
        return mysql_connector.connect(charset="utf8mb4", use_unicode=True, **DB_CONFIG)
    except MySQLError as exc:
        print(f"[db] connect failed: {exc}")
        return None


def _get_keywords(force_refresh: bool = False) -> dict[str, set[str]]:
    global KEYWORD_CACHE, KEYWORD_CACHE_TS
    now = time.time()
    if KEYWORD_CACHE and not force_refresh and (now - KEYWORD_CACHE_TS) < CACHE_TTL_SECONDS:
        return KEYWORD_CACHE

    keywords = _default_keywords()
    
    conn = _open_db_connection()
    if conn is None:
        KEYWORD_CACHE = keywords
        KEYWORD_CACHE_TS = now
        return keywords

    cur = None
    try:
        cur = conn.cursor()
        cur.execute("SELECT Waste_type, name FROM trash WHERE TRIM(COALESCE(name,'')) <> ''")
        for waste_type, name in cur.fetchall():
            tag = _resolve_waste_tag(waste_type)
            _add_keyword(keywords, tag, name)
        cur.close()

        cur = conn.cursor()
        cur.execute("SELECT waste_type, label FROM waste_labels WHERE TRIM(COALESCE(label,'')) <> ''")
        for waste_type, label in cur.fetchall():
            tag = _resolve_waste_tag(waste_type)
            _add_keyword(keywords, tag, label)
            if tag != "all":
                keywords.setdefault(tag, set()).add(str(label).strip().lower())
    except MySQLError as exc:
        print(f"[keywords] failed to load from db: {exc}")
    finally:
        if cur is not None:
            try:
                cur.close()
            except Exception:
                pass
        conn.close()

    KEYWORD_CACHE = keywords
    KEYWORD_CACHE_TS = now
    return keywords


def _load_centers_from_db(force_refresh: bool = False) -> list[dict]:
    global CENTER_CACHE, CENTER_CACHE_TS
    now = time.time()
    if CENTER_CACHE and not force_refresh and (now - CENTER_CACHE_TS) < CACHE_TTL_SECONDS:
        return CENTER_CACHE

    conn = _open_db_connection()
    if conn is None:
        CENTER_CACHE = []
        CENTER_CACHE_TS = now
        return CENTER_CACHE

    centers: list[dict] = []
    cur = None
    try:
        cur = conn.cursor(dictionary=True)
        cur.execute("SELECT centerId, name, lat, lng, supported_waste_types FROM waste_centers")
        for row in cur.fetchall():
            try:
                plat = float(row.get("lat"))
                plng = float(row.get("lng"))
            except (TypeError, ValueError):
                continue
            tag = _resolve_waste_tag(row.get("supported_waste_types"))
            # เดิมข้าม row ที่ไม่ได้ระบุหมวด ("all") ทำให้ไม่มีข้อมูล seed เลย
            # เปลี่ยนให้ตีความเป็น "general" เพื่อให้ยังแสดงในผลค้นหาได้
            if tag == "all":
                tag = "general"
            centers.append(
                {
                    "id": row.get("centerId"),
                    "name": (row.get("name") or "").strip(),
                    "lat": plat,
                    "lng": plng,
                    "type": tag,
                }
            )
    except MySQLError as exc:
        print(f"[centers] failed to load from db: {exc}")
    finally:
        if cur is not None:
            try:
                cur.close()
            except Exception:
                pass
        conn.close()

    CENTER_CACHE = centers
    CENTER_CACHE_TS = now
    return centers


def _merge_keyword_plans(
    tag: str,
    base: List[Dict[str, Any]],
    keyword_sets: Dict[str, Set[str]],
    max_extra: Optional[int] = 10,
) -> List[Dict[str, Any]]:
    merged: List[Dict[str, Any]] = []
    seen: Set[Tuple[str, Optional[str]]] = set()

    extras = sorted(keyword_sets.get(tag, set()))
    if max_extra is not None:
        extras = extras[: max_extra]

    for word in extras:
        w = word.strip()
        if not w:
            continue
        key = (w.lower(), None)
        if key in seen:
            continue
        merged.append({"kw": w, "type": None, "tag": tag})
        seen.add(key)

    for item in base:
        kw = str(item.get("kw", "")).strip()
        if not kw:
            continue
        key = (kw.lower(), item.get("type"))
        if key in seen:
            continue
        merged.append({"kw": kw, "type": item.get("type"), "tag": tag})
        seen.add(key)

    return merged

def _classify_place_type(keyword: Optional[str], category: Optional[str]) -> str:
    k = (keyword or "").strip().lower()
    c = (category or "").strip().lower()
    tag_from_category = _resolve_waste_tag(category)
    if tag_from_category != "all":
        return tag_from_category

    keyword_sets = _get_keywords()
    for tag in ("hazardous", "recycle", "general"):
        if any(word and (word in k or word in c) for word in keyword_sets.get(tag, set())):
            return tag
    return "general"

def _places_nearby(lat: float, lng: float, radius_m: int, keyword: Optional[str] = None, place_type: Optional[str] = None) -> list[dict]:
    """เรียก Google Places NearbySearch พร้อมดึงต่อเนื่องด้วย next_page_token (สูงสุด ~60 รายการ)"""
    url = "https://maps.googleapis.com/maps/api/place/nearbysearch/json"
    params_base = {
        "location": f"{lat},{lng}",
        "radius": radius_m,
        "language": "th",
        "key": GOOGLE_MAPS_API_KEY,
    }
    out: list[dict] = []
    pagetoken: Optional[str] = None
    tries = 0
    while True:
        tries += 1
        params = dict(params_base)
        if place_type:
            params["type"] = place_type
        if keyword and keyword.strip():
            params["keyword"] = keyword.strip()
        if pagetoken:
            params = {"pagetoken": pagetoken, "key": GOOGLE_MAPS_API_KEY}
        r = requests.get(url, params=params, timeout=12)
        data = r.json()
        status = data.get("status")
        if status != "OK" and status != "ZERO_RESULTS":
            # หากกำลังรอ page token ให้ชะลอและลองใหม่เล็กน้อย
            if status == "INVALID_REQUEST" and pagetoken and tries < 5:
                time.sleep(1.5)
                continue
            break
        out += data.get("results", [])
        pagetoken = data.get("next_page_token")
        if not pagetoken:
            break
        # ต้องรอ 1.5-2 วิ ให้ token มีผล
        time.sleep(1.6)
        # จำกัดหน้าไม่เกิน 3
        if len(out) >= 60:
            break
    return out
# =========================
# Schemas (เพื่อความชัดเจน)
# =========================
class Prediction(BaseModel):
    waste_type: str
    confidence: float
    category: str

class PredictResponse(BaseModel):
    success: bool
    predictions: List[Prediction]


# =========================
# Routes
# =========================
@app.get("/")
def root():
    return {
        "ok": True,
        "model": "convnext_tiny",
        "img_size": IMG_SIZE,
        "classes": CLASS_NAMES,
        "google_key_loaded": bool(GOOGLE_MAPS_API_KEY),
        "weights_path": str(WEIGHTS_PATH),
    }

@app.get("/health")
def health():
    return {"ok": True}

@app.get("/labels")
def labels():
    return {"classes": CLASS_NAMES, "count": len(CLASS_NAMES)}


# -------------------------
# Dataset / Models / Train endpoints (Admin)
# -------------------------

def _require_admin(token: str):
    if ADMIN_TOKEN and token != ADMIN_TOKEN:
        raise HTTPException(status_code=401, detail="invalid token")


def _ensure_dir(p: Path):
    p.mkdir(parents=True, exist_ok=True)


def _scan_models() -> List[dict]:
    items = []
    for p in MODELS_DIR.glob("**/best.pt"):
        d = p.parent
        labels = d / "labels.json"
        items.append({
            "id": d.name,
            "weights": str(p),
            "labels": str(labels) if labels.exists() else None,
            "dir": str(d),
            "mtime": p.stat().st_mtime if p.exists() else None,
        })
    items.sort(key=lambda x: (x.get("mtime") or 0), reverse=True)
    return items


@app.get("/dataset/classes")
def dataset_classes():
    train_dir = DATA_ROOT / "train"
    if not train_dir.exists():
        raise HTTPException(status_code=400, detail=f"DATA_ROOT ไม่ถูกต้อง: {DATA_ROOT}")
    classes = sorted([p.name for p in train_dir.iterdir() if p.is_dir()])
    return {"root": str(DATA_ROOT), "classes": classes}


@app.post("/dataset/rename_class")
def dataset_rename_class(
    old_name: str = Form(...),
    new_name: str = Form(...),
    token: str = Form(...),
):
    """Rename a class folder under both train/ and val/ safely (admin only)."""
    _require_admin(token)
    invalid_chars = "\\/:*?\"<>|"
    if not old_name or not new_name or any(ch in new_name for ch in invalid_chars):
        raise HTTPException(status_code=400, detail="invalid class name")
    if old_name == new_name:
        return {"ok": True, "renamed": False, "reason": "same_name"}

    src_train = DATA_ROOT / "train" / old_name
    src_val = DATA_ROOT / "val" / old_name
    dst_train = DATA_ROOT / "train" / new_name
    dst_val = DATA_ROOT / "val" / new_name

    if (dst_train.exists() or dst_val.exists()):
        raise HTTPException(status_code=400, detail="target class already exists")

    renamed_any = False
    try:
        if src_train.exists():
            dst_train.parent.mkdir(parents=True, exist_ok=True)
            shutil.move(str(src_train), str(dst_train))
            renamed_any = True
        if src_val.exists():
            dst_val.parent.mkdir(parents=True, exist_ok=True)
            shutil.move(str(src_val), str(dst_val))
            renamed_any = True
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"rename failed: {e}")

    if not renamed_any:
        raise HTTPException(status_code=404, detail="class not found")
    return {"ok": True, "renamed": True, "old": old_name, "new": new_name}


@app.post("/dataset/delete_class")
def dataset_delete_class(
    class_name: str = Form(...),
    token: str = Form(...),
):
    """Delete a class folder under both train/ and val/ (admin only)."""
    _require_admin(token)
    deleted = {"train": False, "val": False}
    try:
        t = DATA_ROOT / "train" / class_name
        v = DATA_ROOT / "val" / class_name
        if t.exists():
            shutil.rmtree(t, ignore_errors=True)
            deleted["train"] = True
        if v.exists():
            shutil.rmtree(v, ignore_errors=True)
            deleted["val"] = True
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"delete failed: {e}")
    if not any(deleted.values()):
        raise HTTPException(status_code=404, detail="class not found")
    return {"ok": True, "deleted": deleted}


@app.post("/dataset/classes")
def dataset_add_class(name: str = Form(...), token: str = Form(...)):
    _require_admin(token)
    invalid_chars = "\\/:*?\"<>|"
    if not name or any(ch in name for ch in invalid_chars):
        # ป้องกันชื่อโฟลเดอร์ผิดกติกาใน Windows
        raise HTTPException(status_code=400, detail="invalid class name")
    for d in [DATA_ROOT / "train" / name, DATA_ROOT / "val" / name]:
        _ensure_dir(d)
    return {"ok": True, "class": name}


@app.post("/dataset/upload_images")
async def dataset_upload_images(
    request: Request,
    class_name: str = Form(None),
    token: str = Form(None),
):
    # ปิดการใช้งาน: บังคับให้อัปโหลดเป็น ZIP เท่านั้น
    _require_admin(token or "")
    raise HTTPException(status_code=400, detail="disabled: กรุณาอัปโหลดรูปเป็นไฟล์ ZIP เท่านั้น (ใช้ /dataset/upload_zip)")


@app.get("/dataset/class_counts")
def dataset_class_counts():
    """สรุปจำนวนรูปใน train/ และ val/ ของทุกคลาส เพื่อแสดงผลบน UI"""
    root_train = DATA_ROOT / "train"
    root_val = DATA_ROOT / "val"
    if not root_train.exists() or not root_val.exists():
        raise HTTPException(status_code=400, detail=f"DATA_ROOT ไม่ถูกต้อง: {DATA_ROOT}")
    classes = sorted(set([p.name for p in root_train.iterdir() if p.is_dir()]) | set([p.name for p in root_val.iterdir() if p.is_dir()]))

    def _count(d: Path) -> int:
        return sum(1 for p in d.rglob("*") if _is_image_file(p))

    counts = {}
    for c in classes:
        ct = _count(root_train / c)
        cv = _count(root_val / c)
        counts[c] = {"train": ct, "val": cv, "total": ct + cv}
    return {"root": str(DATA_ROOT), "counts": counts, "classes": classes}


@app.get("/dataset/class_samples")
def dataset_class_samples(class_name: str, limit: int = 20, thumb_size: int = 96, source: str | None = Query(None, regex="^(train|val)$")):
    """ส่งตัวอย่างภาพของคลาสแบบย่อ (base64 data URL) สำหรับพรีวิวในหน้า UI

    - class_name: ชื่อคลาส
    - limit: จำนวนสูงสุด
    - thumb_size: ขนาด thumbnail สั้นสุด
    - source: เจาะเลือก 'train' หรือ 'val' (ถ้าไม่ระบุจะรวมจาก train ก่อน แล้วค่อย val)
    """
    root_train = DATA_ROOT / "train" / class_name
    root_val = DATA_ROOT / "val" / class_name
    if not root_train.exists() and not root_val.exists():
        raise HTTPException(404, detail="class not found")

    files: list[Path] = []
    def _gather(d: Path):
        if not d.exists():
            return []
        # sort by mtime desc then name
        arr = [p for p in d.rglob("*") if _is_image_file(p)]
        arr.sort(key=lambda p: (p.stat().st_mtime, p.name), reverse=True)
        return arr

    if source == "train":
        files = _gather(root_train)
    elif source == "val":
        files = _gather(root_val)
    else:
        files = _gather(root_train)
        if len(files) < limit:
            files += _gather(root_val)

    out = []
    for p in files[: max(1, min(200, int(limit)))]:
        try:
            im = Image.open(p).convert("RGB")
            im.thumbnail((thumb_size, thumb_size))
            buf = io.BytesIO()
            im.save(buf, format="JPEG", quality=80)
            b64 = base64.b64encode(buf.getvalue()).decode("ascii")
            out.append({"name": p.name, "data_url": f"data:image/jpeg;base64,{b64}"})
        except Exception:
            continue
    return {"ok": True, "class": class_name, "limit": limit, "size": thumb_size, "samples": out}


@app.post("/dataset/upload_zip")
async def dataset_upload_zip(
    class_name: str = Form(...),
    token: str = Form(...),
    file: UploadFile = File(...),
    val_ratio: float = Form(0.2),
):
    _require_admin(token)
    tdir = DATA_ROOT / "train" / class_name
    vdir = DATA_ROOT / "val" / class_name
    _ensure_dir(tdir); _ensure_dir(vdir)
    tmp = MODELS_DIR / "_upload_tmp.zip"
    tmp.write_bytes(await file.read())
    saved = 0
    try:
        with ZipFile(tmp, "r") as zf:
            names = [n for n in zf.namelist() if n.lower().endswith((".jpg", ".jpeg", ".png", ".bmp", ".gif"))]
            cut = int(len(names) * (1 - max(0.0, min(0.9, float(val_ratio)))))
            for i, n in enumerate(names):
                data = zf.read(n)
                out = (tdir if i < cut else vdir) / Path(n).name
                out.parent.mkdir(parents=True, exist_ok=True)
                out.write_bytes(data)
                saved += 1
    finally:
        try: tmp.unlink(missing_ok=True)
        except Exception: pass
    return {"ok": True, "saved": saved}


@app.get("/models")
def models_list():
    items = _scan_models()
    return {
        "active": str(WEIGHTS_PATH),
        "items": items,
    }


@app.post("/models/load")
def models_load(id: Optional[str] = Form(None), path: Optional[str] = Form(None), token: str = Form(...)):
    _require_admin(token)
    target = None
    if id:
        for it in _scan_models():
            if it["id"] == id:
                target = it["weights"]
                break
    if path:
        target = path
    if not target:
        raise HTTPException(404, detail="model not found")
    # โหลดใหม่แบบปลอดภัย
    global MODEL, TF, CLASS_NAMES, IMG_SIZE, WEIGHTS_PATH, MODEL_LAST_LOADED
    MODEL, TF, CLASS_NAMES, IMG_SIZE = _load_torch_model(str(target), DEVICE)
    WEIGHTS_PATH = Path(target)
    MODEL_LAST_LOADED = str(WEIGHTS_PATH)
    try:
        _persist_active_path(WEIGHTS_PATH)
    except Exception:
        pass
    return {"ok": True, "loaded": str(WEIGHTS_PATH), "classes": len(CLASS_NAMES)}


@app.post("/models/delete")
def models_delete(id: str = Form(...), token: str = Form(...)):
    """Delete a trained model directory by id (admin only)."""
    _require_admin(token)
    # find model entry
    item = None
    for it in _scan_models():
        if it.get("id") == id:
            item = it
            break
    if not item:
        raise HTTPException(status_code=404, detail="model not found")

    mdir = Path(item.get("dir", ""))
    if not mdir.exists() or not mdir.is_dir():
        raise HTTPException(status_code=404, detail="model directory not found")

    # safety: ensure under MODELS_DIR
    try:
        mdir.resolve().relative_to(MODELS_DIR.resolve())
    except Exception:
        raise HTTPException(status_code=400, detail="invalid model path")

    # disallow deleting the currently active model
    global WEIGHTS_PATH
    try:
        if WEIGHTS_PATH and str(WEIGHTS_PATH.resolve()).startswith(str(mdir.resolve())):
            raise HTTPException(status_code=400, detail="cannot delete active model")
    except Exception:
        pass

    try:
        shutil.rmtree(mdir, ignore_errors=True)
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"delete failed: {e}")
    return {"ok": True, "deleted": id}


@app.post("/admin/test_notify")
def admin_test_notify(
    token: str = Form(...),
    version: Optional[str] = Form(None),
    message: Optional[str] = Form(None),
):
    """ส่งแจ้งเตือนทดสอบไปยัง n8n โดยตรง (ไม่ผูกกับสถานะเทรน)

    ใช้สำหรับทดสอบการเชื่อมต่อ n8n/LINE ก่อนเริ่มใช้งานจริง
    """
    _require_admin(token)
    if not N8N_WEBHOOK_URL:
        raise HTTPException(status_code=400, detail="N8N_WEBHOOK_URL not configured")
    payload = {
        "event": "test_notify",
        "version": version or "test",
        "message": message or "test from admin",
        "timestamp": time.time(),
    }
    try:
        r = requests.post(N8N_WEBHOOK_URL, json=payload, timeout=10)
        return {"ok": True, "status_code": getattr(r, "status_code", None)}
    except Exception as e:
        raise HTTPException(status_code=502, detail=f"n8n post failed: {e}")


@app.post("/train/start")
def train_start(
    version: str = Form(...),
    token: str = Form(...),
    base_model_id: str | None = Form(None),
    base_model_path: str | None = Form(None),
    epochs: int = Form(5),
):
    _require_admin(token)
    if not TRAIN_SCRIPT.exists():
        raise HTTPException(400, detail=f"ไม่พบ train.py ที่ {TRAIN_SCRIPT}")

    out_dir = MODELS_DIR / version
    _ensure_dir(out_dir)

    # โครงสร้างข้อมูลของคุณเป็น train/val อยู่แล้ว → ยืนยันก่อนเริ่ม
    ds_info = _verify_trainval_structure(DATA_ROOT)

    # หา checkpoint ตั้งต้น (ถ้ามี)
    resume = None
    if base_model_path:
        resume = base_model_path
    elif base_model_id:
        for it in _scan_models():
            if it["id"] == base_model_id:
                resume = it["weights"]
                break
    if resume is None and Path(WEIGHTS_PATH).exists():
        resume = str(WEIGHTS_PATH)

    # log + status path
    log_path = out_dir / "train.log"
    status_path = out_dir / "status.json"

    # คำสั่งให้ตรงกับ train.py (ไม่ใช้ --auto-split)
    cmd = [
        sys.executable, str(TRAIN_SCRIPT),
        "--data-dir", str(DATA_ROOT),
        "--epochs", str(int(epochs)),
        "--output-dir", str(out_dir),
        "--img-size", "288",
        "--weighted-sampler",
        "--aug-strong",
        "--tta-val", "flip",
        "--amp",
    ]
    if TRAIN_EXTRA_ARGS:
        try:
            import shlex as _shlex
            cmd += _shlex.split(TRAIN_EXTRA_ARGS)
        except Exception:
            cmd += TRAIN_EXTRA_ARGS.split()
    if resume:
        cmd += ["--init-weights", str(resume)]

    # บันทึกอินพุตไว้ช่วยดีบัก
    (out_dir / "train_input.json").write_text(
        json.dumps({
            "dataset": ds_info,
            "data_dir_used": str(DATA_ROOT),
            "resume": resume,
            "epochs": int(epochs),
            "cmd": cmd,
        }, ensure_ascii=False, indent=2),
        encoding="utf-8"
    )

    # รันโปรเซสเทรนให้แยกกลุ่มสัญญาณ + บังคับ stdout เป็น UTF-8 (กัน Ctrl+C/รีโหลด)
    env = os.environ.copy()
    env["PYTHONIOENCODING"] = "utf-8"

    creationflags = 0
    start_new_session = False
    if os.name == "nt":
        creationflags |= getattr(subprocess, "CREATE_NEW_PROCESS_GROUP", 0)
        creationflags |= getattr(subprocess, "CREATE_NO_WINDOW", 0x08000000)
    else:
        start_new_session = True

    try:
        with open(log_path, "w", encoding="utf-8", errors="ignore") as logf:
            proc = subprocess.Popen(
                cmd,
                stdout=logf,
                stderr=subprocess.STDOUT,
                env=env,
                creationflags=creationflags,
                start_new_session=start_new_session,
            )
        status_path.write_text(json.dumps({
            "pid": proc.pid,
            "cmd": cmd,
            "started": time.time(),
            "out_dir": str(out_dir),
            "epochs": int(epochs),
        }, ensure_ascii=False), encoding="utf-8")
        # Start background watcher to send webhook when best.pt appears (no need to call /train/status)
        try:
            threading.Thread(target=_watch_and_notify, args=(version, out_dir), daemon=True).start()
        except Exception:
            pass
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"start train failed: {e}")

    return {"ok": True, "pid": proc.pid, "version": version, "out": str(out_dir), "cmd": cmd}



@app.get("/train/status")
def train_status(version: str):
    d = MODELS_DIR / version
    if not d.exists():
        raise HTTPException(404, detail="version not found")

    status = {
        "done": False,
        "best": None,
        "log": None,
        "progress": 0,
        "started": None,
        "epochs": None,
    }

    best = d / "best.pt"
    if best.exists():
        status["done"] = True
        status["best"] = str(best)

    log = d / "train.log"
    st_file = d / "status.json"
    if st_file.exists():
        try:
            st_data = json.loads(st_file.read_text(encoding="utf-8"))
            status["started"] = st_data.get("started")
            status["epochs"] = st_data.get("epochs")
        except Exception:
            pass

    if log.exists():
        try:
            tail = log.read_text(encoding="utf-8", errors="ignore")
            status["log"] = tail[-4000:]

            import re
            # เจอ error ใน log → ใส่สถานะให้หน้าเว็บรู้
            if "KeyboardInterrupt" in tail or "Traceback (most recent call last):" in tail:
                status["error"] = "interrupted"

            # จับ Epoch x/y ล่าสุด
            cur_epoch = None
            total_epochs = None
            for m in re.finditer(r"(?i)(?:\[.*\])?\s*epoch\s*(\d+)\s*/\s*(\d+)", tail):
                cur_epoch = int(m.group(1))
                total_epochs = int(m.group(2))

            if not total_epochs and status.get("epochs"):
                try:
                    total_epochs = int(status["epochs"])
                except Exception:
                    total_epochs = None

            # จับ % จาก tqdm: "Train: 17%|"
            batch_pct = None
            for m in re.finditer(r"(?i)Train:\s+(\d+)%\|", tail):
                batch_pct = int(m.group(1))

            # คำนวณ progress รวม (epoch + batch)
            if cur_epoch and total_epochs:
                frac_epoch = (batch_pct / 100.0) if batch_pct is not None else 0.0
                overall = ((cur_epoch - 1) + frac_epoch) / max(total_epochs, 1)
                status["progress"] = max(1, min(99, int(overall * 100)))
            elif batch_pct is not None and status.get("epochs"):
                overall = (batch_pct / 100.0) / max(int(status["epochs"]), 1)
                status["progress"] = max(1, min(99, int(overall * 100)))

            # ดึง accuracy จาก log (ถ้ามี)
            acc = _parse_accuracy_from_log_text(tail)
            if acc is not None:
                status["accuracy"] = acc

        except Exception:
            pass

    if status["best"]:
        status["done"] = True
        status["progress"] = 100

    # Notify via n8n webhook once when training done
    try:
        if status["done"] and N8N_WEBHOOK_URL:
            marker = d / "_notify_sent.txt"
            if not marker.exists():
                payload = {
                    "event": "train_done",
                    "version": version,
                    "best": status.get("best"),
                    "started": status.get("started"),
                    "epochs": status.get("epochs"),
                    "models_dir": str(d),
                    "accuracy": status.get("accuracy"),
                    "timestamp": time.time(),
                }
                try:
                    r = requests.post(N8N_WEBHOOK_URL, json=payload, timeout=10)
                    # write marker regardless of response to avoid spamming
                    marker.write_text(json.dumps({"sent": time.time(), "code": getattr(r, "status_code", None)}), encoding="utf-8")
                except Exception:
                    # still mark to prevent repeated attempts on every poll
                    marker.write_text(json.dumps({"sent": time.time(), "error": True}), encoding="utf-8")
    except Exception:
        pass

    return status


@app.post("/train/stop")
def train_stop(
    version: str = Form(...),
    token: str = Form(...),
):
    """Stop an in-progress training process for the given model version.

    Tries to terminate the process tree cross-platform and marks the status file.
    """
    _require_admin(token)
    d = MODELS_DIR / version
    if not d.exists():
        raise HTTPException(404, detail="version not found")

    st_file = d / "status.json"
    if not st_file.exists():
        raise HTTPException(404, detail="no training status for this version")
    try:
        st_data = json.loads(st_file.read_text(encoding="utf-8"))
        pid = int(st_data.get("pid")) if st_data.get("pid") is not None else None
    except Exception:
        pid = None

    if not pid:
        # Already stopped or missing pid
        return {"ok": True, "stopped": False, "reason": "no_pid"}

    try:
        if os.name == "nt":
            # Terminate process tree on Windows
            subprocess.call(["taskkill", "/PID", str(pid), "/T", "/F"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        else:
            import signal, os as _os
            try:
                _os.killpg(pid, signal.SIGTERM)
            except Exception:
                _os.kill(pid, signal.SIGTERM)
    except Exception as e:
        # continue to mark stopped even if kill command raised
        pass

    try:
        st_data["stopped"] = time.time()
        st_file.write_text(json.dumps(st_data, ensure_ascii=False), encoding="utf-8")
    except Exception:
        pass

    return {"ok": True, "stopped": True, "pid": pid}

# -------------------------
# **Predict** (คงไว้เป๊ะ)
# -------------------------
@app.post("/predict/")   # <- ตามไฟล์เดิมของคุณ (ห้ามแก้)
async def predict(
    file: UploadFile | None = File(None, description="image file (field name: file)"),
    image: UploadFile | None = File(None, description="image file (alternate field name: image)"),
    lat: float | None = Form(None),
    lng: float | None = Form(None),
    threshold: float = Form(0.35)   # เผื่อใช้โหมด “ไม่แน่ใจ”
):
    try:
        up = file or image
        if up is None:
            raise HTTPException(status_code=400, detail="No file uploaded. Use field name 'file' or 'image'.")

        # ตรวจชนิดไฟล์
        if not (up.content_type and up.content_type.startswith("image/")):
            raise HTTPException(status_code=400, detail=f"Unsupported content type: {up.content_type}")

        # อ่านภาพ
        raw = await up.read()
        if not raw:
            raise HTTPException(status_code=400, detail="Empty file")

        from PIL import Image
        import io, torch
        img = Image.open(io.BytesIO(raw)).convert("RGB")

        # ทำให้เข้ากับ transform
        x = TF(img).unsqueeze(0).to(DEVICE)   # TF คือ transform ที่โหลดไว้ตอน start
        with torch.inference_mode():
            logits = MODEL(x)
            probs = torch.softmax(logits, dim=1)[0].cpu().numpy().tolist()

        # จัดอันดับ
        topk = sorted(
            [{"waste_type": CLASS_NAMES[i], "confidence": float(p)} for i, p in enumerate(probs)],
            key=lambda z: z["confidence"],
            reverse=True
        )[:3]

        # ใส่หมวดหมู่ + โหมด “ไม่แน่ใจ”
        def to_cat(name: str) -> str:
            if name in ("แบตเตอรี่", "หลอดไฟ"): return "ขยะอันตราย"
            if name in ("กระดาษ", "กระดาษลัง", "ขวดแก้วใส", "พลาสติก", "โลหะกระป๋อง"): return "ขยะรีไซเคิล"
            if name in ("ไม่ใช่ขยะ",): return "ไม่ใช่ขยะ"
            return "ขยะทั่วไป"

        for it in topk:
            it["category"] = to_cat(it["waste_type"])

        top1 = topk[0]
        unsure = (top1["confidence"] < threshold)

        return {
            "success": True,
            "unsure": unsure,
            "threshold": threshold,
            "predictions": topk,
            "lat": lat,
            "lng": lng,
        }

    except HTTPException:
        raise
    except Exception as e:
        # log แล้วตอบ 400 เพื่อไม่ให้กลายเป็น 500 เงียบๆ
        import traceback, sys
        traceback.print_exc(file=sys.stderr)
        raise HTTPException(status_code=400, detail=f"Predict error: {e}")


# -------------------------
# Geocode / Reverse
# -------------------------
@app.get("/geocode")
def geocode(address: str):
    if not address:
        raise HTTPException(status_code=400, detail="ต้องระบุ address")
    try:
        if GOOGLE_MAPS_API_KEY:
            url = "https://maps.googleapis.com/maps/api/geocode/json"
            params = {"address": address, "key": GOOGLE_MAPS_API_KEY, "language": "th"}
            r = requests.get(url, params=params, timeout=10)
            data = r.json()
            if data.get("status") != "OK":
                return {"success": False, "message": data.get("status"), "results": []}
            loc = data["results"][0]["geometry"]["location"]
            return {"success": True, "lat": loc["lat"], "lng": loc["lng"], "result": data["results"][0]}
        else:
            url = "https://nominatim.openstreetmap.org/search"
            params = {"q": address, "format": "json", "limit": 1}
            r = requests.get(url, params=params, headers={"User-Agent": "waste-classifier"}, timeout=10)
            data = r.json()
            if not data:
                return {"success": False, "message": "not_found"}
            loc = data[0]
            return {"success": True, "lat": float(loc["lat"]), "lng": float(loc["lon"]), "result": loc}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"geocode error: {e}")


@app.get("/reverse_geocode")
def reverse_geocode(lat: float, lng: float):
    try:
        if GOOGLE_MAPS_API_KEY:
            url = "https://maps.googleapis.com/maps/api/geocode/json"
            params = {"latlng": f"{lat},{lng}", "key": GOOGLE_MAPS_API_KEY, "language": "th"}
            r = requests.get(url, params=params, timeout=10)
            data = r.json()
            if data.get("status") != "OK":
                return {"success": False, "message": data.get("status"), "results": []}
            return {"success": True, "address": data["results"][0]["formatted_address"], "result": data["results"][0]}
        else:
            url = "https://nominatim.openstreetmap.org/reverse"
            params = {"lat": lat, "lon": lng, "format": "json"}
            r = requests.get(url, params=params, headers={"User-Agent": "waste-classifier"}, timeout=10)
            data = r.json()
            return {"success": True, "address": data.get("display_name"), "result": data}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"reverse_geocode error: {e}")


# -------------------------
# Nearby centers (แก้เฉพาะส่วนแผนที่)
# -------------------------

@app.get("/nearby_center")
def nearby_center(
    lat: float,
    lng: float,
    radius_km: float = Query(30.0, alias="radius", ge=0.5, le=120.0),
    radius_km_alt: Optional[float] = Query(None, alias="radius_km"),
    limit: int = 30,
    keyword: Optional[str] = None,
    category: Optional[str] = None,
):
    if radius_km_alt is not None:
        radius_km = float(radius_km_alt)
    radius_km = max(0.5, min(120.0, float(radius_km)))

    keyword_sets = _get_keywords()
    tag_filter = _resolve_waste_tag(category)
    kw_filter = (keyword or "").strip().lower()

    db_centers = _load_centers_from_db()
    seed_centers: List[Dict[str, Any]] = []
    if db_centers:
        for center in db_centers:
            if tag_filter != "all" and center["type"] != tag_filter:
                continue
            dist = _haversine_km(lat, lng, center["lat"], center["lng"])
            if dist > radius_km:
                continue
            if kw_filter:
                matched = keyword_sets.get(center["type"], set())
                name_text = (center["name"] or "").lower()
                if kw_filter not in name_text and all(kw_filter not in w for w in matched):
                    continue
            seed_centers.append(
                {
                    "center_name": center["name"] or "ศูนย์จัดการขยะ",
                    "name": center["name"] or "ศูนย์จัดการขยะ",
                    "lat": center["lat"],
                    "lng": center["lng"],
                    "address": "",
                    "rating": None,
                    "user_ratings_total": 0,
                    "distance": round(dist, 2),
                    "maps_link": f"https://www.google.com/maps/search/?api=1&query={center['lat']},{center['lng']}",
                    "type": center["type"],
                }
            )

    if not GOOGLE_MAPS_API_KEY:
        # หากไม่มี Google Key ให้คืนข้อมูลจากฐานข้อมูลเท่าที่มี
        seed_centers.sort(key=lambda x: x["distance"])
        return {"success": True, "centers": seed_centers[: max(1, limit)]}

    recycle_words = keyword_sets.get("recycle", set())
    hazardous_words = keyword_sets.get("hazardous", set())
    general_words = keyword_sets.get("general", set())

    hazard_types = {
        "hazardous_waste_collection",
        "waste_management",
        "electronics_store",
        "hardware_store",
        "home_goods_store",
        "storage",
        "point_of_interest",
    }
    recycle_types = {"recycling_center"}
    general_types = {"local_government_office", "waste_management"}

    def _text_of(it: Dict[str, Any]) -> str:
        return (f"{it.get('name','')} {it.get('vicinity','')} {it.get('formatted_address','')}").lower()

    def _types_of(it: Dict[str, Any]) -> Set[str]:
        return set((it.get("types") or []))

    def _operational(it: Dict[str, Any]) -> bool:
        bs = it.get("business_status")
        return (bs is None) or (bs == "OPERATIONAL")

    def _contains_any(txt: str, words: Set[str]) -> bool:
        for w in words:
            if w in txt:
                return True
        return False

    def _is_relevant(it: Dict[str, Any], tag: str) -> bool:
        if not _operational(it):
            return False
        txt = _text_of(it)
        ty = _types_of(it)
        if tag == "recycle":
            if "recycling_center" in ty:
                return True
            if "waste_management" in ty and _contains_any(txt, recycle_words):
                return True
            # ผ่อนปรน: อนุญาตร้านรับซื้อ/รีไซเคิลที่อาจมีคำว่าแบต/หลอดในรายละเอียด
            # กันเฉพาะคำอันตรายชัดเจนเท่านั้น
            hazard_red = {"อันตราย", "พิษ", "hazard", "hazardous", "toxic", "medical"}
            return _contains_any(txt, recycle_words) and not _contains_any(txt, hazard_red)
        if tag == "hazardous":
            # กันร้านขายแบตเตอรี่/อุปกรณ์ที่ไม่ได้เป็นจุดรับทิ้ง
            if {"electronics_store", "hardware_store", "home_goods_store"} & ty:
                drop_terms = {"จุดรับ", "รับ", "รับทิ้ง", "ทิ้ง", "กำจัด", "collection", "drop-off", "drop off", "disposal", "dispose"}
                if not _contains_any(txt, drop_terms):
                    return False
            # ใช้ตรรกะเดิมที่ยืดหยุ่นสำหรับแหล่งอื่น ๆ
            if _contains_any(txt, hazardous_words):
                return True
            return len(hazard_types & ty) > 0 and (
                _contains_any(txt, {"recycle", "รีไซ", "รับซื้อ", "ขยะ", "ของเสีย", "กำจัด"})
                or _contains_any(txt, {"ถ่าน", "battery", "แบตเตอรี่", "หลอด", "สาร", "เคมี", "medical"})
            )
        if tag == "general":
            if _contains_any(txt, general_words):
                return True
            return (len(general_types & ty) > 0) and ("ขยะ" in txt or "waste" in txt)
        return False

    plans: List[Dict[str, Any]] = []
    if tag_filter == "hazardous":
        # จำกัดให้เหลือ 5 คำที่ชัวร์ที่สุด
        base = [
            {"kw": "ขยะอันตราย", "type": None, "tag": "hazardous"},
            {"kw": "จุดรับขยะอันตราย", "type": None, "tag": "hazardous"},
            {"kw": "e-waste", "type": None, "tag": "hazardous"},
            {"kw": "รับทิ้งแบตเตอรี่", "type": "electronics_store", "tag": "hazardous"},
            {"kw": "รับหลอดไฟ", "type": "hardware_store", "tag": "hazardous"},
        ]
        plans = _merge_keyword_plans("hazardous", base, keyword_sets, max_extra=max(0, 5 - len(base)))
    elif tag_filter == "recycle":
        # จำกัด 5 คำที่แม่น และไม่ล็อก type เพื่อให้ Google คืนผลกว้างขึ้น
        base = [
            {"kw": "รีไซเคิล", "type": None, "tag": "recycle"},
            {"kw": "recycling center", "type": None, "tag": "recycle"},
            {"kw": "ร้านรับซื้อของเก่า", "type": None, "tag": "recycle"},
            {"kw": "รับซื้อขวดพลาสติก", "type": None, "tag": "recycle"},
            {"kw": "รับซื้อกระดาษ", "type": None, "tag": "recycle"},
        ]
        plans = _merge_keyword_plans("recycle", base, keyword_sets, max_extra=max(0, 5 - len(base)))
    elif tag_filter == "general":
        base = [
            {"kw": "สถานที่กำจัดขยะ", "type": None, "tag": "general"},
            {"kw": "ศูนย์บริหารจัดการขยะ", "type": None, "tag": "general"},
            {"kw": "จุดทิ้งขยะ", "type": None, "tag": "general"},
            {"kw": "สถานีขนถ่ายขยะ", "type": None, "tag": "general"},
            {"kw": "waste management", "type": None, "tag": "general"},
        ]
        plans = _merge_keyword_plans("general", base, keyword_sets, max_extra=max(0, 5 - len(base)))
    else:
        # ใช้ 5 คำฐานของแต่ละหมวดโดยตรง เพื่อให้โหมด "ทั้งหมด" ใกล้เคียงผลรวมของแต่ละหมวด
        plans = []
        plans += [
            {"kw": "รีไซเคิล", "type": None, "tag": "recycle"},
            {"kw": "recycling center", "type": None, "tag": "recycle"},
            {"kw": "ร้านรับซื้อของเก่า", "type": None, "tag": "recycle"},
            {"kw": "รับซื้อขวดพลาสติก", "type": None, "tag": "recycle"},
            {"kw": "รับซื้อกระดาษ", "type": None, "tag": "recycle"},
        ]
        plans += [
            {"kw": "ขยะอันตราย", "type": None, "tag": "hazardous"},
            {"kw": "จุดรับขยะอันตราย", "type": None, "tag": "hazardous"},
            {"kw": "e-waste", "type": None, "tag": "hazardous"},
            {"kw": "รับทิ้งแบตเตอรี่", "type": "electronics_store", "tag": "hazardous"},
            {"kw": "รับหลอดไฟ", "type": "hardware_store", "tag": "hazardous"},
        ]
        plans += [
            {"kw": "สถานที่กำจัดขยะ", "type": None, "tag": "general"},
            {"kw": "ศูนย์บริหารจัดการขยะ", "type": None, "tag": "general"},
            {"kw": "จุดทิ้งขยะ", "type": None, "tag": "general"},
            {"kw": "สถานีขนถ่ายขยะ", "type": None, "tag": "general"},
            {"kw": "waste management", "type": None, "tag": "general"},
        ]

    if keyword and keyword.strip():
        guessed = _classify_place_type(keyword, category)
        if guessed == "recycle":
            plans.insert(0, {"kw": keyword.strip(), "type": "recycling_center", "tag": "recycle"})
        elif guessed == "hazardous":
            plans.insert(0, {"kw": keyword.strip(), "type": None, "tag": "hazardous"})
        else:
            plans.insert(0, {"kw": keyword.strip(), "type": None, "tag": "general"})

    seen: Dict[str, Dict[str, Any]] = {}
    radius_m = int(radius_km * 1000)

    # ใส่รายการจากฐานข้อมูลก่อน แล้วค่อยต่อด้วยผลจาก Google Places
    for item in sorted(seed_centers, key=lambda x: x["distance"]):
        pid = f"db:{item['name']}@{item['lat']},{item['lng']}"
        seen[pid] = item

    for q in plans:
        kw_plan = q.get("kw")
        tag_plan = q.get("tag")
        ptype = q.get("type")
        results = _places_nearby(lat, lng, radius_m, kw_plan, ptype)
        for it in results:
            if not _is_relevant(it, tag_plan):
                continue
            pid = it.get("place_id")
            if not pid or pid in seen:
                continue
            loc = (it.get("geometry") or {}).get("location") or {}
            plat, plng = loc.get("lat"), loc.get("lng")
            if plat is None or plng is None:
                continue
            dist = _haversine_km(lat, lng, float(plat), float(plng))
            seen[pid] = {
                "center_name": it.get("name"),
                "name": it.get("name"),
                "lat": plat,
                "lng": plng,
                "address": it.get("vicinity") or it.get("formatted_address", ""),
                "rating": it.get("rating"),
                "user_ratings_total": it.get("user_ratings_total", 0),
                "distance": round(dist, 2),
                "maps_link": f"https://www.google.com/maps/place/?q=place_id:{pid}",
                "type": tag_plan,
            }

    centers = sorted(seen.values(), key=lambda x: x["distance"])[: max(1, limit)]
    return {"success": True, "centers": centers}


# =========================
# Local run
# =========================
if __name__ == "__main__":
    import uvicorn
    # รันจากโฟลเดอร์ project:
    #   uvicorn backend.main:app --reload --port 8000
    uvicorn.run("backend.main:app", host="0.0.0.0", port=int(os.getenv("PORT", "8000")), reload=True)
# -------------------------
# Helpers: log parsing + webhook sender (background)
# -------------------------

def _parse_accuracy_from_log_text(text: str) -> Optional[float]:
    try:
        if not text:
            return None
        pats = [
            r"(?:val(?:idation)?\s*acc(?:uracy)?|top[-\s]?1(?:\s*acc)?)[^0-9]{0,10}(\d+(?:\.\d+)?)\s*%",
            r"best[^0-9]{0,10}acc[^0-9]{0,10}(\d+(?:\.\d+)?)\s*%",
            r"(?:acc(?:uracy)?)[\s:=]+(\d\.\d+)",
        ]
        for p in pats:
            m = re.search(p, text, flags=re.IGNORECASE)
            if m:
                val = float(m.group(1))
                # If value is 0-1, convert to percentage
                if val <= 1.0:
                    val *= 100.0
                return round(val, 4)
        return None
    except Exception:
        return None


def _send_webhook_once(version: str, out_dir: Path) -> None:
    """Send completion webhook to N8N/Activepieces once when best.pt exists.
    Includes parsed accuracy from log if available. Non-blocking and safe.
    """
    try:
        if not N8N_WEBHOOK_URL:
            return
        marker = out_dir / "_notify_sent.txt"
        if marker.exists():
            return
        best = out_dir / "best.pt"
        if not best.exists():
            return
        # Read log and parse accuracy
        acc = None
        try:
            logp = out_dir / "train.log"
            if logp.exists():
                txt = logp.read_text(encoding="utf-8", errors="ignore")
                acc = _parse_accuracy_from_log_text(txt)
        except Exception:
            acc = None
        payload = {
            "event": "train_done",
            "version": version,
            "best": str(best),
            "started": None,
            "epochs": None,
            "models_dir": str(out_dir),
            "accuracy": acc,
            "timestamp": time.time(),
        }
        try:
            requests.post(N8N_WEBHOOK_URL, json=payload, timeout=10)
        finally:
            # Always mark to avoid repeated tries
            marker.write_text(json.dumps({"sent": time.time(), "accuracy": acc}, ensure_ascii=False), encoding="utf-8")
    except Exception:
        # Never crash background
        pass


def _watch_and_notify(version: str, out_dir: Path, stop_after_sec: int = 60*60*12):
    """Polling watcher: waits until best.pt exists, then sends webhook once.
    Falls back silently if webhook URL not configured. Lifetime up to 12h.
    """
    try:
        if not N8N_WEBHOOK_URL:
            return
        marker = out_dir / "_notify_sent.txt"
        if marker.exists():
            return
        t0 = time.time()
        while (time.time() - t0) < stop_after_sec:
            if (out_dir / "best.pt").exists():
                _send_webhook_once(version, out_dir)
                break
            time.sleep(5)
    except Exception:
        pass
