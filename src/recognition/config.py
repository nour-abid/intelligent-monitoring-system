import yaml
from pathlib import Path

def find_root(start: Path) -> Path:
    for p in [start] + list(start.parents):
        if (p / "models" / "embeddings").exists():
            return p
    return start

SCRIPT_DIR = Path(__file__).resolve().parent
ROOT_DIR = find_root(SCRIPT_DIR)

CONFIG_PATH = ROOT_DIR / "config.yaml"

def load_config():
    with open(CONFIG_PATH, 'r') as f:
        config = yaml.safe_load(f)
    # Resolve paths
    config['paths'] = {
        'emb_dir': ROOT_DIR / "models" / "embeddings",
        'db_path': ROOT_DIR / config['database']['path']
    }
    return config

config = load_config()