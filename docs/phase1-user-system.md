# Phase 1 — User System

This phase covers identity and access: login, registration with consent, profile management, admin user/resource operations, participant contact messages, the participant help guide, and one-click access links.

## Owned Files

| Area | Files |
|---|---|
| Public entry | `index.php`, `account/login.php`, `account/logout.php`, `account/register.php`, `account/forgot_password.php`, `account/auto_login.php` |
| Consent config & PDF | `app/config/consent.json`, `app/config/VideopointsHRP-502a-ConsentForm.pdf`, `account/consent_pdf.php` |
| Participant pages | `account/profile.php`, `dashboard.php` (course selection touchpoints), `contact.php`, `help.php` |
| Admin pages | `admin/dashboard.php`, `admin/manage.php`, `admin/edit_user.php`, `admin/messages.php`, `admin/switch_user.php`, `admin/index.php` (legacy users table) |
| Shared helpers | `app/includes/auth.php`, `app/includes/functions.php`, `app/includes/header.php`, `app/includes/footer.php`, `app/includes/mailer.php` |
| Page assets (CSS) | `assets/css/main.css`, `auth.css`, `register.css`, `profile.css`, `contact.css`, `dashboard.css`, `help.css`, `admin.css`, `admin-dashboard.css`, `admin-manage.css`, `messages.css` |
| Page assets (JS) | `assets/js/common.js`, `auth.js`, `register.js`, `admin-edit-user.js` |
| CLI tools | `scripts/manage_users.py` (participants); `scripts/db.py` with `operation = "default-users"` for refreshing defaults |
| Tables | `users`, `user_courses`, `contact_messages` |

## Account Types

| Type | Created by | Initial access | Later access |
|---|---|---|---|
| Admin | `scripts/db.py` (`operation = "setup"` or `"default-users"`) | Username/email + password | Username/email + password |
| Self-registered participant | `account/register.php` | Username/email + password | Username/email + password |
| Pre-issued participant | `scripts/manage_users.py` or `admin/edit_user.php` | One-click link, **or** username + email while no password is set | One-click link, or username/email + password after password is set |

Pre-issued users can set a password from `account/profile.php`. Saving a password does **not** clear `users.login_token`; one-click links remain valid until an admin regenerates/revokes the link or deactivates the account.

## Login Flow

`index.php` shows the sign-in form. Current rules:

| Inputs | Allowed for | Handler behavior |
|---|---|---|
| Username or email + password | Admins, self-registered users, pre-issued users with a password set | `account/login.php` finds by username/email and verifies `password_hash`. |
| Username + email, no password | Pre-issued users only, only before a password is set | `account/login.php` requires matching username/email, `account_type = 'pre_issued'`, empty `password_hash`, and an active account. |
| One-click URL | Users with an active `login_token` | `account/auto_login.php` signs the user in and sends them to `dashboard.php` (participants) or `admin/dashboard.php` (admins). |

The login page exposes a **Need a one-click access link?** option. `account/forgot_password.php` sends a one-click link only when the submitted email belongs to an active non-admin account — the browser always receives a generic message so account existence is not leaked.

## Registration Flow

`account/register.php` is a four-step self-registration wizard:

| Step | Purpose |
|---|---|
| 1 | Consent from `app/config/consent.json` (PDF or inline text). Stores `consent_version` and `consent_timestamp`. |
| 2 | Required username, required password + confirm, optional email. Email can later be used for sign-in. |
| 3 | Required subject selection, with a note that the subject area cannot be changed later by the participant. |
| 4 | Course selection within the chosen subject, using the same compact checkbox layout as `account/profile.php`. |

Registration creates a `self_registered` user, inserts selected rows into `user_courses`, logs the user in, and redirects to `dashboard.php`.

### Consent Display Modes

`app/config/consent.json` controls step 1 via `display_mode`:

| Value | Behavior |
|---|---|
| `pdf` | Shows the configured PDF from `pdf.filename` through `account/consent_pdf.php`, plus the PDF-specific agreement checkbox label. |
| `text` | Shows the inline `sections` text and the `agreement_label`. |

Key settings (current PDF mode):

| Setting | Role |
|---|---|
| `title` | Short page heading (currently `Consent Form`). |
| `study_name` | Participant-facing study name. |
| `version` | Internal consent version stored on the user record; not shown to the participant. |
| `pdf.filename` | PDF file under `app/config/`. |
| `pdf.intro` | Short instruction paragraph above the embedded PDF. |
| `pdf.agreement_label` | Checkbox label before continuing to account setup. |

The consent PDF stays under `app/config/`; that folder is not directly browsable (`.htaccess` / `web.config`). `account/consent_pdf.php` validates that the configured file exists and has a `.pdf` extension before streaming it as `application/pdf`.

## Profile Flow

`account/profile.php` is the participant-facing account page.

Current behavior:

- Shows username, email status, password status, subject, and course selection.
- Does **not** show account type, registration date, or last-login details.
- Allows users without an email to add one.
- Allows password setup/change with only `new password` + `confirm password`; current password is not required.
- Keeps any existing `login_token` after password save.
- Shows a short hint **only** when no password is set, explaining that the user can add one for password-based sign-in.
- Allows course changes only within the user's existing subject.
- Uses the same compact course checkbox layout as registration. Shared styles live in `assets/css/main.css`.

Subject changes remain an admin-only operation — the subject area is treated as fixed after registration.

## Participant Help Guide

`help.php` is a logged-in participant guide that explains the study flow, dashboard, viewer controls, and Part 1 / Part 2 question structure. It is linked from the header's **Help** nav item and uses `assets/css/help.css`. The page includes a print-only header so participants can print or save the guide as PDF.

## Admin Pages

The header's **Admin** link points to `admin/manage.php`. The legacy `admin/index.php` users table is still present.

| Page | Purpose |
|---|---|
| `admin/dashboard.php` | Admin landing page: high-level stats — total participants, active in last 7 days, never-logged-in, assigned chapters, etc. Uses `assets/css/admin.css` + `admin-dashboard.css`. |
| `admin/manage.php` | Combined users table + course/video/segments table with shortcuts to `edit_user.php`, `edit_objects.php`, `visualize.php`, and `switch_user.php`. Uses `assets/css/admin.css` + `admin-manage.css`. |
| `admin/edit_user.php` | Per-participant: update subject + course assignments, generate/revoke a one-click access link, deactivate/reactivate. Uses `assets/js/admin-edit-user.js` for the copy-link button. |
| `admin/messages.php` | Contact messages grouped by sent-before-login vs. after-login. Opening the page marks unread as read after rendering (so newly opened messages still show as `New` on that view). Admins can delete messages. |
| `admin/switch_user.php` | POST-only "Login As" handler — ends the current admin session and signs in as the selected non-admin, active participant. Triggered by a confirmed form in `admin/manage.php`. |

The generated one-click URL uses `absoluteUrl()`, which follows `APP_URL` from `.env`:

```text
# Local
APP_URL=http://localhost:8888/userstudy2/

# Production
APP_URL=https://www.videopoints.org/public/sites/userstudy2/
```

> Phase 2 admin tools (`admin/visualize.php`, `admin/edit_objects.php`, `admin/save_objects_ajax.php`) live next to these pages but are documented in [Phase 2](phase2-resources.md).

## CLI: `scripts/manage_users.py`

Used for pre-issued participant accounts. **Admin creation remains a Phase 0 responsibility in `scripts/db.py`.**

Operations are selected by uncommenting one line in `main()`:

| Operation | Purpose |
|---|---|
| `create-users` | Insert each entry in `USERS_TO_CREATE` (username, password, subject_code, course_ids), verifying first that at least one active admin already exists. |
| `retrieve-login-info` | Print username/password/one-click URL for pre-issued non-admin users. Supports `refresh_pw` and `refresh_token` flags. |
| `delete-users` | Interactive deletion of non-admin users (per-row `y/N/q` prompt). |

The script builds one-click URLs from `APP_URL`, falling back to `BASE_URL`, and then to `SERVER_HOST + BASE_URL` for cases where only `BASE_URL` is a relative path.

## Contact and Email

`contact.php` stores messages in `contact_messages`:

- Logged-in users are attached by `user_id`.
- Anonymous/pre-login messages store the entered name and optional email.

When `.env` mail settings are present, `app/includes/mailer.php` emails the configured admin notification address. The same helper sends one-click links from `account/forgot_password.php`.

Required mail-related `.env` keys:

```text
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=thevideopoints@gmail.com
MAIL_PASSWORD=your-gmail-app-password
MAIL_FROM_EMAIL=thevideopoints@gmail.com
MAIL_FROM_NAME=VideoPoints User Study
ADMIN_NOTIFY_EMAIL=thevideopoints@gmail.com
```

Use a Gmail **app password**, not the normal account password.

## Database Tables

| Table | Role |
|---|---|
| `users` | Username, optional email, password hash, subject, account type, active/admin flags, one-click `login_token`, login timestamps. |
| `user_courses` | Participant–course assignments used by Profile and Dashboard. |
| `contact_messages` | Messages sent before or after login. |

Full definitions live in `app/sql/schema.sql`.

## Page Asset Reference

| File | Used by |
|---|---|
| `assets/js/common.js` | Password show/hide buttons on login and profile. |
| `assets/js/auth.js` | Login/register tab switching, forgot-password form toggle, login input hints. |
| `assets/js/register.js` | Client-side password validation for registration step 2 (server validation remains authoritative). |
| `assets/js/admin-edit-user.js` | Copy button for admin-generated one-click access links. |
| `assets/css/admin.css`, `admin-dashboard.css`, `admin-manage.css`, `messages.css` | Admin pages. |
| `assets/css/auth.css`, `register.css`, `profile.css`, `contact.css`, `dashboard.css`, `help.css` | Participant pages. |

No legacy `assets/js/pages/`, `assets/js/common/password-toggle.js`, or `assets/css/pages/` files exist in the current layout.

## Phase Boundaries

Phase 1 owns user identity and participant access. It touches course selection because users need assigned courses, but the resource/video content for those courses belongs to Phase 2. It does not own the survey viewer or response storage — those belong to Phase 3.
