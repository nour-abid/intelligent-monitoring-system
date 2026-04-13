"""Surveillance package — load .env on import so DB credentials are available."""
from pathlib import Path
from dotenv import load_dotenv

# Locate the project root (.env sits next to requirements.txt / surveillance/)
_ENV_FILE = Path(__file__).resolve().parent.parent / ".env"

# override=False: OS environment variables already set take priority over .env
load_dotenv(_ENV_FILE, override=False)
