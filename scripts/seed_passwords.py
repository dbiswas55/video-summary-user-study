#!/usr/bin/env python3
"""
Seed User Passwords
===================
Sets bcrypt password hashes for the seed test users (testuser, admin).
Run this AFTER setup_db.py.

Usage:
    python scripts/seed_passwords.py
"""
import sys

import bcrypt

from _db_common import connect

# Edit here if you want different default passwords
SEED_USERS = {
    "testuser": "testpass123",
    "admin":    "admin123",
}


def main():
    print("→ Setting passwords for seed users...\n")

    try:
        conn = connect()
    except Exception as e:
        sys.stderr.write(f"ERROR: Connection failed: {e}\n")
        sys.exit(1)

    cursor = conn.cursor()
    updated = 0

    for username, plain_password in SEED_USERS.items():
        hashed = bcrypt.hashpw(
            plain_password.encode("utf-8"),
            bcrypt.gensalt(rounds=10)
        ).decode("utf-8")

        cursor.execute(
            "UPDATE users SET password_hash = %s WHERE username = %s",
            (hashed, username)
        )

        if cursor.rowcount > 0:
            print(f"  ✓ {username:12s} → password set")
            updated += 1
        else:
            print(f"  ⚠ {username:12s} → user not found in DB (skipped)")

    conn.commit()
    cursor.close()
    conn.close()

    print(f"\n✓ {updated} user(s) updated.\n")
    print("Test logins:")
    for username, password in SEED_USERS.items():
        print(f"  {username:12s} / {password}")
    print()


if __name__ == "__main__":
    main()
