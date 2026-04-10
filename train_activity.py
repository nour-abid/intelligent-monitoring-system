from ultralytics import YOLO

MODEL = r"C:\Users\BH RENOVATIONS\intelligent-monitoring-system\yolo11s.pt"
DATA  = r"C:\Users\BH RENOVATIONS\intelligent-monitoring-system\data_balanced.yaml"

def main():
    model = YOLO(MODEL)
    model.train(
        data=DATA,
        imgsz=640,          # ✅ recommended for GTX 1650 + deployment
        batch=8,            # if OOM -> 4
        epochs=100,
        device=0,
        workers=4,          # ✅ now safe with __main__ guard
        seed=42,
        deterministic=True,
        cache=False,        # your logs say not enough RAM to cache images anyway
        cos_lr=True,
        patience=20,
        project="runs_activity",
        name="activity_yolo11s_prod_640_balanced",
    )

if __name__ == "__main__":
    main()