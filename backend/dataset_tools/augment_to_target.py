import argparse
import random
import uuid
from pathlib import Path
from PIL import Image, ImageOps, ImageFilter, ImageEnhance


def aug_pipeline(im: Image.Image, size: int) -> Image.Image:
    w, h = im.size
    # random crop
    scale = random.uniform(0.7, 1.0)
    nw, nh = max(1, int(w * scale)), max(1, int(h * scale))
    left = random.randint(0, max(0, w - nw))
    top = random.randint(0, max(0, h - nh))
    im = im.crop((left, top, left + nw, top + nh))

    # flips / rotate
    if random.random() < 0.5:
        im = ImageOps.mirror(im)
    if random.random() < 0.2:
        im = ImageOps.flip(im)
    if random.random() < 0.5:
        im = im.rotate(random.uniform(-15, 15), resample=Image.BICUBIC, expand=True, fillcolor=(255, 255, 255))

    # color jitter / blur
    if random.random() < 0.5:
        im = ImageEnhance.Color(im).enhance(random.uniform(0.7, 1.3))
    if random.random() < 0.5:
        im = ImageEnhance.Brightness(im).enhance(random.uniform(0.9, 1.1))
    if random.random() < 0.5:
        im = ImageEnhance.Contrast(im).enhance(random.uniform(0.9, 1.1))
    if random.random() < 0.2:
        im = im.filter(ImageFilter.GaussianBlur(radius=random.uniform(0.0, 1.2)))

    im = im.resize((size, size), Image.BICUBIC)
    return im


def run(data_root: str, img_size: int, target_per_class: int) -> None:
    train_root = Path(data_root) / "train"
    for cls_dir in sorted([p for p in train_root.iterdir() if p.is_dir()]):
        files = list(cls_dir.glob("*.jpg")) + list(cls_dir.glob("*.jpeg")) + list(cls_dir.glob("*.png"))
        cur = len(files)
        need = max(0, int(target_per_class) - cur)
        print(f"[{cls_dir.name}] {cur} -> {target_per_class} (+{need})")
        if need <= 0:
            continue
        rnd = random.Random(42)
        for i in range(need):
            src = files[i % len(files)]
            try:
                im = Image.open(src).convert("RGB")
                aug = aug_pipeline(im, img_size)
                aug.save(cls_dir / f"aug_{uuid.uuid4().hex[:12]}.jpg", quality=92)
            except Exception:
                pass


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--data-dir", required=True)
    ap.add_argument("--img-size", type=int, default=288)
    ap.add_argument("--target-per-class", type=int, default=10000)
    args = ap.parse_args()
    run(args.data_dir, args.img_size, args.target_per_class)

