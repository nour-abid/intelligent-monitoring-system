import time
import cv2
from ultralytics import YOLO

# COCO class ids
PERSON_ID = 0
PHONE_ID = 67

def main():
    model = YOLO("yolov8n.pt")  # downloads once

    cap = cv2.VideoCapture(0)
    cap.set(cv2.CAP_PROP_FRAME_WIDTH, 1280)
    cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 720)

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
            conf=0.35,
            iou=0.5,
            classes=[PERSON_ID, PHONE_ID],
            verbose=False
        )[0]

        if result.boxes is not None and len(result.boxes) > 0:
            boxes = result.boxes.xyxy.cpu().numpy()
            confs = result.boxes.conf.cpu().numpy()
            clss  = result.boxes.cls.cpu().numpy().astype(int)

            for (x1, y1, x2, y2), c, k in zip(boxes, confs, clss):
                label = "person" if k == PERSON_ID else "phone"
                cv2.rectangle(frame, (int(x1), int(y1)), (int(x2), int(y2)), (0, 255, 0), 2)
                cv2.putText(
                    frame, f"{label} {c:.2f}",
                    (int(x1), max(0, int(y1) - 6)),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.6, (0, 255, 0), 2
                )

        now = time.time()
        dt = now - prev_t
        prev_t = now
        fps = (0.9 * fps) + (0.1 * (1.0 / max(dt, 1e-6)))

        cv2.putText(frame, f"FPS: {fps:.1f}", (20, 35),
                    cv2.FONT_HERSHEY_SIMPLEX, 1.0, (255, 255, 255), 2)

        cv2.imshow("YOLOv8 (person + phone)", frame)
        key = cv2.waitKey(1) & 0xFF
        if key in (27, ord("q")):  # ESC or q
            break

    cap.release()
    cv2.destroyAllWindows()

if __name__ == "__main__":
    main()
