# Phase 3 - Comparative Evaluation Survey

This phase covers the participant study page, A/B summary comparison, questionnaire validation, progress updates, and response storage.

## Owned Files

| Area | Files |
|---|---|
| Viewer page | `survey/viewer.php` |
| Submission handler | `survey/submit.php` |
| Survey config | `app/config/study.json` |
| Shared helpers | `app/includes/auth.php`, `app/includes/functions.php` |
| Tables | `responses_familiarity`, `responses_ratings`, `responses_comments`, `user_segment_progress` |

## Viewer Route

The viewer route is:

```text
survey/viewer.php?vid={video_id}
```

`video_id` is the real video integer ID stored in `videos.video_id`, not the internal auto-increment `videos.id`.

`viewer.php` requires login. It loads:

- Course/video/segment metadata from `videos`, `courses`, and `segments`.
- Transcript and summary text from the resource folder.
- Slide images from `slides/`.
- MP4 URL from `getVideoUrl()`.
- Familiarity options, rating dimensions, and scale labels from `app/config/study.json`.
- Previous responses for the current user and segment, so a partially saved survey can be resumed.

## Study Interface

The page presents:

- Video player.
- Transcript panel.
- Slide strip/lightbox.
- Summary Version A.
- Summary Version B.
- Optional comparison/diff view.
- Familiarity question.
- Ratings for each dimension and version.
- Optional comments per dimension.

The method behind Version A/B is hidden from participants. The mapping is stored in `segments.version_assignment`; see Phase 2 for details.

## Survey Questions

The participant-facing wording is configured in `app/config/study.json`:

| Config key | Use |
|---|---|
| `familiarity_question` | Q1 prompt. |
| `familiarity_options` | Q1 option IDs and labels. |
| `dimensions` | Rating dimension IDs, labels, and question text. |
| `rating_scale` | Rating range and scale label text, for example `Scale: 1 = Poor → 10 = Excellent`. |

Keep dimension IDs and familiarity option IDs stable unless the database schema is also changed, because those IDs are stored in response tables.

The current structured response model has:

| Question area | Stored in |
|---|---|
| Familiarity with the topic | `responses_familiarity` |
| Faithfulness ratings for A and B | `responses_ratings` |
| Completeness ratings for A and B | `responses_ratings` |
| Coherence ratings for A and B | `responses_ratings` |
| Usefulness ratings for A and B | `responses_ratings` |
| Optional free-text comments per dimension | `responses_comments` |

Ratings are currently integers from 1 to 10. Each dimension has one rating for Version A and one for Version B.

## Save and Submit Behavior

`survey/submit.php` accepts POST requests from the viewer.

Two actions are supported:

| Action | Behavior |
|---|---|
| `save_later` | Saves any provided answers, permits incomplete responses, and returns to the dashboard. |
| `submit` | Requires familiarity plus all configured dimension ratings for both versions, saves responses, marks progress completed, and returns to the dashboard. |

The handler uses upserts, so returning to a segment and changing an answer updates the existing row instead of creating duplicates.

## Progress Logic

Progress is derived from the number of answered structured questions. With the current four dimensions, there are nine counted answers:

| Answered count | Status |
|---|---|
| 0 | `not_started` |
| 1-8 | `in_progress` |
| 9 | `completed` |

The nine counted answers are one familiarity answer plus eight ratings. Comments do not count toward completion.

Once a segment is completed, partial later saves do not downgrade it.

## Response Tables

| Table | Uniqueness |
|---|---|
| `responses_familiarity` | One row per user and segment. |
| `responses_ratings` | One row per user, segment, dimension, and version. |
| `responses_comments` | One row per user, segment, and dimension when a comment is provided. |
| `user_segment_progress` | One row per user and segment. |

Foreign keys cascade when users or segments are removed. Be careful with resource deletion scripts in a production study because deleting a segment also removes associated response/progress data.

## Analysis Notes

For analysis, join responses through:

```text
responses_* -> segments -> videos -> courses -> subjects
```

Use `segments.version_assignment` to recover which summary generation method was shown as Version A or B.

## Phase Boundaries

Phase 3 owns the evaluation interaction and response capture. It consumes resources prepared in Phase 2 and authenticated users from Phase 1. It does not manage user accounts or import raw resources.
