# Phase 3 - Comparative Evaluation Survey

This phase covers the participant study page, A/B summary comparison, two-part questionnaire validation, progress updates, and response storage.

## Owned Files

| Area | Files |
|---|---|
| Viewer page | `survey/viewer.php` |
| Submission handler | `survey/submit.php` |
| Survey config | `app/config/study.json` |
| Shared helpers | `app/includes/auth.php`, `app/includes/functions.php` |
| Tables | `responses_familiarity`, `responses_ratings`, `responses_comments`, `responses_visual_objects`, `user_segment_progress` |

## Viewer Route

The viewer route is:

```text
survey/viewer.php?vid={video_id}&chapter={chapter_num}
```

`video_id` is the real video integer ID stored in `videos.video_id`, not the internal auto-increment `videos.id`. `chapter` is the chapter number stored in `segments.chapter_num`. If `chapter` is omitted, the viewer loads the first segment for that video by display order; dashboard links include both values so the intended chapter is explicit.

`viewer.php` requires login. It loads:

- Course/video/segment metadata from `videos`, `courses`, and `segments`.
- `transcript.vtt` from the video folder.
- Summary text from the chapter folder.
- Slide images from `chapter{N}/slides/`.
- Visual-object crop metadata from `chapter{N}/metadata.json` and crop images from `chapter{N}/visual_objects/`.
- MP4 URL from `getVideoUrl()`.
- Familiarity options, text-summary rating dimensions, visual-object question text, and scale labels from `app/config/study.json`.
- Previous responses for the current user and segment, so a partially saved survey can be resumed.

## Study Interface

The page presents:

- Video player.
- Transcript panel synced from `transcript.vtt`.
- Slide strip/lightbox.
- Summary Version A and Summary Version B.
- Normal summary view and Diff View.
- Part 1: text summary evaluation questions.
- Part 2: visual object selection questions.

The method behind Version A/B is hidden from participants. The mapping is stored in `segments.version_assignment`; see Phase 2 for details.

### Summary Comparison

Normal view renders both summaries as Markdown. Diff View now renders Markdown first and then applies highlights inside the rendered text nodes. This preserves headings, bullets, and list indentation better than injecting diff markup into raw Markdown before parsing.

The Summary Comparison section can be collapsed from the heading button.

### Video and Transcript Controls

The video is limited to the current chapter while **Single Chapter Only** is active. If playback seeks or reaches outside the chapter range, the viewer shows a concise notice explaining that full-video playback is available by turning off the restriction.

Native subtitles are hidden by default. When the user turns subtitles on, transcript-click seeking refreshes the active caption cue without disabling subtitles.

## Survey Questions

The participant-facing wording is configured in `app/config/study.json`:

| Config key | Use |
|---|---|
| `familiarity_question` | Q1 prompt. |
| `familiarity_options` | Q1 option IDs and labels. |
| `dimensions` | Rating dimension IDs, labels, and question text. |
| `visual_questions` | Part 2 visual-object question labels and question text. |
| `rating_scale` | Rating range and scale label text, for example `Scale: 1 = Poor → 10 = Excellent`. |

Keep dimension IDs and familiarity option IDs stable unless the database schema is also changed, because those IDs are stored in response tables. Visual question config IDs should also stay stable because the submit handler and table columns are named around the current model.

### Part 1 - Text Summary Evaluation

Part 1 includes:

| Question area | Stored in |
|---|---|
| Familiarity with the topic | `responses_familiarity` |
| Faithfulness ratings for A and B | `responses_ratings` |
| Completeness ratings for A and B | `responses_ratings` |
| Coherence ratings for A and B | `responses_ratings` |
| Usefulness ratings for A and B | `responses_ratings` |
| Optional free-text comments per dimension | `responses_comments` |

Ratings are currently integers from 1 to 10. Each dimension has one rating for Version A and one for Version B.

For progress, each text-summary dimension counts as one required question only when both Version A and Version B have ratings. Optional comments do not count toward completion.

### Part 2 - Visual Object Selection

Part 2 introduces the purpose of visual selection as enriching the summary with visual objects that could strengthen a user's review while using the summary.

The visual object display shows two groups:

| Group | Source | Labels |
|---|---|---|
| Selected Visual Objects | `metadata.json` key `visual_objects.selected` | `S1`, `S2`, ... |
| Unselected Visual Objects | `metadata.json` key `visual_objects.unselected` | `U1`, `U2`, ... |

The object grid is stacked vertically by group. The **Objects per row** slider changes how many object cards fit per row. Crop images keep their aspect ratio and have a thin border on the actual image area.

Part 2 has three required questions:

| Question | Config key | Stored in |
|---|---|---|
| Q1 Selection Quality | `visual_questions.selection_quality` | `responses_visual_objects.selection_quality_rating` |
| Q2 Include Important | `visual_questions.include_important` | `responses_visual_objects.include_important_labels` and `include_important_none` |
| Q3 Exclude Unimportant | `visual_questions.exclude_unimportant` | `responses_visual_objects.exclude_unimportant_labels` and `exclude_unimportant_none` |

Q2 offers unselected labels plus a required `None` option. Q3 starts with selected labels visually selected; participants unselect any selected object they consider unimportant, or choose `None`.

## Save and Submit Behavior

`survey/submit.php` accepts POST requests from the viewer.

Two actions are supported:

| Action | Behavior |
|---|---|
| `save_later` | Saves any provided answers, permits incomplete responses, and returns to the dashboard. |
| `submit` | Requires all Part 1 structured questions and all three Part 2 questions, saves responses, marks progress completed, and returns to the dashboard. |

The handler uses upserts, so returning to a segment and changing an answer updates the existing row instead of creating duplicates.

## Progress Logic

Progress is derived from answered required question groups, not raw sub-ratings. With the current four Part 1 dimensions, there are eight counted required questions:

- Part 1: five required questions, made of familiarity plus four text-summary dimensions.
- Part 2: three required visual-object questions.

| Answered count | Status |
|---|---|
| 0 | `not_started` |
| 1-7 | `in_progress` |
| 8 | `completed` |

For Part 1, a dimension counts only when both Version A and Version B ratings are present. For Part 2, Q2 and Q3 count when either at least one label is selected/unselected or the `None` option is chosen. Comments do not count toward completion.

Once a segment is completed, partial later saves do not downgrade it.

## Response Tables

| Table | Uniqueness |
|---|---|
| `responses_familiarity` | One row per user and segment. |
| `responses_ratings` | One row per user, segment, dimension, and version. |
| `responses_comments` | One row per user, segment, and dimension when a comment is provided. |
| `responses_visual_objects` | One row per user and segment for Part 2 visual-object responses. |
| `user_segment_progress` | One row per user and segment. |

Foreign keys cascade when users or segments are removed. Be careful with resource deletion scripts in a production study because deleting a segment also removes associated response/progress data.

## Analysis Notes

For analysis, join responses through:

```text
responses_* -> segments -> videos -> courses -> subjects
```

Use `segments.version_assignment` to recover which summary generation method was shown as Version A or B. Visual-object label responses are stored as JSON arrays of labels such as `["U2","U5"]` or `["S1"]`; resolve those labels back through the chapter metadata ordering when analyzing crop filenames.

## Phase Boundaries

Phase 3 owns the evaluation interaction and response capture. It consumes resources prepared in Phase 2 and authenticated users from Phase 1. It does not manage user accounts or import raw resources.
