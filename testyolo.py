import cv2
from inference_sdk import InferenceHTTPClient
from inference_sdk.webrtc import RTSPSource, StreamConfig, VideoMetadata

# Initialize client
client = InferenceHTTPClient.init(
    api_url="https://serverless.roboflow.com",
    api_key="J92ySMjPS7u9CfMbH34Y"
)

# Configure video source (RTSP stream)
source = RTSPSource("rtsp://admin:admin1234@169.254.77.55:554/Streaming/Channels/101")


config = StreamConfig(
    stream_output=["output_image"],  # Get video back with annotations
    data_output=["predictions", "detection_predictions", "classification_predictions"],  # <-- comma here
    processing_timeout=3600,              # 60 minutes
    requested_plan="webrtc-gpu-medium",   # webrtc-gpu-small/medium/large
    requested_region="us"                 # us/eu/ap
)

# Create streaming session
session = client.webrtc.stream(
    source=source,
    workflow="detect-and-classify",
    workspace="nours-workspace-dld6n",
    image_input="image",
    config=config
)

# Handle incoming video frames
@session.on_frame
def show_frame(frame, metadata):
    cv2.imshow("Workflow Output", frame)
    if cv2.waitKey(1) & 0xFF == ord("q"):
        session.close()

# Handle prediction data via datachannel
@session.on_data()
def on_data(data: dict, metadata: VideoMetadata):
    print(f"Frame {metadata.frame_id}: {data}")

# Run the session (blocks until closed)
session.run()
