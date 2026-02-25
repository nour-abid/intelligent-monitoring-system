import torch
from src.model_lib.MiniFASNet import MiniFASNetV2, MiniFASNetV1SE

# init test
m1 = MiniFASNetV2(conv6_kernel=(5,5), num_classes=3)
m2 = MiniFASNetV1SE(conv6_kernel=(5,5), num_classes=3)
print("Model init OK")

# load test
sd1 = torch.load("recognition/recognition/2.7_80x80_MiniFASNetV2.pth", map_location="cpu")
sd2 = torch.load("recognition/recognition/4_0_0_80x80_MiniFASNetV1SE.pth", map_location="cpu")

# some checkpoints wrap state_dict
if isinstance(sd1, dict) and "state_dict" in sd1: sd1 = sd1["state_dict"]
if isinstance(sd2, dict) and "state_dict" in sd2: sd2 = sd2["state_dict"]

# remove DataParallel prefix if present
sd1 = {k.replace("module.", ""): v for k, v in sd1.items()}
sd2 = {k.replace("module.", ""): v for k, v in sd2.items()}

print(m1.load_state_dict(sd1, strict=False))
print(m2.load_state_dict(sd2, strict=False))
print("Weights load test finished")