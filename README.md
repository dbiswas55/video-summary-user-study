# Video Detailed Summary - User Study

PHP + MySQL web application for the VideoPoints comparative evaluation study. Participants sign in, choose courses in their subject area, view assigned lecture chapters, compare two anonymized summary versions (A vs. B), rate the text summaries, and evaluate selected visual objects from each chapter.

The root README is intentionally short. Detailed, per-phase documentation lives in [`docs/`](docs/) and is the source of truth.

## Documentation Map

| Document | Scope |
|---|---|
| [`docs/phase0-setup-deployment.md`](docs/phase0-setup-deployment.md) | Local Mac/MAMP setup, `.env` configuration, database setup/reset, GitHub workflow, AWS/Windows subfolder deployment, Apache/IIS protection. |
| [`docs/phase1-user-system.md`](docs/phase1-user-system.md) | Login, self-registration, pre-issued accounts, consent (PDF or text), profile, course selection, admin pages (dashboard, manage, edit_user, messages, login-as), participant help guide, and email/one-click access. |
| [`docs/phase2-resources.md`](docs/phase2-resources.md) | Course/video/chapter resources, nested folder conventions, import + verify scripts, admin visualize/edit-objects tools, S3 transfer helper, database rows, dashboard display. |
| [`docs/phase3-survey.md`](docs/phase3-survey.md) | Chapter viewer, A/B summary comparison, two-part questionnaire (Part 1 text-summary ratings, Part 2 visual-object selection), save/submit behavior, response tables. |

## Current App Segments

| Phase | Status | Primary files |
|---|---|---|
| Phase 0 — setup and deployment | Active | `.env`, `app/config/config.php`, `app/sql/schema.sql`, `scripts/db.py`, `scripts/_db_common.py` |
| Phase 1 — user system | Active | `index.php`, `account/`, `contact.php`, `help.php`, `admin/` (dashboard, manage, edit_user, messages, switch_user) |
| Phase 2 — resources and dashboard | Active | `resources/`, `scripts/sync_videos.py`, `scripts/verify_chapter_objects.py`, `scripts/transfer_files_s3.py`, `admin/visualize.php`, `admin/edit_objects.php`, `dashboard.php`, resource helpers in `app/includes/functions.php` |
| Phase 3 — survey and response capture | Active | `survey/viewer.php`, `survey/submit.php`, response tables in `app/sql/schema.sql` |

## Quick Local Start

1. Start MAMP (Apache + MySQL).
2. Copy `.env.example` → `.env` and fill in local database, base URL, optional app URL, and optional email settings.
3. Create the Python environment and install requirements:

   ```bash
   python3 -m venv venv312
   source venv312/bin/activate
   pip install -r scripts/requirements.txt
   ```

4. Initialize the database. Open `scripts/db.py`, set the line in `main()` to `operation = "setup"`, then run:

   ```bash
   python scripts/db.py
   ```

5. After setup, change `operation` back to `None` so re-running the file is a no-op.
6. Open the app:

   ```text
   http://localhost:8888/userstudy2/index.php
   ```

> ⚠️ The checked-in `operation` value in `scripts/db.py` is occasionally left as `"reset"` during development. Confirm it before running — `"reset"` drops the database after a typed confirmation.

For the full setup/reset/GitHub/deployment workflow, see [`docs/phase0-setup-deployment.md`](docs/phase0-setup-deployment.md).

## Default Seed Accounts

`scripts/db.py` (with `operation = "setup"` or `"default-users"`) seeds three accounts:

| Username | Role | Subject | Course IDs |
|---|---|---|---|
| `admin` / `admin@123` | Admin | — | — |
| `test02` / `test02` | Participant | COSC | `390`, `533` |
| `test03` / `test03` | Participant | BIOL | `527`, `528` |

Seeded courses in the schema are `527` (BIOL2321), `528` (BIOL2301), `533` (COSC4393), and `390` (COSC4393 / Intro to HPC).

## Deployment Target

The intended AWS/Windows subfolder URL is:

```text
https://www.videopoints.org/public/sites/userstudy2/index.php
```

Use `BASE_URL=/public/sites/userstudy2/` and `APP_URL=https://www.videopoints.org/public/sites/userstudy2/` in the server `.env`.

## Data and Secrets

- Do not commit `.env`, `resources/` content, raw database dumps, or `venv312/`.
- Keep database credentials, Gmail app passwords, participant one-click links, and raw study resources out of GitHub.
- `app/config/VideopointsHRP-502a-ConsentForm.pdf` is served only through `account/consent_pdf.php`; direct access to `app/config/` is blocked by `.htaccess` / `web.config`.
