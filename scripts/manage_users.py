#!/usr/bin/env python3
"""
manage_users.py — Admin tool for user account management.

Edit main() at the bottom of this file, choose one operation, then run:
    python scripts/manage_users.py

Default admin/test users are managed by scripts/db.py. This script only manages
pre-issued participant accounts and their one-click login URLs.
"""
import os
import secrets

from _db_common import connect

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


def login_link(token: str) -> str:
    return f"{BASE_URL.rstrip('/')}/account/auto_login.php?token={token}"


def get_subject_id(cur, code: str):
    if not code:
        return None
    cur.execute("SELECT id FROM subjects WHERE code = %s", (code,))
    row = cur.fetchone()
    return row[0] if row else None


# ── Operations ────────────────────────────────────────────────────────────────
def ensure_admins_exist(cur):
    """Verify at least one active admin exists before participant creation."""
    cur.execute("SELECT COUNT(*) FROM users WHERE is_admin = TRUE AND is_active = TRUE")
    count = cur.fetchone()[0]
    if count <= 0:
        print("No active admin account found.")
        print('Run scripts/db.py with operation = "setup" or "default-users" first.')
        return False
    print(f"✓ Active admin account(s) found: {count}")
    return True


def create_users():
    conn = connect()
    cur = conn.cursor()

    # Admin accounts are created by scripts/db.py. This check is only a safety
    # step before creating participant accounts.
    if not ensure_admins_exist(cur):
        cur.close()
        conn.close()
        return

    if not USERS_TO_CREATE:
        print("USERS_TO_CREATE is empty. Edit this file to add participant accounts.")
        cur.close()
        conn.close()
        return

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


def delete_users():
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


def generate_login_urls(usernames=None, refresh_existing_tokens=False):
    usernames = [u.strip() for u in (usernames or []) if u.strip()]

    conn = connect()
    cur  = conn.cursor(dictionary=True)

    params = []
    username_filter = ""
    if usernames:
        placeholders = ", ".join(["%s"] * len(usernames))
        username_filter = f" AND username IN ({placeholders})"
        params.extend(usernames)

    cur.execute(f"""
        SELECT id, username, email, account_type, login_token, is_active
        FROM users
        WHERE account_type = 'pre_issued'
          AND is_admin = FALSE
          {username_filter}
        ORDER BY id
    """, params)
    users = cur.fetchall()

    if not users:
        if usernames:
            print(f"No pre-issued users found for: {', '.join(usernames)}")
        else:
            print("No pre-issued users.")
        cur.close()
        conn.close()
        return

    if usernames:
        found = {u["username"] for u in users}
        missing = [u for u in usernames if u not in found]
        if missing:
            print(f"Not found or not pre-issued: {', '.join(missing)}\n")

    print(f"\n→ Login URLs for {len(users)} pre-issued user(s):\n")
    for u in users:
        status = "active" if u['is_active'] else "DISABLED"
        token  = u['login_token']
        if refresh_existing_tokens or not token:
            token = gen_token()
            cur.execute("UPDATE users SET login_token = %s WHERE id = %s",
                        (token, u["id"]))
            if refresh_existing_tokens:
                status += ", refreshed token"
            else:
                status += ", token created"
        link = login_link(token)
        print(f"  id={u['id']:<4d}  {u['username']:20s}  "
              f"{u['email'] or '—':30s}  [{status}]")
        print(f"    {link}\n")

    conn.commit()
    cur.close()
    conn.close()


# ── Entry point ───────────────────────────────────────────────────────────────
def main():
    # Choose exactly ONE operation by uncommenting one line below.
    #
    # operation = "create-users"          # Create USERS_TO_CREATE participant accounts.
                                         # This first verifies an active admin exists.
    # operation = "generate-login-urls"   # Print one-click login URLs for pre-issued users.
    # operation = "delete-users"          # Interactive deletion of non-admin users.
    operation = None                     # Keep this active when you do not want changes.

    # Used only when operation = "generate-login-urls".
    # - [] means generate URLs for every pre-issued non-admin user.
    # - ["dummy1", "dummy2"] limits output to selected usernames.
    login_url_usernames = []

    # Used only when operation = "generate-login-urls".
    # - False keeps existing tokens and creates tokens only when missing.
    # - True replaces existing tokens, invalidating old one-click URLs.
    refresh_existing_login_tokens = False

    if operation == "create-users":
        create_users()
    elif operation == "generate-login-urls":
        generate_login_urls(login_url_usernames, refresh_existing_login_tokens)
    elif operation == "delete-users":
        delete_users()
    else:
        print("No user-management operation selected.")
        print("Edit scripts/manage_users.py main(), uncomment one operation, then run:")
        print("  python scripts/manage_users.py")


if __name__ == "__main__":
    main()
