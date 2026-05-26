# Phase 0 — Setup, Development, GitHub, and Deployment

This phase covers the project environment, database setup, server placement, deployment URLs, and file-protection rules. Participant account behavior, study resources, and survey logic are covered in [Phase 1](phase1-user-system.md), [Phase 2](phase2-resources.md), and [Phase 3](phase3-survey.md).

## Supported Targets

| Target | URL | Project folder | Notes |
|---|---|---|---|
| Local Mac / MAMP | `http://localhost:8888/userstudy2/index.php` | `/Applications/MAMP/htdocs/userstudy2` | Primary development environment. |
| AWS Windows subfolder | `https://www.videopoints.org/public/sites/userstudy2/index.php` | `public/sites/userstudy2/` under the existing site root | Production/staging copy target. |

The app is written to run from a subdirectory. Two `.env` settings make this work:

| Setting | Local MAMP | AWS Windows |
|---|---|---|
| `BASE_URL` | `/userstudy2/` | `/public/sites/userstudy2/` |
| `APP_URL` | `http://localhost:8888/userstudy2/` | `https://www.videopoints.org/public/sites/userstudy2/` |

- `BASE_URL` is used for browser-relative paths inside the app.
- `APP_URL` is used when generating **absolute** one-click account links for emails and admin pages.

## Runtime Requirements

| Component | Local development | AWS Windows subfolder |
|---|---|---|
| PHP | MAMP PHP, 7.4+ compatible | Existing PHP 7.4 site with either PDO MySQL or mysqli enabled. |
| MySQL | MAMP MySQL (commonly port `8889`, socket `/Applications/MAMP/tmp/mysql/mysql.sock`) | MySQL/MariaDB reachable from PHP and optional Python scripts. |
| Python | 3.8+ for setup/import/admin scripts | Optional if you set up/import elsewhere or import SQL manually. |
| Web server | Apache through MAMP | IIS or Apache. Root `web.config` protects IIS; `.htaccess` protects Apache. |
| HTTPS | Optional locally | Required for production participant use. |

The PHP code is PHP 7.4–compatible and has no Composer dependency.

## Environment File

Create `.env` in the project root by copying `.env.example`. **Never commit `.env`.**

```bash
cp .env.example .env
```

### Local MAMP example

```text
DB_HOST=localhost
DB_PORT=8889
DB_NAME=userstudy_vds
DB_USER=root
DB_PASS=root
DB_SOCKET=/Applications/MAMP/tmp/mysql/mysql.sock

BASE_URL=/userstudy2/
APP_URL=http://localhost:8888/userstudy2/
DEBUG=true
SESSION_LIFETIME=3600

RESOURCES_URL=
VIDEO_ROOT_URL=

MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=thevideopoints@gmail.com
MAIL_PASSWORD=your-gmail-app-password
MAIL_FROM_EMAIL=thevideopoints@gmail.com
MAIL_FROM_NAME=VideoPoints User Study
ADMIN_NOTIFY_EMAIL=thevideopoints@gmail.com
```

### AWS Windows subfolder example

```text
DB_HOST=localhost
DB_PORT=3306
DB_NAME=userstudy_vds
DB_USER=production_db_user
DB_PASS=production_db_password
DB_SOCKET=

BASE_URL=/public/sites/userstudy2/
APP_URL=https://www.videopoints.org/public/sites/userstudy2/
DEBUG=false
SESSION_LIFETIME=3600

RESOURCES_URL=
VIDEO_ROOT_URL=

MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=thevideopoints@gmail.com
MAIL_PASSWORD=production-gmail-app-password
MAIL_FROM_EMAIL=thevideopoints@gmail.com
MAIL_FROM_NAME=VideoPoints User Study
ADMIN_NOTIFY_EMAIL=thevideopoints@gmail.com
```

### Resource URL overrides

Leave `RESOURCES_URL` and `VIDEO_ROOT_URL` empty when the `resources/` folder is served from the same project folder. With the AWS values above, resource URLs default to:

```text
https://www.videopoints.org/public/sites/userstudy2/resources/
```

- Set `RESOURCES_URL` only if chapter slides, summary text, visual-object crops, and similar static files live on a different static host.
- Set `VIDEO_ROOT_URL` only if MP4 files and per-video `transcript.vtt` files are hosted separately (e.g., on a CDN).

## Local Development Setup

1. Start MAMP and confirm Apache + MySQL ports in MAMP preferences.
2. Place the repo under MAMP's document root, or symlink your working copy:

   ```bash
   ln -s "$(pwd)" /Applications/MAMP/htdocs/userstudy2
   ```

3. Create and activate the Python environment:

   ```bash
   python3 -m venv venv312
   source venv312/bin/activate
   pip install -r scripts/requirements.txt
   ```

4. Copy `.env.example` → `.env` and fill in the local MAMP values above.
5. Initialize the database and seed default users:

   ```bash
   # In scripts/db.py main(), set operation = "setup", then run:
   python scripts/db.py
   ```

6. After setup, set `operation = None` in `scripts/db.py` so rerunning the file does not accidentally change the database.
7. Open:

   ```text
   http://localhost:8888/userstudy2/index.php
   ```

`tests/db_test.php` can help diagnose local connection issues. Keep `tests/` blocked or removed in production.

## Database Operations

`scripts/db.py` is the preferred setup path because it reads `DB_NAME` from `.env` and rewrites the database name in `app/sql/schema.sql` before executing it. Shared connection helpers live in `scripts/_db_common.py`.

| Operation | Use |
|---|---|
| `setup` | Run the full schema and insert/update default users. Use for a fresh local or fresh server database. |
| `default-users` | Refresh only the default admin/test credentials and seeded course assignments — no schema changes. |
| `reset` | **Dev only.** Drops and recreates `DB_NAME` after typing the database name as confirmation, then runs `setup`. |
| `None` | No database action. This should be the checked-in / default state when no work is being done. |

> ⚠️ The script may temporarily ship with a different active operation during development (for example, `operation = "reset"`). Always verify the active line in `main()` before running.

### Default seeded users

Defined in `SEED_USERS` at the top of `scripts/db.py`:

| Username | Password | Role | Subject | Course IDs |
|---|---|---|---|---|
| `admin` | `admin@123` | Admin | — | — |
| `test02` | `test02` | Participant | COSC (id 2) | `390`, `533` |
| `test03` | `test03` | Participant | BIOL (id 1) | `527`, `528` |

Once Phase 2 resources are synced for those course IDs, the test accounts immediately have matching dashboard content.

### Seeded subjects and courses

`app/sql/schema.sql` seeds two subjects and four courses:

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

Manual SQL import is possible, but `app/sql/schema.sql` contains `CREATE DATABASE IF NOT EXISTS userstudy_vds` and `USE userstudy_vds`. If the production database has a different name and you are not using `scripts/db.py`, edit those two lines before importing.

For any production database containing study data, **avoid destructive resets**. Back up first and prefer explicit migrations.

## Supporting Scripts in `scripts/`

| Script | Purpose |
|---|---|
| `db.py` | Phase 0 schema setup, default-user seeding, dev reset. |
| `manage_users.py` | Phase 1 helper: create pre-issued participants, refresh one-click URLs, delete users. |
| `sync_videos.py` | Phase 2 importer: scans `resources/` and adds/removes videos and segments. |
| `verify_chapter_objects.py` | Phase 2 validator: checks one chapter's `detection_data.json` + `metadata.json` + crops and renders annotated slides. |
| `transfer_files_s3.py` | Convenience helper to upload/download study resources between local and S3. |
| `_db_common.py` | Shared `.env` reader and MySQL connector used by the other Python scripts. |

## AWS Windows Subfolder Deployment

Target public URL:

```text
https://www.videopoints.org/public/sites/userstudy2/index.php
```

Target folder under the existing site:

```text
public/sites/userstudy2/
```

Deployment outline:

1. Copy the project files into `public/sites/userstudy2/`.
2. Do **not** copy local-only items: `.git/`, `venv312/`, `.DS_Store`, caches, local DB dumps, MAMP logs.
3. Create `.env` in `public/sites/userstudy2/` with the AWS values above.
4. Confirm the server has PHP 7.4 with PDO MySQL or mysqli enabled.
5. Create the MySQL database/user if needed.
6. Run `scripts/db.py` with `operation = "setup"` if Python is available; otherwise import `app/sql/schema.sql` manually after confirming the database name.
7. Copy resource folders into `resources/`, or configure `RESOURCES_URL` / `VIDEO_ROOT_URL`.
8. Visit `https://www.videopoints.org/public/sites/userstudy2/index.php`.
9. Verify: sign-in, registration, profile, admin user edit, contact messages, dashboard course/video/chapter grouping, survey viewer, Part 1/Part 2 save & submit, email and one-click access links.

### IIS protection (web.config)

The root `web.config` is included for IIS/Windows deployments. It disables directory browsing and blocks direct browser access to:

- `.env`
- `app/`
- `scripts/`
- `tests/`
- `.py`, `.sql`, `.dump`, and `.log` files

The PHP app can still include files from `app/` internally. Public requests should go through the top-level PHP pages, `account/`, `admin/`, `survey/`, `assets/`, and `resources/`.

### Apache protection (.htaccess)

Apache deployments use:

- root `.htaccess` to disable directory listing and block `.env`, Python, SQL, dump, and log files.
- `app/.htaccess`, `app/config/.htaccess`, `app/includes/.htaccess`, `scripts/.htaccess`, and `tests/.htaccess` to deny direct browser access to internal/test folders.

These require Apache `AllowOverride` support. If the server ignores `.htaccess`, enforce the same restrictions in the virtual-host/server config.

## Switching Between Local and AWS

Switch environments by changing the target `.env` file. PHP code should not need environment-specific edits.

| Setting | Local Mac / MAMP | AWS Windows subfolder |
|---|---|---|
| `BASE_URL` | `/userstudy2/` | `/public/sites/userstudy2/` |
| `APP_URL` | `http://localhost:8888/userstudy2/` | `https://www.videopoints.org/public/sites/userstudy2/` |
| `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME` | MAMP MySQL values | Production database values |
| `DB_SOCKET` | MAMP socket path | Empty |
| `DEBUG` | Usually `true` while developing | `false` |

After switching, verify that normal links, asset URLs, resource URLs, and one-click access URLs all use the expected host and subfolder.

## Updating an Existing Server Copy

For normal code updates:

```bash
git pull
```

…or copy the changed files into the deployed `userstudy2` folder.

PHP is interpreted at runtime, so a web-server restart is usually not needed for PHP-only changes. If `.env`, IIS config, Apache config, or PHP extensions change, an app-pool / web-server reload may be required.

If schema changed, apply the relevant migration, or run `scripts/db.py` with `operation = "setup"` **only when safe** for that environment.

## GitHub Workflow

Recommended flow:

```bash
git status
git add README.md docs/ path/to/changed/files
git commit -m "Describe the change"
git push
```

Before committing:

- Keep `.env` out of Git.
- Keep raw `resources/` content out of Git unless a small placeholder is intentional.
- Avoid committing generated caches, local DB dumps, MAMP logs, virtual environments, or OS metadata files.
- Run `php -l` on changed PHP files.
- Activate the project virtual environment before running Python scripts.

## Production Checklist

- [ ] `DEBUG=false`.
- [ ] `BASE_URL=/public/sites/userstudy2/`.
- [ ] `APP_URL=https://www.videopoints.org/public/sites/userstudy2/`.
- [ ] Strong database password.
- [ ] `.env` exists on the server and is not web-accessible.
- [ ] IIS `web.config` or Apache `.htaccess` protections are active.
- [ ] `tests/` is removed or blocked from public access.
- [ ] HTTPS active.
- [ ] Database backup scheduled.
- [ ] Resource backup/sync strategy decided.
- [ ] Gmail app password stored only in `.env`.
- [ ] One-click links generated by admin / password-help use the public AWS URL.

## Compatibility Notes

- Forward-slash paths in PHP are safe on Windows; PHP resolves `__DIR__ . '/../...'` paths correctly.
- `DB_SOCKET` should be empty on Windows/AWS.
- Python scripts are convenience tools, not required at request time by the PHP app.
- `resources/` must remain browser-readable unless you configure external `RESOURCES_URL` and `VIDEO_ROOT_URL`.
- `app/config/VideopointsHRP-502a-ConsentForm.pdf` is served through `account/consent_pdf.php`; direct access to `app/config/` should stay blocked.

## Phase Boundaries

Phase 0 owns environment, database initialization, GitHub workflow, and deployment. It does **not** define participant account behavior, resource-import semantics, or survey logic; those belong to Phases 1–3.
