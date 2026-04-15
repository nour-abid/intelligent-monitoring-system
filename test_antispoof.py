#!/usr/bin/env python
import sys
sys.path.insert(0, 'src')

print("Testing MiniFASNet import...")
try:
    from anti_spoof_predict import AntiSpoofPredict
    print("[OK] AntiSpoofPredict imported successfully")

    # Try to instantiate (might fail due to missing detection model, but that's ok)
    spoof_model = AntiSpoofPredict(device_id=0)
    print("[OK] AntiSpoofPredict instantiated")
except Exception as e:
    print(f"[ERROR] Failed to import/instantiate AntiSpoofPredict: {e}")
    import traceback
    traceback.print_exc()

print("\nTesting MiniFASNet model files...")
from pathlib import Path
model_dir = Path("resources/anti_spoof_models")
if model_dir.exists():
    models = list(model_dir.glob("*.pth"))
    print(f"[OK] Found {len(models)} model files:")
    for m in models:
        print(f"  - {m.name}")
else:
    print(f"[ERROR] Model directory not found: {model_dir}")

print("\nAll tests completed!")
