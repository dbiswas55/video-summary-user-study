# Phase 2 - Resources, Videos, Segments, and Dashboard Display

This phase covers the study resources used as context for evaluation: course rows, resource folders, video/segment import, and dashboard presentation.

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

Default admin/test users are seeded by `scripts/db.py` with `operation = "setup"` as part of Phase 0. The schema does not seed video, segment, response, progress, or message rows. Videos and segments should be inserted from resource folders with `scripts/sync_videos.py`.

## Resource Folder Convention

Each video/chapter clip lives under:

```text
resources/
└── i{instructor_id}/
    └── v{video_id}/
        ├── metadata.json
        ├── transcript.txt
        ├── transcript_summary.txt
        ├── multimodal_summary.txt
        ├── slides/
        │   ├── slide_000.png
        │   └── slide_001.png
        └── *.mp4
```

Example:

```text
resources/i116/v9230/metadata.json
resources/i116/v9230/transcript.txt
resources/i116/v9230/transcript_summary.txt
resources/i116/v9230/multimodal_summary.txt
resources/i116/v9230/slides/slide_000.png
resources/i116/v9230/video.mp4
```

The integer IDs must match the database:

- `courses.instructor_id` maps to `i{instructor_id}`.
- `videos.video_id` maps to `v{video_id}`.

## Metadata Expectations

`metadata.json` is read by `scripts/sync_videos.py`. Current fields used:

| Field | Use |
|---|---|
| `chapter_number` | Stored as `segments.chapter_num` and display order. |
| `section_title` | Stored as `segments.title`. |
| `duration` | Stored as `segments.duration_s`; viewer uses the clip from `0` to duration. |
| `slide_index_start` | Stored as `segments.slide_range_start`. |
| `slide_index_end` | Stored as `segments.slide_range_end`. |

The script warns when expected files are missing. Missing files can make the viewer incomplete, even if the database rows are inserted.

## Sync Resource Folders

Use `scripts/sync_videos.py` when resource folders and database rows need to be compared or updated:

```bash
# In scripts/sync_videos.py main(), choose operation = "report", "add",
# "delete-stale", or "delete-by-id", then run:
python scripts/sync_videos.py
```

The checked-in/default state should keep `operation = None` and `dry_run = True` so running the script accidentally does not change the database. Delete operations remove videos, segments, progress, and responses for those segments. Use `dry_run = True` first and back up the database before deleting production data.

`sync_videos.py` with `operation = "add"` is the only Phase 2 import path. It scans `resources/i{instructor_id}/v{video_id}/`, reads each folder's `metadata.json`, looks up the matching course by `courses.instructor_id`, inserts `videos` and `segments` rows, and randomly assigns `segments.version_assignment`.

## Version Assignment

Participants see anonymous Version A and Version B. The `segments.version_assignment` value records which generation method is behind each visible label:

| Value | Version A | Version B |
|---|---|---|
| `normal` | `transcript_summary.txt` | `multimodal_summary.txt` |
| `swapped` | `multimodal_summary.txt` | `transcript_summary.txt` |

This keeps the participant interface blind to the summary source while preserving analysis metadata in the database.

## Dashboard Display

`dashboard.php` shows only segments from courses assigned to the logged-in participant through `user_courses`.

The dashboard groups segments by course and shows:

- Course code and title.
- Per-course completed count.
- Overall completed count.
- Segment status: `not_started`, `in_progress`, or `completed`.
- Link to `survey/viewer.php?vid={video_id}`.

If assigned courses do not yet have imported segments, the dashboard shows a message directing the participant to review their course selection from Profile.

## Path and URL Helpers

Resource paths are built in `app/includes/functions.php`:

| Helper | Purpose |
|---|---|
| `getResourcePath($instructor_id, $video_id, $file)` | Server filesystem path for PHP to read transcripts, summaries, and slides. |
| `getResourceUrl($instructor_id, $video_id, $file)` | Browser URL for slides and text resources. |
| `getVideoUrl($instructor_id, $video_id, $filename)` | Browser URL for MP4 playback. |

By default, URLs use `BASE_URL/resources`. Override with:

```text
RESOURCES_URL=https://static.example.org/public/sites/userstudy2/resources
VIDEO_ROOT_URL=https://video.example.org/public/sites/userstudy2/resources
```

## Phase Boundaries

Phase 2 owns resource organization, import, and participant assignment display. It does not own account creation, password sign-in, or one-click access behavior; that is Phase 1. It feeds the viewer, but survey rendering and response writes belong to Phase 3.
