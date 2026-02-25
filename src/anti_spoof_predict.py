import os
import sys
from pathlib import Path
import torch
import numpy as np
import torch.nn.functional as F

# Make imports work in both:
# - python src/recognition/attendance_webcam.py
# - python -m src.recognition.attendance_webcam
THIS_DIR = Path(__file__).resolve().parent            # .../src/recognition
SRC_DIR = THIS_DIR.parent                             # .../src
PROJECT_ROOT = SRC_DIR.parent                         # project root

for p in (THIS_DIR, SRC_DIR, PROJECT_ROOT):
    sp = str(p)
    if sp not in sys.path:
        sys.path.insert(0, sp)

try:
    # module-style imports
    from src.model_lib.MiniFASNet import MiniFASNetV1, MiniFASNetV2, MiniFASNetV1SE, MiniFASNetV2SE
    from src.data_io import transform as trans
    from src.utility import get_kernel, parse_model_name
except ModuleNotFoundError:
    # script/local fallback
    from model_lib.MiniFASNet import MiniFASNetV1, MiniFASNetV2, MiniFASNetV1SE, MiniFASNetV2SE
    from data_io import transform as trans
    from utility import get_kernel, parse_model_name


MODEL_MAPPING = {
    "MiniFASNetV1": MiniFASNetV1,
    "MiniFASNetV2": MiniFASNetV2,
    "MiniFASNetV1SE": MiniFASNetV1SE,
    "MiniFASNetV2SE": MiniFASNetV2SE,
}


class AntiSpoofPredict:
    """
    Patched version:
    - DOES NOT load OpenCV Caffe face detector
    - Only runs MiniFASNet inference on already-cropped patches
    - Works with CropImage patch crops from attendance_webcam.py
    """
    def __init__(self, device_id=0):
        if device_id >= 0 and torch.cuda.is_available():
            self.device = torch.device(f"cuda:{device_id}")
        else:
            self.device = torch.device("cpu")

        self.model = None
        self.last_model_path = None

    def _load_model(self, model_path: str):
        model_path = str(model_path)
        if self.last_model_path == model_path and self.model is not None:
            return  # cache loaded model

        model_name = os.path.basename(model_path)
        h_input, w_input, model_type, _ = parse_model_name(model_name)
        kernel_size = get_kernel(h_input, w_input)

        if model_type not in MODEL_MAPPING:
            raise ValueError(f"Unsupported model type parsed from '{model_name}': {model_type}")

        model = MODEL_MAPPING[model_type](conv6_kernel=kernel_size).to(self.device)

        state_dict = torch.load(model_path, map_location=self.device)

        # Handle checkpoints wrapped with "module."
        if isinstance(state_dict, dict) and len(state_dict) > 0:
            first_key = next(iter(state_dict))
            if isinstance(first_key, str) and first_key.startswith("module."):
                from collections import OrderedDict
                new_state_dict = OrderedDict()
                for key, value in state_dict.items():
                    new_state_dict[key[7:]] = value
                state_dict = new_state_dict

        model.load_state_dict(state_dict, strict=False)
        model.eval()

        self.model = model
        self.last_model_path = model_path

    def predict(self, img, model_path):
        """
        img: cropped patch image (H,W,3) uint8 from CropImage.crop(...)
        returns: softmax probabilities shape [1,3]
        """
        if img is None or not isinstance(img, np.ndarray) or img.size == 0:
            raise ValueError("AntiSpoofPredict.predict received invalid image patch")

        self._load_model(model_path)

        test_transform = trans.Compose([
            trans.ToTensor(),
        ])

        x = test_transform(img)
        x = x.unsqueeze(0).to(self.device)

        with torch.no_grad():
            result = self.model(x)
            if isinstance(result, (tuple, list)):
                result = result[0]
            result = F.softmax(result, dim=1).cpu().numpy()

        return result