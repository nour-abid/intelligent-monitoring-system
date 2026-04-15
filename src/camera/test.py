import os
import cv2

URL = "rtsp://admin:admin1234@169.254.77.55:554/Streaming/Channels/102"

# Force TCP + longer timeout
os.environ["OPENCV_FFMPEG_CAPTURE_OPTIONS"] = "rtsp_transport;tcp|stimeout;10000000|max_delay;500000"

cap = cv2.VideoCapture(URL, cv2.CAP_FFMPEG)
print("Opened:", cap.isOpened())

if not cap.isOpened():
    raise SystemExit("❌ Cannot open RTSP")

while True:
    ret, frame = cap.read()
    if not ret:
        print("❌ No frame (retrying...)")
        continue

    cv2.imshow("Hikvision RTSP", frame)
    if cv2.waitKey(1) & 0xFF in (ord("q"), 27):
        break

cap.release()
cv2.destroyAllWindows()
