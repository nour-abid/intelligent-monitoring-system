import logging
import logging.handlers
from pathlib import Path

logs_dir = Path("logs")
logs_dir.mkdir(exist_ok=True)

def setup_logging():
    logger = logging.getLogger()  # root logger

    # ✅ Let DEBUG messages flow to handlers
    logger.setLevel(logging.DEBUG)

    # ✅ Avoid duplicate handlers if imported multiple times
    if logger.handlers:
        return logger

    handler = logging.handlers.RotatingFileHandler(
        logs_dir / "system.log",
        maxBytes=10 * 1024 * 1024,  # 10MB
        backupCount=5,
        encoding="utf-8"
    )
    handler.setLevel(logging.DEBUG)

    formatter = logging.Formatter(
        "%(asctime)s - %(name)s - %(levelname)s - %(message)s"
    )
    handler.setFormatter(formatter)

    logger.addHandler(handler)

    # (optional) also show logs in terminal
    console = logging.StreamHandler()
    console.setLevel(logging.INFO)
    console.setFormatter(formatter)
    logger.addHandler(console)

    return logger

logger = setup_logging()