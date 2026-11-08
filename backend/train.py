import argparse, os, math, json, time, random
from pathlib import Path
from typing import Dict, List, Tuple
import torch, torch.nn as nn, torch.nn.functional as F
from torch.utils.data import DataLoader, WeightedRandomSampler
from torchvision import datasets, transforms
import numpy as np

# Default normalization for ImageNet pretrained models
IMAGENET_MEAN=[0.485,0.456,0.406]
IMAGENET_STD =[0.229,0.224,0.225]

def seed_all(s=42):
    random.seed(s); np.random.seed(s); torch.manual_seed(s); torch.cuda.manual_seed_all(s)

def build_loaders(data_dir: Path, img_size: int, bs: int, weighted: bool, aug_strong: bool):
    train_tf = [
        transforms.RandomResizedCrop(img_size, scale=(0.6, 1.0), ratio=(0.75,1.33)),
        transforms.RandomHorizontalFlip(),
        transforms.RandomVerticalFlip(p=0.1),
        transforms.ColorJitter(0.1,0.1,0.1,0.05),
        transforms.ToTensor(),
        transforms.Normalize(mean=IMAGENET_MEAN, std=IMAGENET_STD),
    ]
    if aug_strong:
        train_tf.insert(1, transforms.AutoAugment(transforms.AutoAugmentPolicy.IMAGENET))
    val_tf = transforms.Compose([
        transforms.Resize((img_size, img_size)),
        transforms.ToTensor(),
        transforms.Normalize(mean=IMAGENET_MEAN, std=IMAGENET_STD),
    ])
    train_ds = datasets.ImageFolder(str(data_dir/"train"), transform=transforms.Compose(train_tf))
    val_ds   = datasets.ImageFolder(str(data_dir/"val"),   transform=val_tf)

    sampler=None
    if weighted:
        counts = np.zeros(len(train_ds.classes), dtype=np.int64)
        for _, y in train_ds.samples: counts[y]+=1
        class_weights = 1.0 / np.clip(counts, 1, None)
        weights = [class_weights[y] for _,y in train_ds.samples]
        sampler = WeightedRandomSampler(weights, num_samples=len(weights), replacement=True)

    train_loader = DataLoader(train_ds, batch_size=bs, shuffle=(sampler is None), sampler=sampler, num_workers=4, pin_memory=True)
    val_loader   = DataLoader(val_ds,   batch_size=max(1,bs*2), shuffle=False, num_workers=4, pin_memory=True)
    return train_loader, val_loader, train_ds.classes

def build_model(num_classes: int, init_weights: str|None):
    import timm
    model_name = "convnextv2_large.fcmae_ft_in22k_in1k"
    model = timm.create_model(model_name, pretrained=True, num_classes=num_classes)
    if init_weights and Path(init_weights).exists():
        ckpt = torch.load(init_weights, map_location="cpu")
        sd = ckpt.get("model_state") or ckpt.get("state_dict") or ckpt
        new_sd={}
        for k,v in sd.items():
            if k.startswith("module."): k=k[7:]
            if k.startswith("model."):  k=k[6:]
            new_sd[k]=v
        try: model.load_state_dict(new_sd, strict=False)
        except Exception: pass
    return model, model_name

def evaluate(model, loader, device, tta=None):
    model.eval()
    correct=0; total=0; loss_sum=0.0
    ce = nn.CrossEntropyLoss()
    with torch.no_grad():
        for x,y in loader:
            x=x.to(device)
            if tta=="flip":
                xf = torch.flip(x, dims=[-1])
                logits = (model(x)+model(xf))/2
            else:
                logits = model(x)
            loss = ce(logits, y.to(device))
            loss_sum += loss.item()*x.size(0)
            pred = logits.argmax(1).cpu()
            correct += (pred==y).sum().item()
            total += y.size(0)
    return loss_sum/total, correct/total

def train_one_epoch(model, loader, opt, scaler, device):
    model.train()
    ce = nn.CrossEntropyLoss(label_smoothing=0.05)
    total=0; loss_sum=0.0
    for x,y in loader:
        x=x.to(device); y=y.to(device)
        opt.zero_grad(set_to_none=True)
        with torch.cuda.amp.autocast(enabled=(scaler is not None)):
            logits = model(x)
            loss = ce(logits, y)
        if scaler is not None:
            scaler.scale(loss).backward()
            scaler.step(opt)
            scaler.update()
        else:
            loss.backward(); opt.step()
        total += x.size(0); loss_sum += loss.item()*x.size(0)
    return loss_sum/total

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--data-dir", required=True)
    ap.add_argument("--output-dir", required=True)
    ap.add_argument("--epochs", type=int, default=10)
    ap.add_argument("--img-size", type=int, default=288)
    ap.add_argument("--batch-size", type=int, default=16)
    ap.add_argument("--lr", type=float, default=5e-5)
    ap.add_argument("--weight-decay", type=float, default=0.05)
    ap.add_argument("--weighted-sampler", action="store_true")
    ap.add_argument("--aug-strong", action="store_true")
    ap.add_argument("--tta-val", type=str, default=None, choices=[None,"flip","none"])
    ap.add_argument("--amp", action="store_true")
    ap.add_argument("--init-weights", type=str, default=None)
    ap.add_argument("--seed", type=int, default=42)
    args = ap.parse_args()

    seed_all(args.seed)
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    out = Path(args.output_dir); out.mkdir(parents=True, exist_ok=True)

    train_loader, val_loader, classes = build_loaders(Path(args.data_dir), args.img_size, args.batch_size, args.weighted_sampler, args.aug_strong)
    num_classes=len(classes)
    model, model_name = build_model(num_classes, args.init_weights)
    model = model.to(device)

    opt = torch.optim.AdamW(model.parameters(), lr=args.lr, weight_decay=args.weight_decay)
    sched = torch.optim.lr_scheduler.CosineAnnealingLR(opt, T_max=max(1,args.epochs))

    scaler = torch.cuda.amp.GradScaler(enabled=args.amp)

    best_acc=0.0
    idx_to_class={i:c for i,c in enumerate(classes)}
    (out/"labels.json").write_text(json.dumps(idx_to_class, ensure_ascii=False, indent=2), encoding="utf-8")

    for epoch in range(1, args.epochs+1):
        t0=time.time()
        train_loss = train_one_epoch(model, train_loader, opt, scaler, device)
        val_loss, val_acc = evaluate(model, val_loader, device, tta=(None if args.tta_val in [None,'none'] else args.tta_val))
        sched.step()
        dt=time.time()-t0
        print(f"[Epoch {epoch}/{args.epochs}] train_loss={train_loss:.4f} val_loss={val_loss:.4f} val_acc={val_acc*100:.2f}% lr={sched.get_last_lr()[0]:.2e} time={dt:.1f}s", flush=True)

        if val_acc>best_acc:
            best_acc=val_acc
            ckpt={ "model_state": model.state_dict(), "num_classes": num_classes, "img_size": args.img_size,
                   "idx_to_class": idx_to_class, "model_name": model_name, "mean": IMAGENET_MEAN, "std": IMAGENET_STD }
            torch.save(ckpt, str(out/"best.pt"))
    print(f"best_acc={best_acc*100:.2f}%")

if __name__=="__main__":
    main()

