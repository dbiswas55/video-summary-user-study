#!/usr/bin/env python3
"""
Phase 0 database utility.

Edit main() at the bottom of this file, choose one operation, then run:
    python scripts/db.py
"""
import re
import sys
from datetime import datetime

from _db_common import PROJECT_ROOT, get_config, connect, split_sql_statements


SEED_USERS = [
    {
        "username": "admin",
        "email": "admin@example.com",
        "password": "admin@123",
        "subject_id": None,
        "account_type": "pre_issued",
        "consent_given": True,
        "consent_version": None,
        "consent_timestamp": None,
        "is_admin": True,
        "course_ids": [],
    },
    {
        "username": "test02",
        "email": "test02@example.com",
        "password": "test02",
        "subject_id": 2,
        "account_type": "pre_issued",
        "consent_given": True,
        "consent_version": "v1.0",
        "consent_timestamp": datetime.now(),
        "is_admin": False,
        "course_ids": [531, 533],
    },
    {
        "username": "test03",
        "email": "test03@example.com",
        "password": "test03",
        "subject_id": 1,
        "account_type": "pre_issued",
        "consent_given": True,
        "consent_version": "v1.0",
        "consent_timestamp": datetime.now(),
        "is_admin": False,
        "course_ids": [527, 528],
    }
]


def quote_identifier(name):
    return "`" + name.replace("`", "``") + "`"


def hash_password(plain_password):
    import bcrypt

    return bcrypt.hashpw(
        plain_password.encode("utf-8"),
        bcrypt.gensalt(rounds=10),
    ).decode("utf-8")


def run_schema():
    cfg = get_config()
    schema_file = PROJECT_ROOT / "app" / "sql" / "schema.sql"

    if not schema_file.exists():
        sys.stderr.write(f"ERROR: schema.sql not found at {schema_file}\n")
        sys.exit(1)

    print("Connecting to MySQL...")
    try:
        conn = connect(use_database=False)
    except Exception as e:
        sys.stderr.write(f"ERROR: Connection failed: {e}\n")
        sys.exit(1)

    cursor = conn.cursor()
    database_name = quote_identifier(cfg["database"])
    sql = schema_file.read_text(encoding="utf-8")
    sql = re.sub(
        r"CREATE DATABASE IF NOT EXISTS\s+`?[\w-]+`?",
        f"CREATE DATABASE IF NOT EXISTS {database_name}",
        sql,
        count=1,
    )
    sql = re.sub(
        r"USE\s+`?[\w-]+`?\s*;",
        f"USE {database_name};",
        sql,
        count=1,
    )
    statements = split_sql_statements(sql)

    print(f"Running schema from {schema_file.name}")
    print(f"Found {len(statements)} statements to execute.")

    success_count = 0
    failure_count = 0
    for i, stmt in enumerate(statements, start=1):
        try:
            cursor.execute(stmt)
            try:
                while cursor.with_rows:
                    cursor.fetchall()
                    if not cursor.nextset():
                        break
            except Exception:
                pass
            success_count += 1
        except Exception as e:
            failure_count += 1
            preview = stmt[:80].replace("\n", " ")
            sys.stderr.write(f"\nStatement {i} failed: {preview}...\n{e}\n")

    conn.commit()
    cursor.close()
    conn.close()

    print(f"\nSchema statements executed: {success_count}/{len(statements)}")
    if failure_count:
        sys.stderr.write(f"ERROR: {failure_count} schema statement(s) failed.\n")
        sys.exit(1)

    print(f"Database '{cfg['database']}' is ready.")


def default_users():
    print("\nRefreshing default Phase 0 users...")
    try:
        conn = connect(use_database=True)
    except Exception as e:
        sys.stderr.write(f"ERROR: Connection failed: {e}\n")
        sys.exit(1)

    cursor = conn.cursor()
    upserted = 0

    for user in SEED_USERS:
        hashed = hash_password(user["password"])

        cursor.execute("SELECT id FROM users WHERE username = %s", (user["username"],))
        existing = cursor.fetchone()

        if existing:
            user_id = existing[0]
            cursor.execute(
                """
                UPDATE users
                SET email = %s,
                    password_hash = %s,
                    subject_id = %s,
                    account_type = %s,
                    consent_given = %s,
                    consent_version = %s,
                    is_admin = %s,
                    is_active = TRUE
                WHERE id = %s
                """,
                (
                    user["email"],
                    hashed,
                    user["subject_id"],
                    user["account_type"],
                    user["consent_given"],
                    user["consent_version"],
                    user["is_admin"],
                    user_id,
                ),
            )
        else:
            cursor.execute(
                """
                INSERT INTO users
                    (username, email, password_hash, subject_id, account_type,
                     consent_given, consent_version, consent_timestamp,
                     is_admin, is_active)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, TRUE)
                """,
                (
                    user["username"],
                    user["email"],
                    hashed,
                    user["subject_id"],
                    user["account_type"],
                    user["consent_given"],
                    user["consent_version"],
                    user["consent_timestamp"],
                    user["is_admin"],
                ),
            )
            user_id = cursor.lastrowid

        if not user["is_admin"]:
            course_ids = user.get("course_ids", [])
            cursor.execute("DELETE FROM user_courses WHERE user_id = %s", (user_id,))
            if course_ids:
                placeholders = ", ".join(["%s"] * len(course_ids))
                cursor.execute(
                    f"""
                    SELECT id
                    FROM courses
                    WHERE id IN ({placeholders})
                      AND subject_id = %s
                    """,
                    (*course_ids, user["subject_id"]),
                )
                valid_course_ids = {row[0] for row in cursor.fetchall()}
                missing_course_ids = sorted(set(course_ids) - valid_course_ids)
                if missing_course_ids:
                    sys.stderr.write(
                        f"WARNING: {user['username']} skipped invalid course id(s): "
                        f"{', '.join(map(str, missing_course_ids))}\n"
                    )
                for course_id in course_ids:
                    if course_id in valid_course_ids:
                        cursor.execute(
                            "INSERT INTO user_courses (user_id, course_id) VALUES (%s, %s)",
                            (user_id, course_id),
                        )

        course_note = ""
        if not user["is_admin"] and user.get("course_ids"):
            course_note = f" · courses {', '.join(map(str, user['course_ids']))}"
        print(f"  {user['username']:12s} password set{course_note}")
        upserted += 1

    conn.commit()
    cursor.close()
    conn.close()

    print(f"\nDefault users ready: {upserted}")
    print("Default logins:")
    for user in SEED_USERS:
        print(f"  {user['username']:12s} / {user['password']}")


def setup_database():
    run_schema()
    default_users()
    print("\nSetup complete.")


def reset_database():
    cfg = get_config()
    db_name = cfg["database"]

    print("=" * 60)
    print("  DATABASE RESET - THIS DELETES ALL DATA")
    print("=" * 60)
    print(f"  Database: {db_name}")
    print(f"  Host:     {cfg['host']}:{cfg['port']}")
    print()

    confirm = input(f"Type the database name '{db_name}' to confirm: ").strip()
    if confirm != db_name:
        print("\nAborted - confirmation did not match.\n")
        return

    print("\nDropping database...")
    try:
        conn = connect(use_database=False)
        cursor = conn.cursor()
        cursor.execute(f"DROP DATABASE IF EXISTS {quote_identifier(db_name)}")
        conn.commit()
        cursor.close()
        conn.close()
    except Exception as e:
        sys.stderr.write(f"ERROR: {e}\n")
        sys.exit(1)

    print(f"Dropped: {db_name}\n")
    setup_database()


def main():
    # Choose exactly ONE operation by uncommenting one line below.
    #
    # Important shared setting:
    # - The target database name comes from DB_NAME in the project-root .env file.
    # - Example .env assignment: DB_NAME=userstudy_vds
    # - reset_database() will ask you to type that exact database name before it
    #   drops anything.
    #
    # Operation choices:
    # operation = "setup"       # Create/update schema from app/sql/schema.sql,
                                # then insert/update the default admin/test users.
    # operation = "default-users"  # Only insert/update SEED_USERS above. Use this to
                                # refresh admin/test passwords without touching tables.
    # operation = "reset"       # DEV ONLY. Drop DB_NAME, recreate schema, then seed
                                # users. This deletes all existing study data.
    operation = "setup"            # Keep this active when you do not want any DB change.

    if operation == "setup":
        setup_database()
    elif operation == "default-users":
        default_users()
    elif operation == "reset":
        reset_database()
    else:
        print("No database operation selected.")
        print("Edit scripts/db.py main(), uncomment one operation, then run:")
        print("  python scripts/db.py")


if __name__ == "__main__":
    main()
