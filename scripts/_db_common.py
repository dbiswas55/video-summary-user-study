"""
Shared helpers for database scripts.
Loads .env from project root and provides a connect() function.
"""
import os
import sys
from pathlib import Path

import mysql.connector
from dotenv import load_dotenv

PROJECT_ROOT = Path(__file__).resolve().parent.parent
ENV_PATH = PROJECT_ROOT / ".env"

if not ENV_PATH.exists():
    sys.stderr.write(
        f"\nERROR: .env file not found at {ENV_PATH}\n"
        "Copy .env.example to .env and fill in your credentials.\n\n"
    )
    sys.exit(1)

load_dotenv(ENV_PATH)


def get_config():
    """Read DB config from environment."""
    return {
        "host":     os.getenv("DB_HOST", "localhost"),
        "port":     int(os.getenv("DB_PORT", "3306")),
        "user":     os.getenv("DB_USER", "root"),
        "password": os.getenv("DB_PASS", ""),
        "database": os.getenv("DB_NAME", "userstudy_vds"),
        "socket":   os.getenv("DB_SOCKET", "").strip() or None,
    }


def connect(use_database=True):
    """
    Connect to MySQL.
    Set use_database=False for initial setup (database may not exist yet).
    """
    cfg = get_config()
    kwargs = {
        "host": cfg["host"],
        "port": cfg["port"],
        "user": cfg["user"],
        "password": cfg["password"],
    }

    # MAMP socket support (Mac only — leave DB_SOCKET empty on Windows/AWS)
    if cfg["socket"] and Path(cfg["socket"]).exists():
        kwargs["unix_socket"] = cfg["socket"]

    if use_database:
        kwargs["database"] = cfg["database"]

    return mysql.connector.connect(**kwargs)


def split_sql_statements(sql_text):
    """Naive SQL splitter — splits on ; but ignores ; inside strings/comments."""
    statements = []
    buffer = []
    in_string = False
    string_char = None

    for line in sql_text.splitlines():
        stripped = line.strip()
        # Skip empty lines and full-line comments
        if not stripped or stripped.startswith("--"):
            continue
        buffer.append(line)

    text = "\n".join(buffer)
    current = []
    for ch in text:
        if in_string:
            current.append(ch)
            if ch == string_char:
                in_string = False
        else:
            if ch in ("'", '"'):
                in_string = True
                string_char = ch
                current.append(ch)
            elif ch == ";":
                stmt = "".join(current).strip()
                if stmt:
                    statements.append(stmt)
                current = []
            else:
                current.append(ch)

    last = "".join(current).strip()
    if last:
        statements.append(last)

    return statements
