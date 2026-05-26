# Phase 2 — Resources, Videos, Chapters, and Dashboard Display

This phase covers the study resources used as the evaluation context: course rows, resource folders, video/chapter import, the admin inspection/edit tools, and the participant dashboard. In the database, each chapter is stored as one row in `segments`.

## Owned Files

| Area | Files |
|---|---|
| Resource storage | `resources/i{instructor_id}/v{video_id}/` |
| Import / sync | `scripts/sync_videos.py` |
| Validation | `scripts/verify_chapter_objects.py` |
| S3 transfer | `scripts/transfer_files_s3.py` |
| Resource helpers | `app/includes/functions.php` (`getResourcePath`, `getResourceUrl`, `getVideoUrl`) |
| Resource config | `app/config/resources.json` (default filenames) |
| Participant display | `dashboard.php` |
| Admin tools | `admin/visualize.php` (inspect a video like the viewer), `admin/edit_objects.php` (visual-object override editor), `admin/save_objects_ajax.php` (AJAX endpoint) |
| Page assets | `assets/css/dashboard.css`, `assets/css/admin-visualize.css`, `assets/css/admin-edit-objects.css`, `assets/js/admin-visualize.js`, `assets/js/admin-edit-objects.js` |
| Tables | `subjects`, `courses`, `videos`, `segments`, `user_courses`, `user_segment_progress` |

## Database Role

`app/sql/schema.sql` seeds only:

- Subject areas (`subjects`).
- Course rows (`courses`).

Default admin/test users are seeded by `scripts/db.py` as part of Phase 0. The schema does **not** seed video, chapter/segment, response, progress, or message rows. Videos and chapter segments must be inserted from resource folders with `scripts/sync_videos.py`.

### Currently seeded subjects and courses

| Subject ID | Code | Name |
|---|---|---|
| `1` | BIOL | Biology |
| `2` | COSC | Computer Science |

| Course ID | Subject | Code | Name | Instructor ID |
|---|---|---|---|---|
| `527` | BIOL | BIOL2321 | Microbiology for Science Majors | `1` |
| `528` | BIOL | BIOL2301 | Human Anatomy & Physiology I | `116` |
| `533` | COSC | COSC4393 | Digital Image Processing | `12394` |
| `390` | COSC | COSC4393 | Intro to HPC | `3225` |

Additional courses (BIOL4315 Neuroscience, COSC1336) are present as commented-out seeds in `app/sql/schema.sql` and can be re-enabled when resources are ready.

## Resource Folder Convention

Each video folder contains the MP4 and `transcript.vtt`, plus one `chapter{N}/` folder per chapter:

```text
resources/
└── i{instructor_id}/
    └── v{video_id}/
        ├── *.mp4
        ├── transcript.vtt
        ├── chapter1/
        │   ├── metadata.json
        │   ├── detection_data.json
        │   ├── transcript_summary.txt
        │   ├── multimodal_summary.txt
        │   ├── slides/
        │   │   ├── slide_000.png
        │   │   └── slide_001.png
        │   └── visual_objects/
        │       ├── selected_crop.png
        │       └── unselected_crop.png
        └── chapter2/
            └── ...
```

Example paths:

```text
resources/i116/v9230/video.mp4
resources/i116/v9230/transcript.vtt
resources/i116/v9230/chapter2/metadata.json
resources/i116/v9230/chapter2/detection_data.json
resources/i116/v9230/chapter2/transcript_summary.txt
resources/i116/v9230/chapter2/multimodal_summary.txt
resources/i116/v9230/chapter2/slides/slide_000.png
resources/i116/v9230/chapter2/visual_objects/crop_001.png
```

The integer IDs must match the database:

- `courses.instructor_id` ↔ `i{instructor_id}`
- `videos.video_id` ↔ `v{video_id}`
- `segments.chapter_num` ↔ `chapter{N}`

`detection_data.json` is optional for `sync_videos.py` (which only reads `metadata.json`) but is required by the admin object editor (`admin/edit_objects.php`) and the validator (`scripts/verify_chapter_objects.py`).

## Metadata Expectations

The chapter-level `metadata.json` is read by `sync_videos.py`, by the Part 2 visual-object survey, and by `admin/visualize.php`.

### Fields used by `sync_videos.py`

| Field | Stored as / used for |
|---|---|
| `chapter_number` | `segments.chapter_num` and display order. |
| `chapter_title` or `section_title` | `segments.title`. |
| `start_time` | `segments.start_s`; defaults to `0.0`. |
| `end_time` | `segments.end_s`; if invalid, inferred from `transcript.vtt`. |
| `duration` | `segments.duration_s`; inferred from end/start if needed. |
| `slide_index_start` | `segments.slide_range_start`. |
| `slide_index_end` | `segments.slide_range_end`. |

### Fields used by the viewer / submit / admin tools

| Field | Use |
|---|---|
| `visual_objects.selected` | Ordered list of crop filenames; shown as **selected** objects labeled `S1`, `S2`, … |
| `visual_objects.unselected` | Ordered list of crop filenames; shown as **unselected** objects labeled `U1`, `U2`, … |

The visual-object image filenames resolve under `chapter{N}/visual_objects/`. `sync_videos.py` warns when expected files are missing — missing files can make the viewer incomplete even if the database rows were inserted.

## Sync Resource Folders

Use `scripts/sync_videos.py` to compare resource folders with database rows and to add/remove records:

```bash
# In scripts/sync_videos.py main(), choose one operation, then run:
python scripts/sync_videos.py
```

| Operation | Purpose |
|---|---|
| `report` | Show which chapter folders are in sync, new on disk, and stale in DB. Read-only. |
| `add` | Import every chapter folder not yet in the database. |
| `delete-stale` | Delete DB rows whose resource folders are missing. |
| `delete-by-id` | Delete only video IDs listed in `delete_video_ids`. |
| `None` | No DB access — recommended idle state. |

Knobs in `main()`:

- `dry_run = True` previews writes without committing. Use this on production data first.
- `auto_confirm = True` skips per-step `[y/N]` prompts (intended for trusted local/dev runs).
- `resources_root_override` can point at a different resources folder.

Delete operations cascade through `segments`, `user_segment_progress`, and all response tables — back up the database before deleting any production data.

### What `add` does

`sync_videos.py` with `operation = "add"` is the **only** Phase 2 import path. It:

1. Scans `resources/i{instructor_id}/v{video_id}/chapter{N}/`.
2. Reads each chapter's `metadata.json`.
3. Looks up the matching course by `courses.instructor_id`.
4. Inserts a `videos` row once per video, capturing the MP4 filename from the video folder.
5. Inserts one `segments` row per chapter and randomly assigns `segments.version_assignment`.

## Version Assignment

Participants see only anonymous **Version A** and **Version B**. The `segments.version_assignment` value records which generation method is behind each visible label:

| Value | Version A | Version B |
|---|---|---|
| `normal` | `chapter{N}/transcript_summary.txt` | `chapter{N}/multimodal_summary.txt` |
| `swapped` | `chapter{N}/multimodal_summary.txt` | `chapter{N}/transcript_summary.txt` |

This keeps the participant interface blind to the summary source while preserving the mapping for analysis.

## Validating a Chapter — `scripts/verify_chapter_objects.py`

Run this on a single chapter before/after object curation:

```bash
python scripts/verify_chapter_objects.py --chapter-dir resources/i12394/v9265/chapter6
```

It checks:

- `detection_data.json` structure and bbox validity.
- `metadata.json` `visual_objects.selected` / `unselected` integrity.
- Crop file existence and crop-size consistency against bbox-derived extents.
- Overlap and missing references between detections and metadata.

Outputs:

- Annotated slide images under `resources/temp/.../annotated_slides/`.
- `report.json` and `report.txt` in the same output root.

Requires PIL/Pillow for the annotated-slide rendering step.

## Transferring Resources — `scripts/transfer_files_s3.py`

A convenience tool for pushing/pulling study resources to/from an S3 bucket. Edit the configuration constants at the top of the file (bucket name, `S3_TRANSFER_ROOT`, etc.) and call the helper functions inside `__main__`. Not required at request time by the PHP app — use it only when staging large resource folders across machines.

## Admin Resource Tools

### `admin/visualize.php`

Admin-only inspection of a single video, opened via `admin/visualize.php?vid={video_id}`. It reads the same `transcript.vtt`, summary files, slides, and visual-object metadata as the participant viewer, lets an admin step through chapters, and uses `assets/css/admin-visualize.css` + `assets/js/admin-visualize.js`. No participant responses are saved from this page.

### `admin/edit_objects.php` + `admin/save_objects_ajax.php`

Admin-only editor for a chapter's visual objects. It reads `metadata.json` + `detection_data.json` for that chapter and lets an admin reclassify detections (selected vs. unselected, or drop them entirely). Saves are POSTed to `admin/save_objects_ajax.php`, which rewrites the chapter's `metadata.json` and `detection_data.json` on disk. Uses `assets/css/admin-edit-objects.css` + `assets/js/admin-edit-objects.js`.

Because these endpoints write into `resources/`, the admin server account must have write permission on the chapter folder.

## Dashboard Display

`dashboard.php` shows only chapters from courses assigned to the logged-in participant through `user_courses`. The page uses `assets/css/dashboard.css`.

It groups content by **course → video → chapter** and shows:

- Course code and title.
- Per-course completed count.
- Number of videos per course.
- Video display name derived from `videos.video_filename` (the UI strips the trailing non-alphanumeric-plus-four-digit MP4 suffix when present and normalizes repeated symbol runs).
- Per-video completed chapter count.
- Overall completed count.
- Chapter status: `not_started`, `in_progress`, or `completed`.
- Link to `survey/viewer.php?vid={video_id}&chapter={chapter_num}`.

Course sections collapse by clicking the course heading. If assigned courses have no imported chapters yet, the dashboard shows a message directing the participant to review their course selection from Profile.

## Path and URL Helpers

Resource paths/URLs are built in `app/includes/functions.php`:

| Helper | Purpose |
|---|---|
| `getResourcePath($instructor_id, $video_id, $file)` | Server filesystem path for PHP to read transcripts, summaries, metadata, slides, and visual-object crops. |
| `getResourceUrl($instructor_id, $video_id, $file)` | Browser URL for slides, visual-object crops, and text resources. |
| `getVideoUrl($instructor_id, $video_id, $filename)` | Browser URL for MP4 playback. |

By default, URLs are built under `BASE_URL/resources`. Override with `.env`:

```text
RESOURCES_URL=https://static.example.org/public/sites/userstudy2/resources
VIDEO_ROOT_URL=https://video.example.org/public/sites/userstudy2/resources
```

`app/config/resources.json` defines default filenames used inside a chapter folder (`transcript.txt`, `summary_a.txt`, `summary_b.txt`, `slides/`) for places where a chapter-relative lookup is needed.

## Phase Boundaries

Phase 2 owns resource organization, import, admin inspection/edit of resources, and the participant dashboard. It does **not** own account creation, password sign-in, or one-click access (Phase 1), and it does not own survey rendering or response writes (Phase 3).
