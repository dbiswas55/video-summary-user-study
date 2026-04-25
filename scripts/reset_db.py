#!/usr/bin/env python3
"""
Reset Database (DEV ONLY)
=========================
Drops the database and recreates it from scratch.
Useful during development when you want a clean slate.

DANGER: This DELETES ALL DATA. Has a confirmation prompt for safety.

Usage:
    python scripts/reset_db.py
"""
import subprocess
import sys
from pathlib import Path

from _db_common import PROJECT_ROOT, get_config, connect


def main():
    cfg = get_config()
    db_name = cfg["database"]

    print("=" * 60)
    print("  ⚠  DATABASE RESET — THIS DELETES ALL DATA")
    print("=" * 60)
    print(f"  Database: {db_name}")
    print(f"  Host:     {cfg['host']}:{cfg['port']}")
    print()

    confirm = input(f"Type the database name '{db_name}' to confirm: ").strip()
    if confirm != db_name:
        print("\n✗ Aborted — confirmation did not match.\n")
        sys.exit(0)

    print("\n→ Dropping database...")
    try:
        conn = connect(use_database=False)
        cursor = conn.cursor()
        cursor.execute(f"DROP DATABASE IF EXISTS `{db_name}`")
        conn.commit()
        cursor.close()
        conn.close()
        print(f"  ✓ Dropped: {db_name}")
    except Exception as e:
        sys.stderr.write(f"ERROR: {e}\n")
        sys.exit(1)

    print("\n→ Recreating from schema...")
    setup_script = Path(__file__).parent / "setup_db.py"
    result = subprocess.run([sys.executable, str(setup_script)])
    if result.returncode != 0:
        sys.stderr.write("\nSchema setup failed.\n")
        sys.exit(1)

    print("→ Setting seed passwords...")
    seed_script = Path(__file__).parent / "seed_passwords.py"
    subprocess.run([sys.executable, str(seed_script)])

    print("\n✓ Reset complete.\n")


if __name__ == "__main__":
    main()
