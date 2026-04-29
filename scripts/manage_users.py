#!/usr/bin/env python3
"""
manage_users.py — Admin tool for user account management.

Edit main() at the bottom of this file, choose one operation, then run:
    python scripts/manage_users.py

Default admin/test users are managed by scripts/db.py. This script only manages
pre-issued participant accounts and their one-click login URLs.
"""
import os
import re
import secrets
import subprocess

try:
    import bcrypt
except ModuleNotFoundError:
    bcrypt = None

from _db_common import connect

# ── Pre-issued participant accounts ──────────────────────────────────────────
# username, password, subject_code, and course_ids are required. Email may be
# None, omitted, an empty string, or an actual email address. Subject and courses
# complete the same setup that participants choose in the web flow.
# Course IDs must belong to subject_code.
# Current subjects: BIOL courses 527, 528, 532; COSC courses 531, 533.
USERS_TO_CREATE = [
    {"username": "dummy1", "email": None, "password": "dummy1pass", "subject_code": "COSC", "course_ids": [531, 533]},
    {"username": "dummy2", "email": None, "password": "dummy2pass", "subject_code": "BIOL", "course_ids": [527, 528]},
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


def hash_password(plain_password: str) -> str:
    if bcrypt:
        return bcrypt.hashpw(
            plain_password.encode("utf-8"),
            bcrypt.gensalt(rounds=10),
        ).decode("utf-8")

    result = subprocess.run(
        ["php", "-r", "echo password_hash($argv[1], PASSWORD_BCRYPT);", plain_password],
        check=True,
        capture_output=True,
        text=True,
    )
    return result.stdout.strip()


def normalized_email(raw_email):
    email = str(raw_email or "").strip().lower()
    return email or None


def is_valid_password(password: str) -> bool:
    return (
        len(password) >= 4
        and re.search(r"[a-zA-Z]", password)
        and re.search(r"[0-9]", password)
    )


def get_subject_id(cur, code: str):
    if not code:
        return None
    cur.execute("SELECT id FROM subjects WHERE code = %s", (code,))
    row = cur.fetchone()
    return row[0] if row else None


def normalized_course_ids(raw_course_ids):
    if raw_course_ids is None:
        return [], []
    if isinstance(raw_course_ids, str):
        raw_values = [part.strip() for part in raw_course_ids.split(",")]
    elif isinstance(raw_course_ids, (list, tuple, set)):
        raw_values = raw_course_ids
    else:
        raw_values = [raw_course_ids]

    course_ids = []
    invalid_values = []
    for raw_id in raw_values:
        try:
            course_id = int(raw_id)
        except (TypeError, ValueError):
            invalid_values.append(raw_id)
            continue
        if course_id not in course_ids:
            course_ids.append(course_id)
    return course_ids, invalid_values


def valid_course_ids_for_subject(cur, course_ids, subject_id):
    if not course_ids:
        return set()
    placeholders = ", ".join(["%s"] * len(course_ids))
    cur.execute(
        f"""
        SELECT id
        FROM courses
        WHERE id IN ({placeholders})
          AND subject_id = %s
        """,
        (*course_ids, subject_id),
    )
    return {row[0] for row in cur.fetchall()}


def users_to_create_by_username():
    return {
        (spec.get("username") or "").strip(): spec
        for spec in USERS_TO_CREATE
        if (spec.get("username") or "").strip()
    }


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
        email     = normalized_email(spec.get("email"))
        password  = str(spec.get("password") or "")
        subj_code = (spec.get("subject_code") or "").strip()
        course_ids, invalid_course_values = normalized_course_ids(spec.get("course_ids"))

        if not username:
            print(f"  ✗ skip — missing username: {spec}")
            errored += 1
            continue
        if not is_valid_password(password):
            print(f"  ✗ {username:20s}  password must be at least 4 chars with one letter and one number")
            errored += 1
            continue
        if invalid_course_values:
            print(
                f"  ✗ {username:20s}  invalid course_ids value(s): "
                f"{', '.join(map(str, invalid_course_values))}"
            )
            errored += 1
            continue
        if not subj_code:
            print(f"  ✗ {username:20s}  missing subject_code")
            errored += 1
            continue
        if not course_ids:
            print(f"  ✗ {username:20s}  missing course_ids")
            errored += 1
            continue

        if email:
            cur.execute("SELECT id FROM users WHERE username = %s OR email = %s",
                        (username, email))
        else:
            cur.execute("SELECT id FROM users WHERE username = %s", (username,))
        if cur.fetchone():
            print(f"  ↪ {username:20s}  exists — skipped")
            skipped += 1
            continue

        subject_id = get_subject_id(cur, subj_code)
        if not subject_id:
            print(f"  ✗ {username:20s}  unknown subject_code '{subj_code}'")
            errored += 1
            continue

        valid_course_ids = valid_course_ids_for_subject(cur, course_ids, subject_id)
        invalid_course_ids = [cid for cid in course_ids if cid not in valid_course_ids]
        if invalid_course_ids:
            print(
                f"  ✗ {username:20s}  invalid course_ids for {subj_code}: "
                f"{', '.join(map(str, invalid_course_ids))}"
            )
            errored += 1
            continue

        token = gen_token()
        password_hash = hash_password(password)
        cur.execute("""
            INSERT INTO users
                (username, email, password_hash, subject_id, account_type,
                 consent_given, is_active, is_admin, login_token)
            VALUES (%s, %s, %s, %s, 'pre_issued', FALSE, TRUE, FALSE, %s)
        """, (username, email, password_hash, subject_id, token))
        user_id = cur.lastrowid

        for course_id in course_ids:
            cur.execute(
                "INSERT INTO user_courses (user_id, course_id) VALUES (%s, %s)",
                (user_id, course_id),
            )

        print(f"  ✓ {username:20s}  created")
        print(f"     subject: {subj_code} · courses: {', '.join(map(str, course_ids))}")
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


def retrieve_login_info(selected_usernames=None, refresh_pw=False, refresh_token=False):
    selected_usernames = [u.strip() for u in (selected_usernames or []) if u.strip()]
    specs_by_username = users_to_create_by_username()

    conn = connect()
    cur  = conn.cursor(dictionary=True)

    params = []
    username_filter = ""
    if selected_usernames:
        placeholders = ", ".join(["%s"] * len(selected_usernames))
        username_filter = f" AND username IN ({placeholders})"
        params.extend(selected_usernames)

    cur.execute(f"""
        SELECT id, username, email, account_type, password_hash, login_token, is_active
        FROM users
        WHERE account_type = 'pre_issued'
          AND is_admin = FALSE
          {username_filter}
        ORDER BY id
    """, params)
    users = cur.fetchall()

    if not users:
        if selected_usernames:
            print(f"No pre-issued users found for: {', '.join(selected_usernames)}")
        else:
            print("No pre-issued users.")
        cur.close()
        conn.close()
        return

    if selected_usernames:
        found = {u["username"] for u in users}
        missing = [u for u in selected_usernames if u not in found]
        if missing:
            print(f"Not found or not pre-issued: {', '.join(missing)}\n")

    print(f"\n→ Login info for {len(users)} pre-issued user(s):\n")
    for u in users:
        status = "active" if u['is_active'] else "DISABLED"
        spec = specs_by_username.get(u["username"], {})
        spec_password = str(spec.get("password") or "")
        password_note = ""

        if refresh_pw:
            if is_valid_password(spec_password):
                cur.execute(
                    "UPDATE users SET password_hash = %s WHERE id = %s",
                    (hash_password(spec_password), u["id"]),
                )
                password = spec_password
                password_note = "refreshed"
            else:
                password = "(not refreshed; no valid password in USERS_TO_CREATE)"
                password_note = "password unchanged"
        elif is_valid_password(spec_password):
            password = spec_password
            password_note = "from USERS_TO_CREATE"
        elif u["password_hash"]:
            password = "(stored hash only; plaintext not retrievable)"
            password_note = "use refresh_pw=True with a USERS_TO_CREATE password to reset"
        else:
            password = "(no password set)"
            password_note = "passwordless"

        token  = u['login_token']
        if refresh_token or not token:
            token = gen_token()
            cur.execute("UPDATE users SET login_token = %s WHERE id = %s",
                        (token, u["id"]))
            if refresh_token:
                status += ", refreshed token"
            else:
                status += ", token created"
        link = login_link(token)
        print(f"  id={u['id']:<4d}  {u['username']:20s}  "
              f"{u['email'] or '—':30s}  [{status}]")
        print(f"    username: {u['username']}")
        print(f"    password: {password} ({password_note})")
        print(f"    one-click: {link}\n")

    conn.commit()
    cur.close()
    conn.close()


def generate_login_urls(usernames=None, refresh_existing_tokens=False):
    retrieve_login_info(usernames, refresh_pw=False, refresh_token=refresh_existing_tokens)


# ── Entry point ───────────────────────────────────────────────────────────────
def main():
    # Choose exactly ONE operation by uncommenting one line below.
    #
    # operation = "create-users"          # Create USERS_TO_CREATE participant accounts.
                                         # This first verifies an active admin exists.
    # operation = "retrieve-login-info"   # Print username/password info and one-click URLs.
    # operation = "delete-users"          # Interactive deletion of non-admin users.
    operation = "create-users"                    # Keep this active when you do not want changes.

    # Used only when operation = "retrieve-login-info".
    # - [] means show info for every pre-issued non-admin user.
    # - ["dummy1", "dummy2"] limits output to selected usernames.
    selected_usernames = []

    # Used only when operation = "retrieve-login-info".
    # - refresh_pw=True resets each selected user's password to USERS_TO_CREATE password.
    # - refresh_token=True replaces existing one-click tokens, invalidating old URLs.
    refresh_pw = False
    refresh_token = False

    if operation == "create-users":
        create_users()
    elif operation in ("retrieve-login-info", "generate-login-urls"):
        retrieve_login_info(selected_usernames, refresh_pw, refresh_token)
    elif operation == "delete-users":
        delete_users()
    else:
        print("No user-management operation selected.")
        print("Edit scripts/manage_users.py main(), uncomment one operation, then run:")
        print("  python scripts/manage_users.py")


if __name__ == "__main__":
    main()
