# Video Detailed Summary - User Study

PHP + MySQL web application for the VideoPoints comparative evaluation study. Participants sign in, choose courses in their subject area, view assigned lecture chapters, compare two anonymized summary versions, rate the text summaries, and evaluate selected visual objects for later analysis.

Detailed documentation lives in [`docs/`](docs/). The root README is intentionally short so the phase documents remain the source of truth.

## Documentation Map

| Document | Scope |
|---|---|
| [`docs/phase0-setup-deployment.md`](docs/phase0-setup-deployment.md) | Local Mac/MAMP setup, database setup, GitHub workflow, standalone AWS/Windows subfolder deployment, environment variables. |
| [`docs/phase1-user-system.md`](docs/phase1-user-system.md) | Login, registration, pre-issued accounts, profile, course selection, admin user management, contact messages, email/reset-link flow. |
| [`docs/phase2-resources.md`](docs/phase2-resources.md) | Course/video/chapter resources, nested folder conventions, import scripts, database rows, dashboard display. |
| [`docs/phase3-survey.md`](docs/phase3-survey.md) | Chapter viewer, A/B summary comparison, two-part questionnaire, save/submit behavior, response tables. |

## Current App Segments

| Phase | Status | Main files |
|---|---|---|
| Phase 0 - setup and deployment | Active | `.env`, `app/config/config.php`, `scripts/db.py`, `app/sql/schema.sql` |
| Phase 1 - user system | Active | `index.php`, `account/`, `contact.php`, `admin/` |
| Phase 2 - resources and dashboard | Active | `resources/`, `scripts/sync_videos.py`, `dashboard.php`, resource helpers in `app/includes/functions.php` |
| Phase 3 - survey and response capture | Active | `survey/viewer.php`, `survey/submit.php`, response tables in `app/sql/schema.sql` |

## Quick Local Start

1. Start MAMP.
2. Configure `.env` for your local database, base URL, optional app URL, and optional email settings.
3. Run the database setup:

```bash
# In scripts/db.py main(), set operation = "setup", then run:
python scripts/db.py
```

4. Open:

```text
http://localhost:8888/userstudy2/index.php
```

For the full setup, reset, GitHub, and deployment workflow, use [`docs/phase0-setup-deployment.md`](docs/phase0-setup-deployment.md).

## Deployment Target

The intended AWS/Windows subfolder URL is:

```text
https://www.videopoints.org/public/sites/userstudy2/index.php
```

Use `BASE_URL=/public/sites/userstudy2/` and `APP_URL=https://www.videopoints.org/public/sites/userstudy2/` in the server `.env`.

## Data and Secrets

The `.env` file and `resources/` content are local/server data and should not be committed. Keep database credentials, Gmail app passwords, participant links, and raw study resources out of GitHub.
