#!/usr/bin/env python3
"""
manage_users.py — Admin tool for user account management.

Every command automatically ensures the admin account(s) defined in
ADMINS_TO_CREATE exist before doing anything else.  Run `create` once after
a fresh database setup to get the admin + all participant accounts in place.

Commands
────────
  create                        — ensure admins, then create USERS_TO_CREATE
  ensure-admins                 — create/verify admin account(s) only
  delete                        — interactive: delete non-admin users one by one
  list-links                    — print the login URL for every pre-issued user
  regenerate <username>         — generate a new login token for one user

Examples
────────
  python scripts/manage_users.py create
  python scripts/manage_users.py ensure-admins
  python scripts/manage_users.py list-links
  python scripts/manage_users.py regenerate alice
  python scripts/manage_users.py delete

Edit ADMINS_TO_CREATE and USERS_TO_CREATE below.
Re-running any command is safe — existing usernames/emails are skipped.
"""
import os
import sys
import secrets

import bcrypt

from _db_common import connect

# ── Admin accounts (always created / verified on every run) ──────────────────
# These accounts have is_admin=TRUE and a real password.
# Adding an entry here and re-running `create` or `ensure-admins` is safe:
# existing accounts are skipped; only new ones are inserted.
ADMINS_TO_CREATE = [
    {"username": "admin", "email": "admin@study.local", "password": "admin123"},
]

# ── Pre-issued participant accounts ──────────────────────────────────────────
# subject_code is optional; values must match a row in the `subjects` table
# (current options: 'BIOL', 'COSC'). Leave it out to skip subject assignment;
# admin can pick it later via admin/edit_user.php.
USERS_TO_CREATE = [
    {"username": "dummy1", "email": "dummy1@example.com", "subject_code": "COSC"},
    {"username": "dummy2", "email": "dummy2@example.com", "subject_code": "BIOL"},
]

# ── URL helpers ───────────────────────────────────────────────────────────────
_app_url  = os.getenv("APP_URL", "").strip()
_raw_base = os.getenv("BASE_URL", "/userstudy2/")
SERVER_HOST = os.getenv("SERVER_HOST", "http://localhost:8888")
if _app_url:
    BASE_URL = _app_url
elif _raw_base.startswith(("http://", "https://")):
    BASE_URL = _raw_base
else:
    BASE_URL = SERVER_HOST.rstrip("/") + "/" + _raw_base.lstrip("/")


# ── Helpers ───────────────────────────────────────────────────────────────────
def gen_token() -> str:
    return secrets.token_urlsafe(16)


def hash_password(plain: str) -> str:
    return bcrypt.hashpw(plain.encode(), bcrypt.gensalt()).decode()


def login_link(token: str) -> str:
    return f"{BASE_URL.rstrip('/')}/account/auto_login.php?token={token}"


def get_subject_id(cur, code: str):
    if not code:
        return None
    cur.execute("SELECT id FROM subjects WHERE code = %s", (code,))
    row = cur.fetchone()
    return row[0] if row else None


# ── Commands ──────────────────────────────────────────────────────────────────
def cmd_ensure_admins(conn=None):
    """Create admin accounts from ADMINS_TO_CREATE if they don't exist yet."""
    close_after = conn is None
    if conn is None:
        conn = connect()
    cur = conn.cursor()

    print("→ Ensuring admin account(s)...\n")
    created = skipped = errored = 0

    for spec in ADMINS_TO_CREATE:
        username = (spec.get("username") or "").strip()
        email    = (spec.get("email")    or "").strip().lower()
        password = (spec.get("password") or "").strip()

        if not username or not email or not password:
            print(f"  ✗ skip — ADMINS_TO_CREATE entry missing username/email/password: {spec}")
            errored += 1
            continue

        cur.execute("SELECT id FROM users WHERE username = %s OR email = %s",
                    (username, email))
        if cur.fetchone():
            print(f"  ↪ {username:20s}  already exists — skipped")
            skipped += 1
            continue

        pw_hash = hash_password(password)
        cur.execute("""
            INSERT INTO users
                (username, email, password_hash, account_type,
                 consent_given, is_active, is_admin)
            VALUES (%s, %s, %s, 'pre_issued', TRUE, TRUE, TRUE)
        """, (username, email, pw_hash))

        print(f"  ✓ {username:20s}  admin created")
        created += 1

    conn.commit()
    cur.close()
    if close_after:
        conn.close()

    print(f"\n✓ Admins: {created} created · {skipped} already existed · {errored} errored.\n")


def cmd_create():
    conn = connect()

    # Always ensure admins exist first
    cmd_ensure_admins(conn)

    if not USERS_TO_CREATE:
        print("USERS_TO_CREATE is empty. Edit this file to add participant accounts.")
        conn.close()
        return

    cur = conn.cursor()
    print(f"→ Creating up to {len(USERS_TO_CREATE)} pre-issued participant(s)...\n")
    created = skipped = errored = 0

    for spec in USERS_TO_CREATE:
        username  = (spec.get("username") or "").strip()
        email     = (spec.get("email")    or "").strip().lower()
        subj_code = (spec.get("subject_code") or "").strip()

        if not username or not email:
            print(f"  ✗ skip — missing username/email: {spec}")
            errored += 1
            continue

        cur.execute("SELECT id FROM users WHERE username = %s OR email = %s",
                    (username, email))
        if cur.fetchone():
            print(f"  ↪ {username:20s}  exists — skipped")
            skipped += 1
            continue

        subject_id = get_subject_id(cur, subj_code)
        if subj_code and not subject_id:
            print(f"  ✗ {username:20s}  unknown subject_code '{subj_code}'")
            errored += 1
            continue

        token = gen_token()
        cur.execute("""
            INSERT INTO users
                (username, email, password_hash, subject_id, account_type,
                 consent_given, is_active, is_admin, login_token)
            VALUES (%s, %s, '', %s, 'pre_issued', FALSE, TRUE, FALSE, %s)
        """, (username, email, subject_id, token))

        print(f"  ✓ {username:20s}  created")
        print(f"     → {login_link(token)}")
        created += 1

    conn.commit()
    cur.close()
    conn.close()
    print(f"\n✓ Participants: {created} created · {skipped} already existed · {errored} errored.\n")


def cmd_delete():
    conn = connect()
    cur  = conn.cursor(dictionary=True)
    cur.execute("""
        SELECT id, username, email, account_type, created_at
        FROM users
        WHERE is_admin = FALSE
        ORDER BY id
    """)
    users = cur.fetchall()

    if not users:
        print("No deletable users.")
        cur.close(); conn.close()
        return

    print(f"\n→ {len(users)} deletable user(s). For each: y = delete, "
          "Enter/n = skip, q = quit.\n")
    deleted = 0

    for u in users:
        print(f"  id={u['id']:<4d}  {u['username']:20s}  "
              f"{u['email'] or '—':30s}  [{u['account_type']}]")
        ans = input("  Delete? [y/N/q]: ").strip().lower()
        if ans == 'q':
            print("  → aborted")
            break
        if ans == 'y':
            cur.execute("DELETE FROM users WHERE id = %s", (u['id'],))
            conn.commit()
            print(f"  ✗ deleted\n")
            deleted += 1
        else:
            print(f"  ↪ skipped\n")

    cur.close()
    conn.close()
    print(f"✓ {deleted} user(s) deleted.\n")


def cmd_list_links():
    conn = connect()
    cur  = conn.cursor(dictionary=True)
    cur.execute("""
        SELECT id, username, email, login_token, is_active
        FROM users
        WHERE account_type = 'pre_issued' AND is_admin = FALSE
        ORDER BY id
    """)
    users = cur.fetchall()
    cur.close()
    conn.close()

    if not users:
        print("No pre-issued users.")
        return

    print(f"\n→ {len(users)} pre-issued user(s):\n")
    for u in users:
        status = "active" if u['is_active'] else "DISABLED"
        token  = u['login_token']
        link   = login_link(token) if token else \
                 "(no token — run: regenerate " + u['username'] + ")"
        print(f"  id={u['id']:<4d}  {u['username']:20s}  "
              f"{u['email'] or '—':30s}  [{status}]")
        print(f"    {link}\n")


def cmd_regenerate(username):
    conn = connect()
    cur  = conn.cursor(dictionary=True)
    cur.execute("SELECT id, account_type FROM users WHERE username = %s",
                (username,))
    u = cur.fetchone()

    if not u:
        print(f"User '{username}' not found.")
        cur.close(); conn.close(); return
    if u['account_type'] != 'pre_issued':
        print(f"User '{username}' is not a pre_issued account "
              "(login links only apply to admin-created users).")
        cur.close(); conn.close(); return

    token = gen_token()
    cur.execute("UPDATE users SET login_token = %s WHERE id = %s",
                (token, u['id']))
    conn.commit()
    cur.close()
    conn.close()
    print(f"✓ {username}  →  {login_link(token)}")


# ── Entry point ───────────────────────────────────────────────────────────────
def main():
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)

    cmd = sys.argv[1]

    if   cmd == 'create':                              cmd_create()
    elif cmd == 'ensure-admins':                       cmd_ensure_admins()
    elif cmd == 'delete':                              cmd_delete()
    elif cmd == 'list-links':                          cmd_list_links()
    elif cmd == 'regenerate' and len(sys.argv) == 3:  cmd_regenerate(sys.argv[2])
    else:
        print("Unknown command. Use:")
        print("  create | ensure-admins | delete | list-links | regenerate <username>")
        sys.exit(1)


if __name__ == "__main__":
    main()
