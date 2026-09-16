import time
import cv2
from pathlib import Path
from ultralytics import YOLO

# Activity class mapping for YOLO11s
ACTIVITY_CLASSES = {
    0: "Inactive",
    1: "Using_Phone",
    2: "Working",
}

ACTIVITY_COLORS = {
    "Inactive":    (128, 128, 128),   # grey
    "Using_Phone": (  0,   0, 255),   # red
    "Working":     (  0, 255,   0),   # green
}

def main():
    # Load trained YOLO11s activity model
    model_path = Path("surveillance/models/best.pt")
    
    if not model_path.exists():
        print(f"❌ Model not found: {model_path}")
        print("   Run: python train_activity.py")
        return
    
    model = YOLO(str(model_path))
    print(f"✅ Loaded: {model_path}")

    # Use RTSP stream instead of webcam
    rtsp_url = "http://192.168.100.46:8080/video"
    print(f"📹 Connecting to: {rtsp_url}")
    
    cap = cv2.VideoCapture(rtsp_url, cv2.CAP_FFMPEG)
    if not cap.isOpened():
        print(f"❌ Cannot open RTSP stream: {rtsp_url}")
        print("   Check camera connection and URL")
        return
    
    cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)  # Minimize latency
    print("✅ RTSP stream opened successfully")

    fps = 0.0
    prev_t = time.time()

    while True:
        ok, frame = cap.read()
        if not ok:
            break

        result = model.predict(
            source=frame,
            device=0,          # GPU
            imgsz=640,
            conf=0.5,
            iou=0.5,
            verbose=False
        )[0]

        if result.boxes is not None and len(result.boxes) > 0:
            boxes = result.boxes.xyxy.cpu().numpy()
            confs = result.boxes.conf.cpu().numpy()
            clss  = result.boxes.cls.cpu().numpy().astype(int)

            for (x1, y1, x2, y2), conf, cls_idx in zip(boxes, confs, clss):
                activity = ACTIVITY_CLASSES.get(cls_idx, "Unknown")
                color = ACTIVITY_COLORS.get(activity, (255, 255, 255))
                
                cv2.rectangle(frame, (int(x1), int(y1)), (int(x2), int(y2)), color, 2)
                cv2.putText(
                    frame, f"{activity} {conf:.2f}",
                    (int(x1), max(0, int(y1) - 6)),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.6, color, 2
                )

        now = time.time()
        dt = now - prev_t
        prev_t = now
        fps = (0.9 * fps) + (0.1 * (1.0 / max(dt, 1e-6)))

        cv2.putText(frame, f"FPS: {fps:.1f}", (20, 35),
                    cv2.FONT_HERSHEY_SIMPLEX, 1.0, (255, 255, 255), 2)

        cv2.imshow("YOLO11s Activity Model (640px)", frame)
        key = cv2.waitKey(1) & 0xFF
        if key in (27, ord("q")):  # ESC or q
            break

    cap.release()
    cv2.destroyAllWindows()

if __name__ == "__main__":
    main()
