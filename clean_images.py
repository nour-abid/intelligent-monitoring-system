from pathlib import Path
import random
import shutil

# -------------------
# CONFIG
# -------------------
DATA_ROOT = Path(r"C:\Users\BH RENOVATIONS\intelligent-monitoring-system")  # where train/valid/test are
OUT_ROOT  = Path(r"C:\Users\BH RENOVATIONS\intelligent-monitoring-system_cleaned")

KEEP_BG_RATIO_TRAIN = 0.10   # keep 10% of background images in train
SEED = 42
random.seed(SEED)

IMG_EXTS = {".jpg", ".jpeg", ".png", ".bmp", ".webp"}

def is_background_label(label_path: Path) -> bool:
    if not label_path.exists():
        return True
    txt = label_path.read_text(encoding="utf-8", errors="ignore").strip()
    return len(txt) == 0

def copy_pair(img_path: Path, lab_path: Path, out_img_dir: Path, out_lab_dir: Path):
    out_img_dir.mkdir(parents=True, exist_ok=True)
    out_lab_dir.mkdir(parents=True, exist_ok=True)
    shutil.copy2(img_path, out_img_dir / img_path.name)
    if lab_path.exists():
        shutil.copy2(lab_path, out_lab_dir / lab_path.name)
    else:
        # create empty label to keep YOLO happy
        (out_lab_dir / (img_path.stem + ".txt")).write_text("")

def process_split(split_name: str, keep_bg_ratio: float):
    img_dir = DATA_ROOT / split_name / "images"
    lab_dir = DATA_ROOT / split_name / "labels"
    out_img_dir = OUT_ROOT / split_name / "images"
    out_lab_dir = OUT_ROOT / split_name / "labels"

    images = [p for p in img_dir.iterdir() if p.suffix.lower() in IMG_EXTS]
    bg, fg = [], []

    for img in images:
        lab = lab_dir / (img.stem + ".txt")
        (bg if is_background_label(lab) else fg).append(img)

    print(f"\n[{split_name}] total={len(images)} fg={len(fg)} bg={len(bg)} bg%={(len(bg)/max(1,len(images)))*100:.1f}%")

    # keep some backgrounds
    keep_bg = random.sample(bg, k=int(len(bg) * keep_bg_ratio)) if bg else []
    keep = fg + keep_bg
    print(f"[{split_name}] keeping {len(keep)} images (fg + {len(keep_bg)} bg)")

    # copy kept
    if OUT_ROOT.exists() and split_name == "train":
        # don't delete whole OUT_ROOT if you rerun; delete split only
        pass
    for img in keep:
        lab = lab_dir / (img.stem + ".txt")
        copy_pair(img, lab, out_img_dir, out_lab_dir)

def main():
    # rebuild output
    if OUT_ROOT.exists():
        shutil.rmtree(OUT_ROOT)

    # train: filter bg
    process_split("train", KEEP_BG_RATIO_TRAIN)
    # valid/test: keep as-is (don’t bias evaluation)
    process_split("valid", 1.0)
    process_split("test", 1.0)

    # copy your yaml if it exists
    yaml_in = DATA_ROOT / "data_balanced.yaml"
    if yaml_in.exists():
        yaml_out = OUT_ROOT / "data_cleaned.yaml"
        txt = yaml_in.read_text(encoding="utf-8", errors="ignore")
        # update path line (simple replace if present)
        txt_lines = txt.splitlines()
        txt_lines = [f"path: {OUT_ROOT.as_posix()}" if line.strip().startswith("path:") else line for line in txt_lines]
        yaml_out.write_text("\n".join(txt_lines), encoding="utf-8")
        print("\nWrote:", yaml_out)

if __name__ == "__main__":
    main()