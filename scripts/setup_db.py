#!/usr/bin/env python3
"""
Database Setup Script
=====================
Creates the database (if missing) and runs schema.sql to create tables + seed data.

Usage:
    python scripts/setup_db.py
"""
import sys
from pathlib import Path

from _db_common import PROJECT_ROOT, get_config, connect, split_sql_statements


def main():
    cfg = get_config()
    schema_file = PROJECT_ROOT / "sql" / "schema.sql"

    if not schema_file.exists():
        sys.stderr.write(f"ERROR: schema.sql not found at {schema_file}\n")
        sys.exit(1)

    print("→ Connecting to MySQL...")
    try:
        # Connect WITHOUT specifying a database (it may not exist yet)
        conn = connect(use_database=False)
    except Exception as e:
        sys.stderr.write(f"ERROR: Connection failed: {e}\n")
        sys.exit(1)

    cursor = conn.cursor()

    # The schema.sql includes CREATE DATABASE + USE statements
    print(f"→ Running schema from: {schema_file.name}")
    sql = schema_file.read_text(encoding="utf-8")
    statements = split_sql_statements(sql)

    print(f"  Found {len(statements)} statements to execute.")

    success_count = 0
    for i, stmt in enumerate(statements, start=1):
        try:
            cursor.execute(stmt)
            # Consume any results to avoid "Unread result found" on next execute
            try:
                while cursor.with_rows:
                    cursor.fetchall()
                    if not cursor.nextset():
                        break
            except Exception:
                pass
            success_count += 1
        except Exception as e:
            preview = stmt[:80].replace("\n", " ")
            sys.stderr.write(f"\n  ⚠ Statement {i} failed: {preview}...\n     {e}\n")

    conn.commit()
    cursor.close()
    conn.close()

    print(f"\n✓ {success_count}/{len(statements)} statements executed.")
    print(f"✓ Database '{cfg['database']}' is ready.\n")
    print("Next step: run `python scripts/seed_passwords.py` to set test user passwords.\n")


if __name__ == "__main__":
    main()
