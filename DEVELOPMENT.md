# Development Setup (Mac with MAMP + VS Code)

This guide walks you through setting up the project on your Mac for local development.

---

## Prerequisites

- **MAMP** — installed and running
- **Python 3.8+** — `python3 --version` to check
- **VS Code** — with extensions
- **Git** — `git --version` to check

---

## One-Time Setup

### 1. Configure MAMP

Open MAMP → Preferences:
- **Ports tab**: note the MySQL port (default `8889`) and Apache port (default `8888`)
- **Server tab**: leave Document Root as default (we'll symlink to it)

Click **Start Servers** in MAMP — green light = MySQL is running.

### 2. Clone the Repo

```bash
cd ~/Projects
git clone git@github.com:YOURUSERNAME/userstudy2.git
cd userstudy2
```

### 3. Configure Environment

```bash
# Create your local .env from the template
cp .env.example .env

# Open in VS Code to edit
code .env
```

Default MAMP credentials are pre-filled (`root` / `root` / port `8889`). If you changed yours in MAMP Preferences, update them in `.env`.

### 4. Symlink to MAMP

```bash
ln -s "$(pwd)" /Applications/MAMP/htdocs/userstudy2
```

This lets MAMP serve the project files directly from your project folder. Edits in VS Code are immediately live in the browser.

### 5. Set Up Python Virtual Environment

```bash
# Create venv inside the project
python3 -m venv .venv

# Activate it
source .venv/bin/activate

# Install dependencies
pip install -r scripts/requirements.txt
```

You will know venv is active because your terminal prompt changes to show `(.venv)`.

### 6. Initialize the Database

```bash
# Make sure venv is activated
source .venv/bin/activate

# Create database, tables, seed data
python scripts/setup_db.py

# Set passwords for testuser and admin
python scripts/seed_passwords.py
```

### 7. Open VS Code

```bash
code .
```

When prompted, accept the recommended extensions:
- **PHP Intelephense** — PHP IntelliSense
- **MySQL** (Weijan Chen) — browse database in VS Code
- **Python** — for the setup scripts

### 8. Connect VS Code to MySQL (Optional)

Install the **MySQL** extension by Weijan Chen, then:
1. Click the database icon in the left sidebar
2. Click **+** to add a new connection:
   - **Host**: `localhost`
   - **Port**: `8889` (from MAMP)
   - **Username**: `root`
   - **Password**: `root`
3. Save the connection — you can now browse tables, run SQL, view data without leaving VS Code.

### 9. Verify Everything Works

Visit in your browser:

```
http://localhost:8888/userstudy2/db_test.php
```

You should see all green checkmarks. If something is red, fix that first.

Then visit:

```
http://localhost:8888/userstudy2/
```

Login with:
- Username: `testuser`
- Password: `testpass123`

---

## Daily Workflow

```bash
# Open MAMP (click "Start Servers")

# Open project in VS Code
cd ~/Projects/userstudy2
code .

# Activate Python venv only if you'll run scripts
source .venv/bin/activate

# Edit code → Save → Refresh browser at localhost:8888/userstudy2/

# Commit and push when ready
git add .
git commit -m "describe changes"
git push
```

---

## Useful Commands

### Reset database (start fresh)
```bash
source .venv/bin/activate
python scripts/reset_db.py
# Type the database name to confirm
```

### Browse tables in CLI (instead of VS Code extension)
```bash
/Applications/MAMP/Library/bin/mysql -h localhost -P 8889 -u root -p
# Password: root
USE userstudy_vds;
SHOW TABLES;
SELECT * FROM users;
```

### View PHP errors
With `DEBUG=true` in `.env`, errors are shown in the browser. To check Apache logs:
```bash
tail -f /Applications/MAMP/logs/php_error.log
```

---

## Adding Resources for a New Video

When videos and instructors are added (Phase 2+), place per-instructor resources at:

```
resources/
└── chad_wayne/                  # instructor_id from courses table
    └── bio1_1/                  # video_id from videos table
        ├── transcript.txt
        ├── summary_a.txt
        ├── summary_b.txt
        └── slides/
            ├── slide_1.png
            └── slide_2.png
```

These are gitignored — never committed to the repo. Copy them locally and to the server separately.

---

## Troubleshooting

**Browser shows "Database connection failed"**
- Is MAMP running? (green dots in MAMP app)
- Is `.env` correct? Default MAMP user/pass is `root`/`root`, port `8889`
- Visit `db_test.php` to see exactly what failed

**Can't access localhost:8888**
- MAMP port is 8888 by default. If yours is different, check MAMP Preferences → Ports
- Try `127.0.0.1:8888` instead of `localhost:8888`

**Python script fails with "ModuleNotFoundError"**
- Did you activate venv? `source .venv/bin/activate`
- Did you install requirements? `pip install -r scripts/requirements.txt`

**Symlink not working**
- Verify it exists: `ls -la /Applications/MAMP/htdocs/userstudy2`
- If missing, recreate: `ln -s "$(pwd)" /Applications/MAMP/htdocs/userstudy2`

**Need to reinstall venv**
```bash
rm -rf .venv
python3 -m venv .venv
source .venv/bin/activate
pip install -r scripts/requirements.txt
```
