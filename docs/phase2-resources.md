# Phase 2 - Resources, Videos, Chapters, and Dashboard Display

This phase covers the study resources used as context for evaluation: course rows, resource folders, video/chapter import, and dashboard presentation. In the database, each chapter is still stored as one `segments` row.

## Owned Files

| Area | Files |
|---|---|
| Resource storage | `resources/i{instructor_id}/v{video_id}/` |
| Import/sync scripts | `scripts/sync_videos.py` |
| Resource helpers | `app/includes/functions.php` (`getResourcePath`, `getResourceUrl`, `getVideoUrl`) |
| Participant display | `dashboard.php` |
| Tables | `subjects`, `courses`, `videos`, `segments`, `user_courses`, `user_segment_progress` |

## Database Role

`app/sql/schema.sql` seeds only:

- Subject areas.
- Course rows.

Default admin/test users are seeded by `scripts/db.py` with `operation = "setup"` as part of Phase 0. The schema does not seed video, chapter/segment, response, progress, or message rows. Videos and chapter segments should be inserted from resource folders with `scripts/sync_videos.py`.

## Resource Folder Convention

Each video folder contains the MP4 and global transcript, plus one `chapter{N}` folder per chapter:

```text
resources/
└── i{instructor_id}/
    └── v{video_id}/
        ├── *.mp4
        ├── transcript.vtt
        ├── chapter1/
        │   ├── metadata.json
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

Example:

```text
resources/i116/v9230/video.mp4
resources/i116/v9230/transcript.vtt
resources/i116/v9230/chapter2/metadata.json
resources/i116/v9230/chapter2/transcript_summary.txt
resources/i116/v9230/chapter2/multimodal_summary.txt
resources/i116/v9230/chapter2/slides/slide_000.png
resources/i116/v9230/chapter2/visual_objects/crop_001.png
```

The integer IDs must match the database:

- `courses.instructor_id` maps to `i{instructor_id}`.
- `videos.video_id` maps to `v{video_id}`.
- `segments.chapter_num` maps to `chapter{N}`.

## Metadata Expectations

The chapter-level `metadata.json` is read by `scripts/sync_videos.py` and by the Part 2 visual-object survey. Current fields used by the sync script:

| Field | Use |
|---|---|
| `chapter_number` | Stored as `segments.chapter_num` and display order. |
| `chapter_title` or `section_title` | Stored as `segments.title`. |
| `start_time` | Stored as `segments.start_s`; defaults to `0.0`. |
| `end_time` | Stored as `segments.end_s`; if invalid, the script can infer from `transcript.vtt`. |
| `duration` | Stored as `segments.duration_s`; inferred from end/start if needed. |
| `slide_index_start` | Stored as `segments.slide_range_start`. |
| `slide_index_end` | Stored as `segments.slide_range_end`. |

Current fields used by the viewer/submit handler for visual-object selection:

| Field | Use |
|---|---|
| `visual_objects.selected` | Ordered list of crop filenames shown as selected visual objects with labels `S1`, `S2`, ... |
| `visual_objects.unselected` | Ordered list of crop filenames shown as unselected visual objects with labels `U1`, `U2`, ... |

The visual-object image filenames are resolved under `chapter{N}/visual_objects/`. The script warns when expected files are missing. Missing files can make the viewer incomplete, even if the database rows are inserted.

## Sync Resource Folders

Use `scripts/sync_videos.py` when resource folders and database rows need to be compared or updated:

```bash
# In scripts/sync_videos.py main(), choose operation = "report", "add",
# "delete-stale", or "delete-by-id", then run:
python scripts/sync_videos.py
```

For active imports, set `operation = "add"` and `dry_run = False` intentionally. When the script is idle, return it to `operation = None` or keep `dry_run = True` so running the file accidentally does not change the database. Delete operations remove videos, segments, progress, and responses for those segments. Use `dry_run = True` first and back up the database before deleting production data.

`sync_videos.py` with `operation = "add"` is the only Phase 2 import path. It scans `resources/i{instructor_id}/v{video_id}/chapter{N}/`, reads each chapter's `metadata.json`, looks up the matching course by `courses.instructor_id`, inserts a `videos` row once per video, inserts one `segments` row per chapter, stores the MP4 filename from the video folder, and randomly assigns `segments.version_assignment`.

## Version Assignment

Participants see anonymous Version A and Version B. The `segments.version_assignment` value records which generation method is behind each visible label:

| Value | Version A | Version B |
|---|---|---|
| `normal` | `chapter{N}/transcript_summary.txt` | `chapter{N}/multimodal_summary.txt` |
| `swapped` | `chapter{N}/multimodal_summary.txt` | `chapter{N}/transcript_summary.txt` |

This keeps the participant interface blind to the summary source while preserving analysis metadata in the database.

## Dashboard Display

`dashboard.php` shows only chapters from courses assigned to the logged-in participant through `user_courses`.

The dashboard groups content by course, then video, then chapter. It shows:

- Course code and title.
- Per-course completed count.
- Number of videos per course.
- Video display name derived from `videos.video_filename`; the UI removes the trailing non-alphanumeric-plus-four-digit MP4 suffix when present and normalizes repeated symbol runs.
- Per-video completed chapter count.
- Overall completed count.
- Chapter status: `not_started`, `in_progress`, or `completed`.
- Link to `survey/viewer.php?vid={video_id}&chapter={chapter_num}`.

Course sections can be collapsed by clicking the full course heading. If assigned courses do not yet have imported chapters, the dashboard shows a message directing the participant to review their course selection from Profile.

## Path and URL Helpers

Resource paths are built in `app/includes/functions.php`:

| Helper | Purpose |
|---|---|
| `getResourcePath($instructor_id, $video_id, $file)` | Server filesystem path for PHP to read transcripts, summaries, metadata, slides, and visual-object crop references. |
| `getResourceUrl($instructor_id, $video_id, $file)` | Browser URL for slides, visual-object crops, and text resources. |
| `getVideoUrl($instructor_id, $video_id, $filename)` | Browser URL for MP4 playback. |

By default, URLs use `BASE_URL/resources`. Override with:

```text
RESOURCES_URL=https://static.example.org/public/sites/userstudy2/resources
VIDEO_ROOT_URL=https://video.example.org/public/sites/userstudy2/resources
```

## Phase Boundaries

Phase 2 owns resource organization, import, and participant assignment display. It does not own account creation, password sign-in, or one-click access behavior; that is Phase 1. It feeds the viewer, but survey rendering and response writes belong to Phase 3.
