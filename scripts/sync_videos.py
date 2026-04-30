#!/usr/bin/env python3
"""
sync_videos.py — Sync the videos/segments database with the resources filesystem.

Scans resources/ for all i{instructor_id}/v{video_id}/chapter{chapter_id}/
folders, compares with the database, and reports what is new, in-sync, or
stale. Can add new videos/chapters or delete existing ones.

Usage (run from the project root):
    Edit main() at the bottom of this file, choose one operation, then run:
        python scripts/sync_videos.py
"""

import sys
import json
import re
import random
from dataclasses import dataclass
from pathlib import Path

# ── Ensure scripts/ is on path so _db_common works when called from project root ─
sys.path.insert(0, str(Path(__file__).resolve().parent))
from _db_common import PROJECT_ROOT, connect

# ──────────────────────────────────────────────────────────────────────────────
# Constants
# ──────────────────────────────────────────────────────────────────────────────

REQUIRED_CHAPTER_FILES = (
    "metadata.json",
    "transcript_summary.txt",
    "multimodal_summary.txt",
)

REQUIRED_VIDEO_FILES = (
    "transcript.vtt",
)

# ──────────────────────────────────────────────────────────────────────────────
# Helpers
# ──────────────────────────────────────────────────────────────────────────────

def fmt_time(seconds: float) -> str:
    s = int(round(seconds))
    return f"{s // 60:02d}:{s % 60:02d}"


def scan_resource_folders(resources_root: Path) -> list[tuple[int, int, int]]:
    """Return sorted list of (instructor_id, video_id, chapter_id) triples."""
    found = []
    inst_pattern = re.compile(r"^i(\d+)$")
    vid_pattern  = re.compile(r"^v(\d+)$")
    chap_pattern = re.compile(r"^chapter(\d+)$")

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
            video_id = int(vm.group(1))
            for chap_dir in sorted(vid_dir.iterdir()):
                cm = chap_pattern.match(chap_dir.name)
                if not cm or not chap_dir.is_dir():
                    continue
                found.append((instructor_id, video_id, int(cm.group(1))))

    return found


def chapter_folder(resources_root: Path, instructor_id: int, video_id: int,
                   chapter_id: int) -> Path:
    return resources_root / f"i{instructor_id}" / f"v{video_id}" / f"chapter{chapter_id}"


def video_folder(resources_root: Path, instructor_id: int, video_id: int) -> Path:
    return resources_root / f"i{instructor_id}" / f"v{video_id}"


def check_folder(video_base: Path, chapter_base: Path) -> dict:
    """Inspect a video/chapter resource folder pair."""
    result = {"missing": [], "mp4": ""}

    for fname in REQUIRED_VIDEO_FILES:
        if not (video_base / fname).exists():
            result["missing"].append(fname)

    for fname in REQUIRED_CHAPTER_FILES:
        if not (chapter_base / fname).exists():
            result["missing"].append(fname)

    if not (chapter_base / "slides").is_dir():
        result["missing"].append("slides/")

    mp4s = sorted(video_base.glob("*.mp4"))
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


def parse_vtt_time(value: str) -> float:
    parts = [float(p) for p in value.strip().replace(",", ".").split(":")]
    if len(parts) == 3:
        return parts[0] * 3600 + parts[1] * 60 + parts[2]
    if len(parts) == 2:
        return parts[0] * 60 + parts[1]
    return parts[0] if parts else 0.0


def vtt_end_time(path: Path) -> float:
    if not path.exists():
        return 0.0
    text = path.read_text(encoding="utf-8", errors="ignore")
    end_times = re.findall(r"-->\s*([0-9:.]+)", text)
    if not end_times:
        return 0.0
    return max(parse_vtt_time(t) for t in end_times)


def db_videos(cursor) -> dict[int, dict]:
    """Return {video_id: row} for all videos currently in the DB."""
    cursor.execute("""
        SELECT v.id AS internal_id, v.video_id, v.instructor_id, v.course_id,
               v.video_filename,
               c.code AS course_code
        FROM videos v
        JOIN courses c  ON c.id = v.course_id
        ORDER BY v.video_id
    """)
    return {r["video_id"]: r for r in cursor.fetchall()}


def db_segments(cursor) -> dict[tuple[int, int], dict]:
    """Return {(real_video_id, chapter_num): row} for all DB segments."""
    cursor.execute("""
        SELECT
            v.id AS internal_video_id,
            v.video_id AS real_video_id,
            v.instructor_id,
            v.course_id,
            v.video_filename,
            c.code AS course_code,
            s.id AS segment_id,
            s.chapter_num,
            s.title AS segment_title,
            s.start_s,
            s.end_s,
            s.duration_s,
            s.version_assignment
        FROM segments s
        JOIN videos v  ON v.id = s.video_id
        JOIN courses c ON c.id = v.course_id
        ORDER BY v.video_id, s.chapter_num
    """)
    return {(r["real_video_id"], r["chapter_num"]): r for r in cursor.fetchall()}


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

def add_chapter(instructor_id: int, video_id: int, chapter_id: int,
                resources_root: Path, video_map: dict[int, dict],
                cursor, dry_run: bool) -> bool:
    """
    Import one chapter folder into the database.
    Creates the videos row first if this is the first chapter for the video.
    Returns True on success, False on error/skip.
    """
    vbase = video_folder(resources_root, instructor_id, video_id)
    cbase = chapter_folder(resources_root, instructor_id, video_id, chapter_id)
    info = check_folder(vbase, cbase)

    if info["missing"]:
        print(f"    ⚠  Missing files: {', '.join(info['missing'])} — importing DB row anyway")

    meta = load_metadata(cbase)
    if not meta:
        print(f"    ✗  Cannot read metadata.json — skipping")
        return False

    meta_chapter = int(meta.get("chapter_number", chapter_id))
    chapter_num = chapter_id
    if meta_chapter != chapter_id:
        print(f"    ⚠  metadata chapter_number={meta_chapter}; using folder chapter{chapter_id}")

    title       = meta.get("chapter_title") or meta.get("section_title") or f"Chapter {chapter_num}"
    start_s     = float(meta.get("start_time", 0.0))
    duration_s  = float(meta.get("duration", 0.0))
    end_s       = float(meta.get("end_time", start_s + duration_s))
    if end_s <= start_s:
        inferred_end = vtt_end_time(vbase / "transcript.vtt")
        if inferred_end > start_s:
            print(f"    ⚠  Invalid end_time={end_s:.4f}; using VTT end {inferred_end:.4f}")
            end_s = inferred_end
    if duration_s <= 0 and end_s >= start_s:
        duration_s = round(end_s - start_s, 4)
    slide_start = int(meta.get("slide_index_start", 0))
    slide_end   = int(meta.get("slide_index_end",   0))
    video_filename = info["mp4"]

    print(f"    Chapter {chapter_num}: {title}")
    print(f"    Path:     i{instructor_id}/v{video_id}/chapter{chapter_id}")
    print(f"    Duration: {fmt_time(duration_s)}  ({duration_s:.4f}s)")
    print(f"    Time:     {fmt_time(start_s)}–{fmt_time(end_s)}")
    print(f"    Slides:   {slide_start}–{slide_end}")
    print(f"    Video file: {video_filename or '(none)'}")

    video_row = video_map.get(video_id)
    if video_row and int(video_row["instructor_id"]) != instructor_id:
        print(f"    ✗  video_id={video_id} already exists for "
              f"instructor_id={video_row['instructor_id']} — skipping")
        return False

    if video_row:
        internal_id = video_row["internal_id"]
        course_id = video_row["course_id"]
        print(f"    Course:   {video_row['course_code']} (id={course_id})")
    else:
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
    transcript_summary = f"chapter{chapter_id}/transcript_summary.txt"
    multimodal_summary = f"chapter{chapter_id}/multimodal_summary.txt"
    summary_a  = transcript_summary if assignment == "normal" else multimodal_summary
    summary_b  = multimodal_summary if assignment == "normal" else transcript_summary
    print(f"    Version:  {assignment}  (A={Path(summary_a).name.replace('_summary.txt','')})")

    if dry_run:
        if not video_row:
            print(f"    [dry-run] Would insert into videos")
        print(f"    [dry-run] Would insert into segments")
        return True

    if not video_row:
        cursor.execute("""
            INSERT INTO videos (course_id, instructor_id, video_id, video_filename)
            VALUES (%s, %s, %s, %s)
        """, (course_id, instructor_id, video_id, video_filename))
        internal_id = cursor.lastrowid
        video_map[video_id] = {
            "internal_id": internal_id,
            "video_id": video_id,
            "instructor_id": instructor_id,
            "course_id": course_id,
            "video_filename": video_filename,
            "course_code": course["code"],
        }

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

    if dry_run:
        print(f"    [dry-run] Would delete video_id={video_id} (internal id={internal_id}) "
              f"and all cascading segment/response rows")
        return True

    cursor.execute("DELETE FROM videos WHERE id = %s", (internal_id,))
    print(f"    ✓ Deleted video_id={video_id} and all associated rows")
    return True


def delete_segment(real_video_id: int, chapter_num: int, db_row: dict,
                   cursor, dry_run: bool) -> bool:
    """Delete one chapter segment and its cascading response/progress rows."""
    segment_id = db_row["segment_id"]

    if dry_run:
        print(f"    [dry-run] Would delete video_id={real_video_id}, "
              f"chapter={chapter_num}, segment_id={segment_id}")
        return True

    cursor.execute("DELETE FROM segments WHERE id = %s", (segment_id,))
    print(f"    ✓ Deleted video_id={real_video_id}, chapter={chapter_num}, segment_id={segment_id}")
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
# Operation orchestration
# ──────────────────────────────────────────────────────────────────────────────

@dataclass
class SyncState:
    resources_root: Path
    disk_chapters: list[tuple[int, int, int]]
    disk_video_set: set[int]
    disk_chapter_set: set[tuple[int, int]]
    db_map: dict[int, dict]
    db_segment_map: dict[tuple[int, int], dict]
    db_video_set: set[int]
    db_chapter_set: set[tuple[int, int]]
    new_on_disk: list[tuple[int, int, int]]
    in_sync: list[tuple[int, int, int]]
    stale_videos: dict[int, dict]
    stale_chapters: dict[tuple[int, int], dict]


def resolve_resources_root(resources_root_override: str | None) -> Path:
    if resources_root_override:
        return Path(resources_root_override)
    return PROJECT_ROOT / "resources"


def gather_state(resources_root: Path, cursor) -> SyncState:
    disk_chapters = scan_resource_folders(resources_root)
    disk_video_set = {v for _, v, _ in disk_chapters}
    disk_chapter_set = {(v, c) for _, v, c in disk_chapters}
    db_map = db_videos(cursor)
    db_segment_map = db_segments(cursor)
    db_video_set = set(db_map.keys())
    db_chapter_set = set(db_segment_map.keys())

    new_on_disk = [
        (i, v, c) for i, v, c in disk_chapters
        if (v, c) not in db_chapter_set
    ]
    in_sync = [
        (i, v, c) for i, v, c in disk_chapters
        if (v, c) in db_chapter_set
    ]
    stale_videos = {v: db_map[v] for v in db_video_set if v not in disk_video_set}
    stale_chapters = {
        key: row for key, row in db_segment_map.items()
        if row["real_video_id"] in disk_video_set and key not in disk_chapter_set
    }

    return SyncState(
        resources_root=resources_root,
        disk_chapters=disk_chapters,
        disk_video_set=disk_video_set,
        disk_chapter_set=disk_chapter_set,
        db_map=db_map,
        db_segment_map=db_segment_map,
        db_video_set=db_video_set,
        db_chapter_set=db_chapter_set,
        new_on_disk=new_on_disk,
        in_sync=in_sync,
        stale_videos=stale_videos,
        stale_chapters=stale_chapters,
    )


def print_status_report(state: SyncState) -> None:
    print(f"\n{'─'*60}")
    print(f"  Resources root : {state.resources_root}")
    print(f"  Chapters found : {len(state.disk_chapters)}")
    print(f"  DB video rows  : {len(state.db_video_set)}")
    print(f"  DB segment rows: {len(state.db_chapter_set)}")
    print(f"{'─'*60}")

    print(f"\n✅  In sync ({len(state.in_sync)}):")
    if state.in_sync:
        for inst_id, vid_id, chap_id in state.in_sync:
            row = state.db_segment_map[(vid_id, chap_id)]
            assign = row.get("version_assignment", "?")
            print(f"    i{inst_id}/v{vid_id}/chapter{chap_id}  │  {row['course_code']}  │  "
                  f"{(row.get('segment_title') or '')[:50]}  │  assign={assign}")
    else:
        print("    (none)")

    print(f"\n🆕  New on disk — not in DB ({len(state.new_on_disk)}):")
    if state.new_on_disk:
        for inst_id, vid_id, chap_id in state.new_on_disk:
            base = chapter_folder(state.resources_root, inst_id, vid_id, chap_id)
            meta = load_metadata(base)
            title = (
                meta.get("chapter_title") or meta.get("section_title") or "(no title)"
            ) if meta else "(no metadata.json)"
            print(f"    i{inst_id}/v{vid_id}/chapter{chap_id}  │  {title[:60]}")
    else:
        print("    (none)")

    print(f"\n⚠️   Stale chapters in DB — no chapter folder ({len(state.stale_chapters)}):")
    if state.stale_chapters:
        for (vid_id, chap_id), row in state.stale_chapters.items():
            print(f"    video_id={vid_id}/chapter{chap_id}  │  {row['course_code']}  │  "
                  f"{(row.get('segment_title') or '')[:50]}")
    else:
        print("    (none)")

    print(f"\n⚠️   Stale videos in DB — no video folder ({len(state.stale_videos)}):")
    if state.stale_videos:
        for vid_id, row in state.stale_videos.items():
            print(f"    video_id={vid_id}  │  {row['course_code']}")
    else:
        print("    (none)")

    print()


def load_state_and_report(resources_root: Path, cursor) -> SyncState:
    state = gather_state(resources_root, cursor)
    print_status_report(state)
    return state


def report_operation(resources_root: Path, cursor) -> bool:
    load_state_and_report(resources_root, cursor)
    return False


def add_operation(resources_root: Path, cursor, dry_run: bool, auto_confirm: bool) -> bool:
    state = load_state_and_report(resources_root, cursor)

    if not state.new_on_disk:
        print("add: Nothing new to import.\n")
        return False

    action = "Would add" if dry_run else "Adding"
    print(f"{'─'*60}")
    print(f"{action} {len(state.new_on_disk)} chapter(s):")
    if not confirm(f"Proceed to {'(dry-run) ' if dry_run else ''}add {len(state.new_on_disk)} chapter(s)?",
                   auto_confirm):
        print("  Skipped.\n")
        return False

    changed = False
    for inst_id, vid_id, chap_id in state.new_on_disk:
        print(f"\n  → i{inst_id}/v{vid_id}/chapter{chap_id}")
        ok = add_chapter(inst_id, vid_id, chap_id, resources_root,
                         state.db_map, cursor, dry_run)
        if ok and not dry_run:
            changed = True
    print()
    return changed


def delete_stale_operation(resources_root: Path, cursor, dry_run: bool,
                           auto_confirm: bool) -> bool:
    state = load_state_and_report(resources_root, cursor)

    if not state.stale_chapters and not state.stale_videos:
        print("delete-stale: No stale records found.\n")
        return False

    print(f"{'─'*60}")
    print(f"Stale chapters to delete ({len(state.stale_chapters)}):")
    for (vid_id, chap_id), row in state.stale_chapters.items():
        print(f"  video_id={vid_id}/chapter{chap_id}  {row['course_code']}  "
              f"{(row.get('segment_title') or '')[:50]}")
    print(f"\nStale videos to delete ({len(state.stale_videos)}):")
    for vid_id, row in state.stale_videos.items():
        print(f"  video_id={vid_id}  {row['course_code']}")
    print()
    print("  ⚠  This will permanently delete these segment/video rows")
    print("     and ALL associated participant responses.")

    total_stale = len(state.stale_chapters) + len(state.stale_videos)
    if not confirm(f"Delete {total_stale} stale record(s)?", auto_confirm):
        print("  Skipped.\n")
        return False

    changed = False
    for (vid_id, chap_id), row in state.stale_chapters.items():
        print(f"\n  → Deleting video_id={vid_id}/chapter{chap_id}")
        ok = delete_segment(vid_id, chap_id, row, cursor, dry_run)
        if ok and not dry_run:
            changed = True
    for vid_id, row in state.stale_videos.items():
        print(f"\n  → Deleting video_id={vid_id}")
        ok = delete_video(vid_id, row, cursor, dry_run)
        if ok and not dry_run:
            changed = True
    print()
    return changed


def delete_by_id_operation(resources_root: Path, cursor, delete_video_ids: list[int],
                           dry_run: bool, auto_confirm: bool) -> bool:
    state = load_state_and_report(resources_root, cursor)

    to_delete = []
    for vid_id in delete_video_ids:
        if vid_id not in state.db_map:
            print(f"  ✗  video_id={vid_id} not found in database — skipping")
        else:
            to_delete.append(vid_id)

    if not delete_video_ids:
        print("delete-by-id: delete_video_ids is empty. Nothing to delete.\n")
        return False
    if not to_delete:
        return False

    print(f"{'─'*60}")
    print(f"Videos to delete by ID ({len(to_delete)}):")
    for vid_id in to_delete:
        row = state.db_map[vid_id]
        folder_exists = vid_id in state.disk_video_set
        print(f"  video_id={vid_id}  {row['course_code']}"
              + ("  [folder still exists on disk]" if folder_exists else ""))
    print()
    print("  ⚠  This will permanently delete these videos, their segments,")
    print("     and ALL associated participant responses.")

    if not confirm(f"Delete {len(to_delete)} video(s)?", auto_confirm):
        print("  Skipped.\n")
        return False

    changed = False
    for vid_id in to_delete:
        print(f"\n  → Deleting video_id={vid_id}")
        ok = delete_video(vid_id, state.db_map[vid_id], cursor, dry_run)
        if ok and not dry_run:
            changed = True
    print()
    return changed


def run_db_operation(operation: str, resources_root: Path, delete_video_ids: list[int],
                     dry_run: bool, auto_confirm: bool) -> None:
    try:
        conn = connect(use_database=True)
        cursor = conn.cursor(dictionary=True)
    except Exception as e:
        sys.exit(f"DB connection failed: {e}")

    try:
        if operation == "report":
            report_operation(resources_root, cursor)
            return
        elif operation == "add":
            changed = add_operation(resources_root, cursor, dry_run, auto_confirm)
        elif operation == "delete-stale":
            changed = delete_stale_operation(resources_root, cursor, dry_run, auto_confirm)
        elif operation == "delete-by-id":
            changed = delete_by_id_operation(resources_root, cursor, delete_video_ids,
                                             dry_run, auto_confirm)
        else:
            print(f"Unknown operation: {operation!r}")
            print('Use "report", "add", "delete-stale", "delete-by-id", or None.\n')
            return

        if changed:
            conn.commit()
            print("✓ All changes committed to database.\n")
        elif dry_run:
            print("[dry-run] No changes made.\n")
        else:
            print("No database changes made.\n")
    finally:
        cursor.close()
        conn.close()


# ──────────────────────────────────────────────────────────────────────────────
# Main
# ──────────────────────────────────────────────────────────────────────────────

def main():
    # Choose exactly ONE operation by uncommenting one line below.
    #
    # Operation choices:
    # operation = "report"        # Show folders in sync, new folders, and stale DB rows.
    # operation = "add"           # Import every chapter folder not yet in the DB.
    # operation = "delete-stale"  # Delete DB rows whose resource folders are missing.
    # operation = "delete-by-id"  # Delete only video IDs listed in delete_video_ids.
    operation = "add"              # Keep this active when you do not want DB access.

    # Operation arguments:
    # - delete_video_ids applies only to operation = "delete-by-id".
    # - dry_run applies to write operations.
    # - auto_confirm skips prompts for trusted local/dev runs.
    # - resources_root_override may point at a different resources folder.
    delete_video_ids = []           # Real videos.video_id values, not internal videos.id.
    dry_run = False
    auto_confirm = False
    resources_root_override = None

    if operation is None:
        print("No video-sync operation selected.")
        print("Edit scripts/sync_videos.py main(), uncomment one operation, then run:")
        print("  python scripts/sync_videos.py")
        return

    resources_root = resolve_resources_root(resources_root_override)
    run_db_operation(
        operation=operation,
        resources_root=resources_root,
        delete_video_ids=delete_video_ids,
        dry_run=dry_run,
        auto_confirm=auto_confirm,
    )


if __name__ == "__main__":
    main()
