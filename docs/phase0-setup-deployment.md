# Phase 0 - Setup, Development, GitHub, and Deployment

This phase covers the project environment rather than participant-facing app behavior.

## Local Development Target

The current development setup is a Mac laptop running MAMP:

| Component | Expected setup |
|---|---|
| Web server | MAMP Apache, commonly `http://localhost:8888/` |
| MySQL | MAMP MySQL, commonly port `8889` with socket `/Applications/MAMP/tmp/mysql/mysql.sock` |
| PHP | MAMP PHP with PDO MySQL enabled |
| Python | Python 3.8+ for database, resource sync, and admin scripts |
| Project path | `/Applications/MAMP/htdocs/userstudy2` |

## Environment File

Create `.env` in the project root. Do not commit it.

Local MAMP example:

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

Production example for a subfolder deployment:

```text
DB_HOST=localhost
DB_PORT=3306
DB_NAME=userstudy_vds
DB_USER=production_db_user
DB_PASS=production_db_password
DB_SOCKET=

BASE_URL=/userstudy2/
APP_URL=https://videopoints.org/userstudy2/
DEBUG=false
SESSION_LIFETIME=3600

RESOURCES_URL=
VIDEO_ROOT_URL=
```

Use `RESOURCES_URL` when slides/text are served from a different static root. Use `VIDEO_ROOT_URL` when MP4 files are hosted separately or on a CDN.

## First Local Setup

1. Start MAMP.
2. Confirm Apache and MySQL ports in MAMP preferences.
3. Put the repo under MAMP's document root, or symlink your working copy:

```bash
ln -s "$(pwd)" /Applications/MAMP/htdocs/userstudy2
```

4. Create and activate a Python environment:

```bash
python3 -m venv venv312
source venv312/bin/activate
pip install -r scripts/requirements.txt
```

5. Initialize the database and default Phase 0 admin/test users:

```bash
# In scripts/db.py main(), set operation = "setup", then run:
python scripts/db.py
```

6. Visit:

```text
http://localhost:8888/userstudy2/
```

`tests/db_test.php` can help diagnose local connection issues. Do not leave it publicly accessible in production.

## Database Reset

For development only:

```bash
# In scripts/db.py main(), set operation = "reset", then run:
python scripts/db.py
```

The `reset` operation is destructive. It drops and recreates the database after a confirmation prompt.

To refresh only the default admin/test user credentials without rebuilding the schema:

```bash
# In scripts/db.py main(), set operation = "default-users", then run:
python scripts/db.py
```

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
- Keep `resources/` out of Git unless a small placeholder is intentional.
- Avoid committing generated caches, local database dumps, or MAMP-specific logs.
- Run `php -l` on changed PHP files.
- Run Python scripts with the project virtual environment active.

## Updating an Existing Server

For normal code updates:

```bash
git pull
```

PHP is interpreted at runtime, so a web-server restart is usually not needed for PHP-only changes.

If schema changed, apply the relevant migration or rerun the setup only when it is safe for that environment:

```bash
# In scripts/db.py main(), set operation = "setup", then run:
python scripts/db.py
```

For production databases containing study data, avoid destructive resets. Back up first, and prefer explicit migrations.

## AWS or Existing-Server Subfolder Deployment

The app is designed to run in a subfolder, for example:

```text
https://videopoints.org/userstudy2/
```

Server requirements:

- PHP 7.4+ with PDO MySQL.
- MySQL 5.7+ or MariaDB 10.3+.
- Python 3.8+ for database, resource sync, and admin scripts.
- Apache, IIS, or another PHP-capable web server.
- HTTPS enabled.

Deployment outline:

1. Clone or pull the repo into the server's web root subfolder.
2. Create `.env` with production DB credentials and `DEBUG=false`.
3. Set `BASE_URL` and `APP_URL` to the deployed subfolder.
4. Install Python dependencies if scripts will run on the server.
5. In `scripts/db.py` `main()`, set `operation = "setup"`, then run `python scripts/db.py` on a fresh database.
6. Copy resource folders to `resources/`, or configure `RESOURCES_URL`/`VIDEO_ROOT_URL`.
7. Verify the site, login, dashboard, viewer, contact form, and email flow.

## Production Checklist

- [ ] `DEBUG=false`.
- [ ] Strong database password.
- [ ] `.env` not web-accessible.
- [ ] `app/` and `scripts/` protected from direct browsing where possible.
- [ ] `tests/db_test.php` removed or protected.
- [ ] HTTPS active.
- [ ] Database backup scheduled.
- [ ] Resource backup/sync strategy decided.
- [ ] Gmail app password stored only in `.env`.
- [ ] `APP_URL` points to the public subfolder URL so one-click links are correct.

## Phase Boundaries

Phase 0 owns environment, database initialization, GitHub workflow, and deployment. It does not define participant account behavior, resource import semantics, or survey logic; those are covered by Phases 1-3.
