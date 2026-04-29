# Phase 1 - User System

This phase covers identity, access, registration consent, profile management, admin user operations, participant contact messages, and one-click access links.

## Owned Files

| Area | Files |
|---|---|
| Public entry | `index.php`, `account/login.php`, `account/logout.php`, `account/register.php`, `account/forgot_password.php`, `account/auto_login.php` |
| Consent config and PDF | `app/config/consent.json`, `app/config/VideopointsHRP-502a-ConsentForm.pdf`, `account/consent_pdf.php` |
| Participant account | `account/profile.php`, `dashboard.php` user summary/course selection touchpoints |
| Admin account work | `admin/index.php`, `admin/edit_user.php`, `admin/messages.php` |
| Shared helpers | `app/includes/auth.php`, `app/includes/functions.php`, `app/includes/header.php`, `app/includes/footer.php`, `app/includes/mailer.php` |
| Page assets | `assets/css/auth.css`, `assets/css/register.css`, `assets/css/profile.css`, `assets/css/admin.css`, `assets/css/messages.css`, `assets/css/contact.css`, shared styles in `assets/css/main.css`, and page scripts in `assets/js/` |
| CLI tools | `scripts/manage_users.py`, `scripts/db.py` with `operation = "default-users"` when intentionally refreshing default users |
| Tables | `users`, `user_courses`, `contact_messages` |

## Account Types

| Type | Created by | Initial access | Later access |
|---|---|---|---|
| Admin | `scripts/db.py` with `operation = "setup"`, `scripts/manage_users.py`, or direct admin setup | Username/email + password | Username/email + password |
| Self-registered participant | `account/register.php` | Username/email + password | Username/email + password |
| Pre-issued participant | `scripts/manage_users.py` or admin edit page | One-click link, or username + email while no password is set | One-click link, or username/email + password after password is set |

Pre-issued users can set a password from `account/profile.php`. Password setup does not remove `users.login_token`; one-click links remain valid until an admin regenerates or revokes the link, or deactivates the account.

## Login Flow

`index.php` shows the sign-in form. The current rules are:

| Inputs | Allowed for | Handler behavior |
|---|---|---|
| Username or email + password | Admins, self-registered users, pre-issued users with a password | `account/login.php` finds by username/email and verifies `password_hash`. |
| Username + email, no password | Pre-issued users only, only before a password is set | `account/login.php` requires matching username and email, `account_type = 'pre_issued'`, empty `password_hash`, and active account. |
| One-click URL | Users with an active `login_token` | `account/auto_login.php` signs the user in and sends them to `dashboard.php` for participants or `admin/index.php` for admins. |

The login page shows a **Need a one-click access link?** option. Clicking it opens the email form. `account/forgot_password.php` sends a one-click access link only when the submitted email belongs to an active non-admin account, but the browser always receives a generic message so account existence is not exposed.

## Registration Flow

`account/register.php` is a four-step self-registration wizard:

| Step | Purpose |
|---|---|
| 1 | Consent from `app/config/consent.json`; stores consent version and timestamp. Can render either inline text sections or the configured PDF consent form. The participant page shows a simplified one-line heading and does not display the internal consent version. |
| 2 | Required username, required password/confirm, optional email. Email can later be used for sign-in. |
| 3 | Required subject selection with confirmation that the subject area cannot be changed later by the participant. |
| 4 | Course selection within the selected subject, using the same compact checkbox layout used by `account/profile.php`. |

Registration creates a `self_registered` user, inserts selected rows into `user_courses`, logs the user in, and redirects to `dashboard.php`.

### Consent Display Modes

`app/config/consent.json` controls the first registration step with `display_mode`:

| Value | Behavior |
|---|---|
| `pdf` | Shows the configured PDF from `pdf.filename` through `account/consent_pdf.php`, plus the PDF-specific agreement checkbox label. |
| `text` | Shows the existing inline `sections` text and the existing `agreement_label`. |

Current PDF mode uses:

| Setting | Role |
|---|---|
| `title` | Short page heading, currently `Consent Form`. |
| `study_name` | Participant-facing study name, currently `VideoPoints User Study on Video Detailed Summary`. |
| `version` | Internal consent version stored with the user record; it is not shown on the registration page. |
| `pdf.filename` | PDF file under `app/config/`. |
| `pdf.intro` | Short instruction paragraph above the embedded PDF. |
| `pdf.agreement_label` | Checkbox label shown before continuing to account setup. |

The consent PDF can remain under `app/config/`; that directory is not directly browsable because of `.htaccess`, so `account/consent_pdf.php` streams only the configured PDF file. The route validates that the configured file exists and has a `.pdf` extension before sending it as `application/pdf`.

## Profile Flow

`account/profile.php` is the participant-facing account page.

Current behavior:

- Shows username, email status, password status, subject, and course selection.
- Does not show account type, registration date, or last-login details.
- Allows users without an email to add one.
- Allows password setup/change with only `new password` and `confirm password`; current password is not required.
- Keeps any existing `login_token` after password save.
- Shows a short note only when no password is set, explaining that the user can add a password from Profile for password-based sign-in.
- Allows course changes only among courses under the user's existing subject.
- Uses the same compact course checkbox layout as registration. The shared compact course styling lives in `assets/css/main.css`.

Subject changes remain an admin responsibility. This keeps the participant registration rule consistent: the subject area is treated as fixed after registration.

## Admin User Management

`admin/index.php` lists users and links to `admin/edit_user.php` for non-admin accounts.

`admin/edit_user.php` supports:

- Updating a participant's subject and course assignments.
- Generating a persistent one-click access link.
- Revoking an existing one-click link.
- Deactivating or reactivating a participant account. Deactivation blocks both password sign-in and one-click links.

The generated URL uses `absoluteUrl()` and should follow `APP_URL` from `.env` when configured. For local development, for example:

```text
APP_URL=http://localhost:8888/userstudy2/
```

For production under the VideoPoints site, use the deployed subfolder URL, for example:

```text
APP_URL=https://www.videopoints.org/public/sites/userstudy2/
```

`admin/messages.php` shows contact messages grouped by whether they were sent before or after login. Opening the page marks unread messages as read after loading them, so newly opened messages still appear as `New` for that page view. Admins can delete stored messages from this page.

`scripts/manage_users.py` provides development support for pre-issued participant creation, login URL generation, and participant deletion. Admin creation remains a Phase 0 responsibility in `scripts/db.py`.

## Contact and Email

`contact.php` stores messages in `contact_messages`. Logged-in users are attached by `user_id`; anonymous/pre-login messages store the entered name and optional email.

If mail settings are present in `.env`, `app/includes/mailer.php` also emails the configured admin notification address. The same mail helper is used by `account/forgot_password.php` to send one-click access links.

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

Use a Gmail app password, not the normal Gmail account password.

## Database Tables

| Table | Role |
|---|---|
| `users` | Username, optional email, password hash, subject, account type, active/admin flags, one-click token, login timestamps. |
| `user_courses` | Participant-course assignments used by profile and dashboard. |
| `contact_messages` | Messages sent to the admin before or after login. |

The full table definitions live in `app/sql/schema.sql`.

## Phase 1 Scripts and Assets

The current Phase 1 scripts and assets are all referenced:

| File | Used by |
|---|---|
| `assets/js/common.js` | Password show/hide buttons on login and profile. |
| `assets/js/auth.js` | Login/register tab switching, forgot-password form toggle, login input hints. |
| `assets/js/register.js` | Client-side password validation for registration step 2. Server validation remains authoritative. |
| `assets/js/admin-edit-user.js` | Copy button for admin-generated one-click access links. |
| `scripts/manage_users.py` | Optional CLI helper for pre-issued participant accounts and login URLs. |
| `scripts/db.py` | Phase 0 database setup/default users; still relevant because default admin/test accounts support Phase 1 access. |

No old nested `assets/js/pages/`, `assets/js/common/password-toggle.js`, or `assets/css/pages/` files are part of the current layout.

## Phase Boundaries

Phase 1 owns user identity and participant access. It touches course selection because users need assigned courses, but the resource/video content for those courses belongs to Phase 2. It does not own the survey viewer or response storage; those belong to Phase 3.
