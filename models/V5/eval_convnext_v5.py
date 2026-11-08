import json
import os
from pathlib import Path

import matplotlib
import matplotlib.pyplot as plt
import numpy as np
import torch
import torch.nn as nn
from sklearn.metrics import (
    accuracy_score,
    classification_report,
    confusion_matrix,
)
from torch.utils.data import DataLoader
from torchvision import datasets, transforms


# -------------------------
# Configuration
# -------------------------
SCRIPT_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = SCRIPT_DIR.parent.parent

# Default paths (edit if needed)
MODEL_PATH = SCRIPT_DIR / "best.pt"
VAL_DIR = PROJECT_ROOT / "data" / "prepared" / "val"

ACCURACY_FIG_PATH = SCRIPT_DIR / "accuracy_per_class.png"
CONFUSION_MATRIX_FIG_PATH = SCRIPT_DIR / "confusion_matrix.png"

# ImageNet normalization (used during training)
IMAGENET_MEAN = [0.485, 0.456, 0.406]
IMAGENET_STD = [0.229, 0.224, 0.225]

# Set this to a font name that supports Thai (e.g., "Tahoma")
# or leave as None to use the default font.
FONT_NAME = "Tahoma"  


def set_matplotlib_font():
    """Configure matplotlib to use a font that supports Thai labels."""
    if FONT_NAME:
        matplotlib.rcParams["font.family"] = FONT_NAME
    matplotlib.rcParams["axes.unicode_minus"] = False


def load_checkpoint(model_path: Path, device: torch.device) -> dict:
    """Load the checkpoint from disk."""
    if not model_path.is_file():
        raise FileNotFoundError(f"Model file not found: {model_path}")

    print(f"Loading checkpoint from: {model_path}")
    ckpt = torch.load(model_path, map_location=device)

    # If the file is a raw state_dict or nn.Module, wrap as dict
    if isinstance(ckpt, nn.Module):
        ckpt = {"model_state": ckpt.state_dict()}
    elif isinstance(ckpt, dict) and "state_dict" not in ckpt and "model_state" not in ckpt:
        # Heuristic: if looks like a state_dict (all tensor values), wrap it
        if all(isinstance(v, torch.Tensor) for v in ckpt.values()):
            ckpt = {"model_state": ckpt}

    return ckpt


def build_model_from_checkpoint(ckpt: dict, device: torch.device) -> tuple[nn.Module, int, str]:
    """
    Rebuild the ConvNeXt model from checkpoint metadata.

    Uses timm with the stored model_name if available.
    Falls back to convnextv2_large.fcmae_ft_in22k_in1k.
    """
    import timm

    idx_to_class = ckpt.get("idx_to_class")
    if idx_to_class is not None:
        # Keys may be strings "0", "1", ...
        try:
            num_classes = len(idx_to_class)
        except TypeError:
            num_classes = len(list(idx_to_class.keys()))
    else:
        num_classes = int(ckpt.get("num_classes", 11))

    model_name = ckpt.get("model_name", "convnextv2_large.fcmae_ft_in22k_in1k")
    print(f"Building model: {model_name} (num_classes={num_classes})")

    model = timm.create_model(model_name, pretrained=False, num_classes=num_classes)

    # Extract state_dict
    state_dict = ckpt.get("model_state") or ckpt.get("state_dict") or ckpt

    # Strip common prefixes if necessary
    cleaned_state_dict = {}
    for k, v in state_dict.items():
        if k.startswith("module."):
            k = k[7:]
        if k.startswith("model."):
            k = k[6:]
        cleaned_state_dict[k] = v

    model.load_state_dict(cleaned_state_dict, strict=False)
    model.to(device)
    return model, num_classes, model_name


def load_val_loader(
    val_dir: Path,
    img_size: int,
    mean: list[float],
    std: list[float],
    batch_size: int = 32,
    num_workers: int = 2,
):
    """Create DataLoader for validation set."""
    if not val_dir.is_dir():
        raise FileNotFoundError(f"Validation directory not found: {val_dir}")

    val_transform = transforms.Compose(
        [
            transforms.Resize((img_size, img_size)),
            transforms.ToTensor(),
            transforms.Normalize(mean=mean, std=std),
        ]
    )

    val_dataset = datasets.ImageFolder(str(val_dir), transform=val_transform)
    class_names = val_dataset.classes
    print(f"Found {len(class_names)} classes in validation set at {val_dir}:")
    for idx, name in enumerate(class_names):
        print(f"  {idx}: {name}")

    val_loader = DataLoader(
        val_dataset,
        batch_size=batch_size,
        shuffle=False,
        num_workers=num_workers,
        pin_memory=torch.cuda.is_available(),
    )

    return val_loader, class_names


def evaluate(model: nn.Module, data_loader: DataLoader, device: torch.device):
    """Run inference on the validation set and return targets and predictions."""
    model.eval()
    all_preds = []
    all_targets = []

    with torch.no_grad():
        for images, labels in data_loader:
            images = images.to(device)
            labels = labels.to(device)

            outputs = model(images)
            _, preds = torch.max(outputs, 1)

            all_preds.append(preds.cpu().numpy())
            all_targets.append(labels.cpu().numpy())

    all_preds = np.concatenate(all_preds)
    all_targets = np.concatenate(all_targets)
    return all_targets, all_preds


def compute_metrics(all_targets, all_preds, class_names):
    """Compute overall accuracy, per-class accuracy, classification report and confusion matrices."""
    num_classes = len(class_names)

    # Overall accuracy
    overall_acc = accuracy_score(all_targets, all_preds)
    overall_acc_percent = overall_acc * 100.0

    # Per-class accuracy
    per_class_correct = np.zeros(num_classes, dtype=int)
    per_class_total = np.zeros(num_classes, dtype=int)

    for t, p in zip(all_targets, all_preds):
        per_class_total[t] += 1
        if t == p:
            per_class_correct[t] += 1

    per_class_acc = per_class_correct / np.maximum(per_class_total, 1)

    # Classification report
    cls_report = classification_report(
        all_targets,
        all_preds,
        target_names=class_names,
        digits=4,
    )

    # Confusion matrices
    cm = confusion_matrix(all_targets, all_preds)
    cm_normalized = confusion_matrix(all_targets, all_preds, normalize="true")

    return overall_acc_percent, per_class_acc, per_class_correct, per_class_total, cls_report, cm, cm_normalized


def plot_per_class_accuracy(per_class_acc, class_names, output_path: Path):
    """Plot a bar chart of per-class accuracy (in %) and save to PNG."""
    num_classes = len(class_names)
    x = np.arange(num_classes)
    acc_percent = per_class_acc * 100.0

    plt.figure(figsize=(12, 6))
    bars = plt.bar(x, acc_percent, color="skyblue", edgecolor="black")

    for bar, acc in zip(bars, acc_percent):
        height = bar.get_height()
        plt.text(
            bar.get_x() + bar.get_width() / 2,
            height + 1,
            f"{acc:.1f}%",
            ha="center",
            va="bottom",
            fontsize=8,
        )

    plt.xticks(x, class_names, rotation=45, ha="right")
    plt.ylabel("Accuracy (%)")
    plt.ylim(0, 100)
    plt.title("Per-Class Accuracy on Validation Set")
    plt.tight_layout()
    plt.savefig(output_path, dpi=300)
    plt.close()
    print(f"Saved per-class accuracy figure to: {output_path}")


def plot_confusion_matrix_figure(cm_normalized, class_names, output_path: Path):
    """Plot a normalized confusion matrix heatmap and save to PNG."""
    num_classes = len(class_names)
    fig, ax = plt.subplots(figsize=(10, 8))
    im = ax.imshow(cm_normalized, interpolation="nearest", cmap=plt.cm.Blues)

    cbar = ax.figure.colorbar(im, ax=ax)
    cbar.ax.set_ylabel("Normalized accuracy", rotation=-90, va="bottom")

    ax.set_xticks(np.arange(num_classes))
    ax.set_yticks(np.arange(num_classes))
    ax.set_xticklabels(class_names, rotation=45, ha="right")
    ax.set_yticklabels(class_names)

    ax.set_ylabel("True label")
    ax.set_xlabel("Predicted label")
    ax.set_title("Normalized Confusion Matrix")

    fmt = ".2f"
    thresh = cm_normalized.max() / 2.0
    for i in range(num_classes):
        for j in range(num_classes):
            value = cm_normalized[i, j]
            ax.text(
                j,
                i,
                format(value, fmt),
                ha="center",
                va="center",
                color="white" if value > thresh else "black",
                fontsize=8,
            )

    plt.tight_layout()
    plt.savefig(output_path, dpi=300)
    plt.close()
    print(f"Saved confusion matrix figure to: {output_path}")


def load_class_names(ckpt: dict, fallback_classes: list[str]):
    """
    Get class names in index order.
    Prefer idx_to_class from checkpoint; otherwise use dataset classes.
    """
    idx_to_class = ckpt.get("idx_to_class")
    if idx_to_class is None:
        return fallback_classes

    # idx_to_class may have string or int keys
    mapped = {}
    for k, v in idx_to_class.items():
        try:
            idx = int(k)
        except ValueError:
            continue
        mapped[idx] = v

    if not mapped:
        return fallback_classes

    class_names = [mapped[i] for i in sorted(mapped.keys())]
    return class_names


def main():
    # Device
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    print(f"Using device: {device}")

    # Font for Thai labels
    set_matplotlib_font()

    # Load checkpoint
    ckpt = load_checkpoint(MODEL_PATH, device)

    # Use stored img_size / normalization if available
    img_size = int(ckpt.get("img_size", 288))
    mean = ckpt.get("mean", IMAGENET_MEAN)
    std = ckpt.get("std", IMAGENET_STD)

    print(f"Using img_size={img_size}, mean={mean}, std={std}")

    # Build model
    model, num_classes, model_name = build_model_from_checkpoint(ckpt, device)

    # Data
    print(f"Loading validation data from: {VAL_DIR}")
    val_loader, dataset_class_names = load_val_loader(
        VAL_DIR, img_size=img_size, mean=mean, std=std
    )

    # Class names (Thai) in correct index order
    class_names = load_class_names(ckpt, dataset_class_names)

    # Evaluate
    print("\nEvaluating on validation set...")
    all_targets, all_preds = evaluate(model, val_loader, device)

    # Metrics
    (
        overall_acc_percent,
        per_class_acc,
        per_class_correct,
        per_class_total,
        cls_report,
        cm,
        cm_normalized,
    ) = compute_metrics(all_targets, all_preds, class_names)

    # Print results
    print("\n================ Evaluation Results ================")
    print(f"Model: {model_name}")
    print(f"Overall Accuracy: {overall_acc_percent:.2f}%")

    print("\nPer-class accuracy:")
    for idx, (name, acc, correct, total) in enumerate(
        zip(class_names, per_class_acc, per_class_correct, per_class_total)
    ):
        print(
            f"[{idx:2d}] {name:15s} : {acc * 100.0:6.2f}%  "
            f"({correct}/{total})"
        )

    print("\nClassification report:")
    print(cls_report)

    # Plots
    print("\nGenerating figures...")
    plot_per_class_accuracy(per_class_acc, class_names, ACCURACY_FIG_PATH)
    plot_confusion_matrix_figure(cm_normalized, class_names, CONFUSION_MATRIX_FIG_PATH)

    print("\nAll done.")
    print(f"Figures saved as:")
    print(f"  - {ACCURACY_FIG_PATH}")
    print(f"  - {CONFUSION_MATRIX_FIG_PATH}")


if __name__ == "__main__":
    main()

