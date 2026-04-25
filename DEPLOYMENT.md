# AWS Deployment (Windows Server)

This guide walks you through deploying the project to your AWS Windows Server.

---

## Prerequisites on the Server

- **PHP 7.4+ with PDO MySQL extension**
- **MySQL 5.7+ or MariaDB 10.3+**
- **Python 3.8+** (for setup scripts)
- **Git** (for `git clone`)
- **IIS or Apache** configured to serve PHP

---

## First-Time Deployment

### 1. Clone the Repo

Connect to your AWS server via RDP or SSH, then:

```cmd
cd C:\inetpub\wwwroot\sites
git clone https://github.com/YOURUSERNAME/userstudy2.git
cd userstudy2
```

(Use `git@github.com:...` for SSH if you prefer.)

### 2. Configure Environment

```cmd
copy .env.example .env
notepad .env
```

Set production values:
```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=userstudy_vds
DB_USER=YOUR_PROD_DB_USER
DB_PASS=YOUR_PROD_DB_PASSWORD
DB_SOCKET=

BASE_URL=/sites/userstudy2/

DEBUG=false

VIDEO_ROOT_URL=https://your-server.com/videos
SESSION_LIFETIME=3600
```

**Important:**
- Leave `DB_SOCKET` empty on Windows
- Set `DEBUG=false` for production
- Use strong DB password

### 3. Set Up Python Environment

```cmd
python -m venv .venv
.venv\Scripts\activate
pip install -r scripts\requirements.txt
```

### 4. Initialize Database

```cmd
python scripts\setup_db.py
python scripts\seed_passwords.py
```

### 5. Adjust Web Server Config

Make sure the web server points to:
- URL: `https://your-domain/sites/userstudy2/`
- Folder: `C:\inetpub\wwwroot\sites\userstudy2\`

If using IIS, you may need a `web.config` file (not provided — Phase 1 uses Apache `.htaccess`).

### 6. Verify

Visit `https://your-domain/sites/userstudy2/db_test.php`

Check all items are green. **Then delete `db_test.php` from the server** for security.

### 7. Place Video Resources

Copy your `/resources/` folder structure to the server:
```
C:\inetpub\wwwroot\sites\userstudy2\resources\
└── chad_wayne\
    └── bio1_1\
        ├── transcript.txt
        ├── summary_a.txt
        ├── summary_b.txt
        └── slides\
```

These are NOT in git — you transfer them manually (FTP, SCP, or RDP file copy).

---

## Updating the Production Site

When you push code from Mac:

```cmd
cd C:\inetpub\wwwroot\sites\userstudy2
git pull
```

That is it. PHP is interpreted at runtime, so no restart is needed unless you change config or schema.

If you changed the database schema:
```cmd
.venv\Scripts\activate
python scripts\setup_db.py    # idempotent — uses CREATE TABLE IF NOT EXISTS
```

If you changed seed users:
```cmd
python scripts\seed_passwords.py
```

---

## Production Hardening Checklist

Before going live:

- [ ] `DEBUG=false` in `.env`
- [ ] Delete `db_test.php` (or password-protect it)
- [ ] Delete `sql/generate_passwords.php` if it exists
- [ ] Use HTTPS (TLS certificate via Let's Encrypt or AWS Certificate Manager)
- [ ] Strong DB password (not `root` / `root`)
- [ ] DB user has only the privileges it needs (no DROP, GRANT, etc.)
- [ ] Confirm `.env` is not accessible via web (try visiting `/sites/userstudy2/.env`)
- [ ] Confirm `/config/` is not accessible (try `/sites/userstudy2/config/config.php`)
- [ ] Set up daily MySQL backup (e.g., `mysqldump` via Task Scheduler)
- [ ] Monitor disk space and PHP error logs

---

## Backup Strategy

Daily MySQL dump example (Windows Task Scheduler):

```cmd
"C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe" -u backup_user -pBACKUP_PASS userstudy_vds > C:\backups\userstudy_%DATE:~10,4%-%DATE:~4,2%-%DATE:~7,2%.sql
```

Schedule this to run nightly.

---

## Troubleshooting

**"Database connection failed"**
- Verify `.env` values match your AWS MySQL credentials
- Check MySQL is running: `services.msc` → look for MySQL
- Check the server can connect: `mysql -u user -p -h localhost`

**"500 Internal Server Error"**
- Check PHP error log (Apache: `error.log`; IIS: Event Viewer)
- Set `DEBUG=true` temporarily in `.env` to see the error in browser

**Permissions errors writing sessions**
- PHP needs write access to its session folder (default: `C:\Windows\Temp` or PHP's tmp folder)

**`git pull` says "denied"**
- Use SSH keys or a Personal Access Token (PAT)
- See GitHub docs on authentication for Windows
