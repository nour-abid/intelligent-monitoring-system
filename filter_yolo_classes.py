from pathlib import Path
import random
import shutil

# =========================
# CONFIG (EDIT THESE)
# =========================
SRC_ROOT = Path(r"C:\Users\BH RENOVATIONS\intelligent-monitoring-system_cleaned")  # or your current dataset root
OUT_ROOT = Path(r"C:\Users\BH RENOVATIONS\intelligent-monitoring-system_6classes")  # output dataset

# Original class names by ID (must match your current labels)
OLD_NAMES = [
    "Arriving", "Counselling", "Eating", "Idle", "Leaving",
    "Sleeping", "Talking", "Using_Phone", "Working"
]

# Classes to drop by name (or you can drop by ID)
DROP_NAMES = {"Arriving", "Leaving", "Eating"}

# What to do with images that become empty after filtering
DROP_EMPTY_LABEL_IMAGES_TRAIN = True      # recommended
KEEP_BG_RATIO_TRAIN = 0.05                # if DROP_EMPTY_LABEL_IMAGES_TRAIN=False, keep 5% backgrounds

# For valid/test: keep as-is (recommended for honest evaluation)
DROP_EMPTY_LABEL_IMAGES_VAL_TEST = False

SEED = 42
random.seed(SEED)

IMG_EXTS = {".jpg", ".jpeg", ".png", ".bmp", ".webp"}


# =========================
# BUILD ID MAPPING
# =========================
drop_ids = {i for i, n in enumerate(OLD_NAMES) if n in DROP_NAMES}
keep_ids = [i for i in range(len(OLD_NAMES)) if i not in drop_ids]
new_id = {old: idx for idx, old in enumerate(keep_ids)}
NEW_NAMES = [("Meeting" if OLD_NAMES[i] == "Counselling" else OLD_NAMES[i]) for i in keep_ids]

print("Dropping IDs:", sorted(list(drop_ids)), "=>", [OLD_NAMES[i] for i in sorted(list(drop_ids))])
print("Keeping IDs:", keep_ids, "=>", NEW_NAMES)
print("Remap:", new_id)


def parse_yolo_label(txt_path: Path):
    """Return list of (cls, x, y, w, h) from a YOLO label file."""
    if not txt_path.exists():
        return []
    lines = txt_path.read_text(encoding="utf-8", errors="ignore").strip().splitlines()
    out = []
    for line in lines:
        parts = line.strip().split()
        if len(parts) >= 5:
            try:
                cls = int(float(parts[0]))
                vals = list(map(float, parts[1:5]))
                out.append((cls, *vals))
            except:
                pass
    return out


def write_yolo_label(txt_path: Path, rows):
    txt_path.parent.mkdir(parents=True, exist_ok=True)
    if not rows:
        txt_path.write_text("", encoding="utf-8")
        return
    txt_path.write_text("\n".join(
        f"{cls} {x:.6f} {y:.6f} {w:.6f} {h:.6f}" for cls, x, y, w, h in rows
    ) + "\n", encoding="utf-8")


def find_image(img_dir: Path, stem: str):
    for ext in IMG_EXTS:
        p = img_dir / (stem + ext)
        if p.exists():
            return p
    # fallback
    hits = list(img_dir.glob(stem + ".*"))
    return hits[0] if hits else None


def process_split(split: str, drop_empty: bool, keep_bg_ratio: float):
    src_img = SRC_ROOT / split / "images"
    src_lab = SRC_ROOT / split / "labels"

    dst_img = OUT_ROOT / split / "images"
    dst_lab = OUT_ROOT / split / "labels"
    dst_img.mkdir(parents=True, exist_ok=True)
    dst_lab.mkdir(parents=True, exist_ok=True)

    label_files = sorted(src_lab.glob("*.txt"))
    print(f"\n[{split}] label files:", len(label_files))

    kept = 0
    became_bg = []

    for lf in label_files:
        stem = lf.stem
        img_path = find_image(src_img, stem)
        if img_path is None:
            continue

        rows = parse_yolo_label(lf)

        # filter + remap
        new_rows = []
        for (cls, x, y, w, h) in rows:
            if cls in drop_ids:
                continue
            if cls in new_id:
                new_rows.append((new_id[cls], x, y, w, h))

        if len(new_rows) == 0:
            became_bg.append(stem)
            if drop_empty:
                continue  # skip this image entirely

        # copy image
        shutil.copy2(img_path, dst_img / img_path.name)
        # write new label (could be empty if we keep bg)
        write_yolo_label(dst_lab / f"{stem}.txt", new_rows)
        kept += 1

    if (not drop_empty) and became_bg:
        # keep only a fraction of bg images
        keep_n = int(len(became_bg) * keep_bg_ratio)
        keep_stems = set(random.sample(became_bg, k=keep_n)) if keep_n > 0 else set()

        # remove the bg images not selected
        for stem in became_bg:
            if stem in keep_stems:
                continue
            # delete copied image+label if exists
            for ext in IMG_EXTS:
                p = dst_img / (stem + ext)
                if p.exists():
                    p.unlink()
            lp = dst_lab / f"{stem}.txt"
            if lp.exists():
                lp.unlink()

        print(f"[{split}] bg after filtering: {len(became_bg)} -> kept {len(keep_stems)} (ratio={keep_bg_ratio})")

    print(f"[{split}] kept images:", kept)


def write_yaml():
    yaml_path = OUT_ROOT / "data_filtered.yaml"
    # Use forward slashes
    root_posix = OUT_ROOT.as_posix()
    lines = [
        f"path: {root_posix}",
        "train: train/images",
        "val: valid/images",
        "test: test/images",
        "",
        "names:",
    ]
    for i, n in enumerate(NEW_NAMES):
        lines.append(f"  {i}: {n}")
    yaml_path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print("\nWrote:", yaml_path)


def main():
    if OUT_ROOT.exists():
        shutil.rmtree(OUT_ROOT)
    OUT_ROOT.mkdir(parents=True, exist_ok=True)

    process_split("train", drop_empty=DROP_EMPTY_LABEL_IMAGES_TRAIN, keep_bg_ratio=KEEP_BG_RATIO_TRAIN)
    process_split("valid", drop_empty=DROP_EMPTY_LABEL_IMAGES_VAL_TEST, keep_bg_ratio=1.0)
    process_split("test",  drop_empty=DROP_EMPTY_LABEL_IMAGES_VAL_TEST, keep_bg_ratio=1.0)

    write_yaml()


if __name__ == "__main__":
    main()