#!/usr/bin/env python3
"""
sync_videos.py — Sync the videos/segments database with the resources filesystem.

Scans resources/ for all i{instructor_id}/v{video_id}/ folders, compares with the
database, and reports what is new, in-sync, or stale. Can add new videos or
delete existing ones.

Usage (run from the project root):
    Edit main() at the bottom of this file, choose one operation, then run:
        python scripts/sync_videos.py
"""

import sys
import json
import re
import random
from pathlib import Path

# ── Ensure scripts/ is on path so _db_common works when called from project root ─
sys.path.insert(0, str(Path(__file__).resolve().parent))
from _db_common import PROJECT_ROOT, connect

# ──────────────────────────────────────────────────────────────────────────────
# Constants
# ──────────────────────────────────────────────────────────────────────────────

REQUIRED_FILES = (
    "metadata.json",
    "transcript.txt",
    "transcript_summary.txt",
    "multimodal_summary.txt",
)

# ──────────────────────────────────────────────────────────────────────────────
# Helpers
# ──────────────────────────────────────────────────────────────────────────────

def fmt_time(seconds: float) -> str:
    s = int(round(seconds))
    return f"{s // 60:02d}:{s % 60:02d}"


def scan_resource_folders(resources_root: Path) -> list[tuple[int, int]]:
    """Return sorted list of (instructor_id, video_id) pairs found on disk."""
    found = []
    inst_pattern = re.compile(r"^i(\d+)$")
    vid_pattern  = re.compile(r"^v(\d+)$")

    if not resources_root.is_dir():
        return found

    for inst_dir in sorted(resources_root.iterdir()):
        m = inst_pattern.match(inst_dir.name)
        if not m or not inst_dir.is_dir():
            continue
        instructor_id = int(m.group(1))
        for vid_dir in sorted(inst_dir.iterdir()):
            vm = vid_pattern.match(vid_dir.name)
            if not vm or not vid_dir.is_dir():
                continue
            found.append((instructor_id, int(vm.group(1))))

    return found


def check_folder(base: Path) -> dict:
    """Inspect a resource folder. Returns a dict with file status and mp4 name."""
    result = {"missing": [], "mp4": ""}

    for fname in REQUIRED_FILES:
        if not (base / fname).exists():
            result["missing"].append(fname)

    if not (base / "slides").is_dir():
        result["missing"].append("slides/")

    mp4s = list(base.glob("*.mp4"))
    if not mp4s:
        result["missing"].append("*.mp4")
    else:
        result["mp4"] = mp4s[0].name

    return result


def load_metadata(base: Path) -> dict:
    path = base / "metadata.json"
    if not path.exists():
        return {}
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def db_videos(cursor) -> dict[int, dict]:
    """Return {video_id: row} for all videos currently in the DB."""
    cursor.execute("""
        SELECT v.id AS internal_id, v.video_id, v.instructor_id, v.course_id,
               v.video_filename,
               c.code AS course_code,
               s.id   AS segment_id, s.title AS segment_title,
               s.version_assignment
        FROM videos v
        JOIN courses c  ON c.id = v.course_id
        LEFT JOIN segments s ON s.video_id = v.id
        ORDER BY v.video_id
    """)
    return {r["video_id"]: r for r in cursor.fetchall()}


def db_courses_by_instructor(cursor) -> dict[int, dict]:
    """Return {instructor_id: course_row} — error if instructor has multiple courses."""
    cursor.execute("SELECT id, code, name, instructor_id FROM courses")
    by_inst: dict[int, list] = {}
    for r in cursor.fetchall():
        by_inst.setdefault(r["instructor_id"], []).append(r)
    return by_inst


# ──────────────────────────────────────────────────────────────────────────────
# Add operation
# ──────────────────────────────────────────────────────────────────────────────

def add_video(instructor_id: int, video_id: int, resources_root: Path,
              cursor, dry_run: bool) -> bool:
    """
    Import one video folder into the database.
    Returns True on success, False on error/skip.
    """
    base = resources_root / f"i{instructor_id}" / f"v{video_id}"
    info = check_folder(base)

    if info["missing"]:
        print(f"    ⚠  Missing files: {', '.join(info['missing'])} — skipping")

    meta = load_metadata(base)
    if not meta:
        print(f"    ✗  Cannot read metadata.json — skipping")
        return False

    chapter_num = int(meta.get("chapter_number", 1))
    title       = meta.get("section_title", "")
    duration_s  = float(meta.get("duration", 0.0))
    start_s     = 0.0
    end_s       = round(duration_s, 4)
    slide_start = int(meta.get("slide_index_start", 0))
    slide_end   = int(meta.get("slide_index_end",   0))
    video_filename = info["mp4"]

    print(f"    Chapter {chapter_num}: {title}")
    print(f"    Duration: {fmt_time(duration_s)}  ({duration_s:.4f}s)")
    print(f"    Slides:   {slide_start}–{slide_end}")
    print(f"    Video file: {video_filename or '(none)'}")

    # Resolve course from instructor_id
    by_inst = db_courses_by_instructor(cursor)
    courses = by_inst.get(instructor_id, [])
    if not courses:
        print(f"    ✗  No course found for instructor_id={instructor_id} — skipping")
        return False
    if len(courses) > 1:
        print(f"    ✗  Ambiguous: instructor {instructor_id} has {len(courses)} courses — skipping")
        return False
    course    = courses[0]
    course_id = course["id"]
    print(f"    Course:   {course['code']} (id={course_id})")

    # Randomly assign Version A / B
    assignment = random.choice(["normal", "swapped"])
    summary_a  = "transcript_summary.txt" if assignment == "normal" else "multimodal_summary.txt"
    summary_b  = "multimodal_summary.txt" if assignment == "normal" else "transcript_summary.txt"
    print(f"    Version:  {assignment}  (A={summary_a.replace('_summary.txt','')})")

    if dry_run:
        print(f"    [dry-run] Would insert into videos + segments")
        return True

    cursor.execute("""
        INSERT INTO videos (course_id, instructor_id, video_id, video_filename)
        VALUES (%s, %s, %s, %s)
    """, (course_id, instructor_id, video_id, video_filename))
    internal_id = cursor.lastrowid

    cursor.execute("""
        INSERT INTO segments
            (video_id, chapter_num, title, start_s, end_s, duration_s,
             slide_range_start, slide_range_end,
             summary_a_file, summary_b_file, version_assignment, display_order)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
    """, (
        internal_id, chapter_num, title,
        start_s, end_s, duration_s,
        slide_start, slide_end,
        summary_a, summary_b, assignment,
        chapter_num,
    ))
    print(f"    ✓ Inserted — videos.id={internal_id}, segments.id={cursor.lastrowid}")
    return True


# ──────────────────────────────────────────────────────────────────────────────
# Delete operation
# ──────────────────────────────────────────────────────────────────────────────

def delete_video(video_id: int, db_row: dict, cursor, dry_run: bool) -> bool:
    """
    Delete one video (and its cascading rows) from the database.
    Returns True on success.
    """
    internal_id = db_row["internal_id"]
    segment_id  = db_row.get("segment_id")

    if dry_run:
        print(f"    [dry-run] Would delete video_id={video_id} (internal id={internal_id}), "
              f"segment_id={segment_id} and all cascading response rows")
        return True

    # Cascade order (FK constraints): responses → segment → video
    if segment_id:
        for tbl in ("responses_ratings", "responses_familiarity",
                    "responses_comments", "user_segment_progress"):
            cursor.execute(f"DELETE FROM {tbl} WHERE segment_id = %s", (segment_id,))
        cursor.execute("DELETE FROM segments WHERE id = %s", (segment_id,))

    cursor.execute("DELETE FROM videos WHERE id = %s", (internal_id,))
    print(f"    ✓ Deleted video_id={video_id} and all associated rows")
    return True


# ──────────────────────────────────────────────────────────────────────────────
# Confirmation prompt
# ──────────────────────────────────────────────────────────────────────────────

def confirm(prompt: str, auto_yes: bool) -> bool:
    if auto_yes:
        print(f"  {prompt} → yes (auto_confirm)")
        return True
    reply = input(f"  {prompt} [y/N]: ").strip().lower()
    return reply in ("y", "yes")


# ──────────────────────────────────────────────────────────────────────────────
# Main
# ──────────────────────────────────────────────────────────────────────────────

def main():
    # Choose exactly ONE operation by uncommenting one line below.
    #
    # operation = "report"        # Show folders in sync, new folders, and stale DB rows.
    # operation = "add"           # Import every resource folder not yet in the DB.
    # operation = "delete-stale"  # Delete DB rows whose resource folder is missing.
    # operation = "delete-by-id"  # Delete only video IDs listed in delete_video_ids.
    operation = "add"             # Keep this active when you do not want DB access.

    # Used only when operation = "delete-by-id".
    # These are real videos.video_id values, not internal videos.id values.
    delete_video_ids = []

    # Safety switch for write operations.
    # - True prints what would happen without changing the database.
    # - False allows inserts/deletes after confirmation.
    dry_run = False

    # Confirmation switch for write operations.
    # - False asks before adding/deleting.
    # - True skips prompts. Use only for trusted local/dev runs.
    auto_confirm = False

    # Optional resource root override.
    # - None means PROJECT_ROOT/resources.
    # - Example: resources_root_override = "/path/to/resources"
    resources_root_override = None

    if operation is None:
        print("No video-sync operation selected.")
        print("Edit scripts/sync_videos.py main(), uncomment one operation, then run:")
        print("  python scripts/sync_videos.py")
        return

    resources_root = (
        Path(resources_root_override)
        if resources_root_override
        else PROJECT_ROOT / "resources"
    )

    # ── Connect ────────────────────────────────────────────────────────────────
    try:
        conn   = connect(use_database=True)
        cursor = conn.cursor(dictionary=True)
    except Exception as e:
        sys.exit(f"DB connection failed: {e}")

    # ── Gather state ───────────────────────────────────────────────────────────
    disk_videos = scan_resource_folders(resources_root)      # [(inst_id, vid_id), …]
    disk_set    = {v for _, v in disk_videos}
    db_map      = db_videos(cursor)                          # {video_id: row}
    db_set      = set(db_map.keys())

    new_on_disk  = [(i, v) for i, v in disk_videos if v not in db_set]
    in_sync      = [(i, v) for i, v in disk_videos if v in db_set]
    stale_in_db  = {v: db_map[v] for v in db_set if v not in disk_set}

    # ── Status report ──────────────────────────────────────────────────────────
    print(f"\n{'─'*60}")
    print(f"  Resources root : {resources_root}")
    print(f"  Folders found  : {len(disk_videos)}")
    print(f"  DB video rows  : {len(db_set)}")
    print(f"{'─'*60}")

    print(f"\n✅  In sync ({len(in_sync)}):")
    if in_sync:
        for inst_id, vid_id in in_sync:
            row = db_map[vid_id]
            assign = row.get("version_assignment", "?")
            print(f"    i{inst_id}/v{vid_id}  │  {row['course_code']}  │  "
                  f"{(row.get('segment_title') or '')[:50]}  │  assign={assign}")
    else:
        print("    (none)")

    print(f"\n🆕  New on disk — not in DB ({len(new_on_disk)}):")
    if new_on_disk:
        for inst_id, vid_id in new_on_disk:
            base = resources_root / f"i{inst_id}" / f"v{vid_id}"
            meta = load_metadata(base)
            title = meta.get("section_title", "(no metadata.json)") if meta else "(no metadata.json)"
            print(f"    i{inst_id}/v{vid_id}  │  {title[:60]}")
    else:
        print("    (none)")

    print(f"\n⚠️   Stale in DB — no resource folder ({len(stale_in_db)}):")
    if stale_in_db:
        for vid_id, row in stale_in_db.items():
            print(f"    video_id={vid_id}  │  {row['course_code']}  │  "
                  f"{(row.get('segment_title') or '')[:50]}")
    else:
        print("    (none)")

    print()

    # Read-only report stops here.
    if operation == "report":
        cursor.close(); conn.close()
        return

    changed = False

    # ── ADD ────────────────────────────────────────────────────────────────────
    if operation == "add":
        if not new_on_disk:
            print("add: Nothing new to import.\n")
        else:
            action = "Would add" if dry_run else "Adding"
            print(f"{'─'*60}")
            print(f"{action} {len(new_on_disk)} video(s):")
            if not confirm(f"Proceed to {'(dry-run) ' if dry_run else ''}add {len(new_on_disk)} video(s)?",
                           auto_confirm):
                print("  Skipped.\n")
            else:
                for inst_id, vid_id in new_on_disk:
                    print(f"\n  → i{inst_id}/v{vid_id}")
                    ok = add_video(inst_id, vid_id, resources_root, cursor, dry_run)
                    if ok and not dry_run:
                        changed = True
                print()

    # ── DELETE STALE ──────────────────────────────────────────────────────────
    elif operation == "delete-stale":
        if not stale_in_db:
            print("delete-stale: No stale records found.\n")
        else:
            print(f"{'─'*60}")
            print(f"Stale records to delete ({len(stale_in_db)}):")
            for vid_id, row in stale_in_db.items():
                print(f"  video_id={vid_id}  {row['course_code']}  "
                      f"{(row.get('segment_title') or '')[:50]}")
            print()
            print("  ⚠  This will permanently delete these videos, their segments,")
            print("     and ALL associated participant responses.")
            if not confirm(f"Delete {len(stale_in_db)} stale video(s)?", auto_confirm):
                print("  Skipped.\n")
            else:
                for vid_id, row in stale_in_db.items():
                    print(f"\n  → Deleting video_id={vid_id}")
                    ok = delete_video(vid_id, row, cursor, dry_run)
                    if ok and not dry_run:
                        changed = True
                print()

    # ── DELETE BY ID ──────────────────────────────────────────────────────────
    elif operation == "delete-by-id":
        to_delete = []
        for vid_id in delete_video_ids:
            if vid_id not in db_map:
                print(f"  ✗  video_id={vid_id} not found in database — skipping")
            else:
                to_delete.append(vid_id)

        if not delete_video_ids:
            print("delete-by-id: delete_video_ids is empty. Nothing to delete.\n")
        elif to_delete:
            print(f"{'─'*60}")
            print(f"Videos to delete by ID ({len(to_delete)}):")
            for vid_id in to_delete:
                row = db_map[vid_id]
                folder_exists = vid_id in disk_set
                print(f"  video_id={vid_id}  {row['course_code']}  "
                      f"{(row.get('segment_title') or '')[:50]}"
                      + ("  [folder still exists on disk]" if folder_exists else ""))
            print()
            print("  ⚠  This will permanently delete these videos, their segments,")
            print("     and ALL associated participant responses.")
            if not confirm(f"Delete {len(to_delete)} video(s)?", auto_confirm):
                print("  Skipped.\n")
            else:
                for vid_id in to_delete:
                    print(f"\n  → Deleting video_id={vid_id}")
                    ok = delete_video(vid_id, db_map[vid_id], cursor, dry_run)
                    if ok and not dry_run:
                        changed = True
                print()
    else:
        print(f"Unknown operation: {operation!r}")
        print('Use "report", "add", "delete-stale", "delete-by-id", or None.\n')

    # ── Commit & close ────────────────────────────────────────────────────────
    if changed:
        conn.commit()
        print("✓ All changes committed to database.\n")
    elif dry_run:
        print("[dry-run] No changes made.\n")
    else:
        print("No database changes made.\n")

    cursor.close()
    conn.close()


if __name__ == "__main__":
    main()
