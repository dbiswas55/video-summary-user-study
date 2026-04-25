# Video Detailed Summary — User Study 2

PHP + MySQL survey system for the VDS comparative user study.

## Status: Phase 1 (Authentication)

✅ Login + 4-step registration with consent
✅ Subject + courses selection
✅ Pre-issued and self-registered accounts
✅ Session management with bcrypt passwords
✅ JSON-based config for consent and study metadata
✅ Python scripts for database setup
✅ DB connection test endpoint

## Quick Links

- 📘 [Mac development setup](DEVELOPMENT.md)
- 🚀 [AWS deployment guide](DEPLOYMENT.md)

## Project Layout

```
userstudy2/
├── .env                  Local secrets (NOT in git)
├── .env.example          Template for .env
├── /config/              JSON configs + PHP config
├── /includes/            Shared PHP partials
├── /scripts/             Python DB setup scripts
├── /sql/                 Schema
├── /resources/           Per-instructor video assets (gitignored)
├── /chapters/fragments/  Comparison page fragments (Phase 3)
├── /admin/               Admin panel (Phase 5)
├── /assets/              CSS, JS, images
├── index.php             Landing page
├── login.php / logout.php
├── register.php          4-step registration
├── dashboard.php         User dashboard
└── db_test.php           DB connection test (delete in production)
```

## Editable Config (No Code Required)

| File | What you edit |
|---|---|
| `.env` | DB credentials, base URL, debug flag |
| `config/consent.json` | Consent form text + version |
| `config/study.json` | Study title, dimensions, scale, dates |
| `config/resources.json` | Default file names |

## Phases Roadmap

- ✅ **Phase 1** — Authentication
- ⏳ **Phase 2** — Full dashboard with videos & segments
- ⏳ **Phase 3** — Segment study page with A/B comparison
- ⏳ **Phase 4** — AJAX submission, response storage
- ⏳ **Phase 5** — Admin panel with CSV export
- ⏳ **Phase 6** — Polish, edge cases, testing
