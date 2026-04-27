# Phase 1 - User System

This phase covers identity, access, profile management, admin user operations, participant contact messages, and password-reset access links.

## Owned Files

| Area | Files |
|---|---|
| Public entry | `index.php`, `account/login.php`, `account/logout.php`, `account/register.php`, `account/forgot_password.php`, `account/auto_login.php` |
| Participant account | `account/profile.php`, `dashboard.php` user summary/course selection touchpoints |
| Admin account work | `admin/index.php`, `admin/edit_user.php`, `admin/messages.php` |
| Shared helpers | `app/includes/auth.php`, `app/includes/functions.php`, `app/includes/header.php`, `app/includes/footer.php`, `app/includes/mailer.php` |
| CLI tools | `scripts/manage_users.py`, `scripts/db.py` with `operation = "default-users"` |
| Tables | `users`, `user_courses`, `contact_messages` |

## Account Types

| Type | Created by | Initial access | Later access |
|---|---|---|---|
| Admin | `scripts/db.py` with `operation = "setup"`, `scripts/manage_users.py`, or direct admin setup | Username/email + password | Username/email + password |
| Self-registered participant | `account/register.php` | Username/email + password | Username/email + password |
| Pre-issued participant | `scripts/manage_users.py` or admin edit page | One-click link, or username + email while no password is set | Username/email + password after password is set |

Pre-issued users can set a password from `account/profile.php`. When they do, `account/profile.php` clears `users.login_token`, so temporary links and passwordless username+email access stop working.

## Login Flow

`index.php` shows the sign-in form. The current rules are:

| Inputs | Allowed for | Handler behavior |
|---|---|---|
| Username or email + password | Admins, self-registered users, pre-issued users with a password | `account/login.php` finds by username/email and verifies `password_hash`. |
| Username + email, no password | Pre-issued users only, only before a password is set | `account/login.php` requires matching username and email, `account_type = 'pre_issued'`, empty `password_hash`, and active account. |
| One-click URL | Users with an active `login_token` | `account/auto_login.php` signs the user in and sends them to `account/profile.php` if the link is a reset/temporary access link. |

The login page initially shows only a **Forgot password?** link. Clicking it opens the email form. `account/forgot_password.php` sends a one-click access link only when the submitted email belongs to an active non-admin account, but the browser always receives a generic message so account existence is not exposed.

## Registration Flow

`account/register.php` is a four-step self-registration wizard:

| Step | Purpose |
|---|---|
| 1 | Consent text from `app/config/consent.json`; stores consent version and timestamp. |
| 2 | Required username, required password/confirm, optional email. Email can later be used for sign-in. |
| 3 | Required subject selection with confirmation that the subject area cannot be changed later by the participant. |
| 4 | Course selection within the selected subject, using the same compact checkbox layout used by `account/profile.php`. |

Registration creates a `self_registered` user, inserts selected rows into `user_courses`, logs the user in, and redirects to `dashboard.php`.

## Profile Flow

`account/profile.php` is the participant-facing account page.

Current behavior:

- Shows username, email status, password status, subject, and course selection.
- Does not show account type, registration date, or last-login details.
- Allows users without an email to add one.
- Allows password setup/change with only `new password` and `confirm password`; current password is not required.
- Clears `login_token` after password save.
- Allows course changes only among courses under the user's existing subject.

Subject changes remain an admin responsibility. This keeps the participant registration rule consistent: the subject area is treated as fixed after registration.

## Admin User Management

`admin/index.php` lists users and links to `admin/edit_user.php` for non-admin accounts.

`admin/edit_user.php` supports:

- Updating a participant's subject and course assignments.
- Generating a temporary one-click access/reset link.
- Revoking an existing one-click link.

The generated URL uses `absoluteUrl()` and should follow `APP_URL` from `.env` when configured. For local development, for example:

```text
APP_URL=http://localhost:8888/userstudy2/
```

For production under the VideoPoints site, use the deployed subfolder URL, for example:

```text
APP_URL=https://videopoints.org/userstudy2/
```

`scripts/manage_users.py` provides equivalent CLI support for admin/pre-issued account creation and token regeneration.

## Contact and Email

`contact.php` stores messages in `contact_messages`. Logged-in users are attached by `user_id`; anonymous/pre-login messages store the entered name and optional email.

If mail settings are present in `.env`, `app/includes/mailer.php` also emails the configured admin notification address. The same mail helper is used by `account/forgot_password.php` to send reset/access links.

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

## Phase Boundaries

Phase 1 owns user identity and participant access. It touches course selection because users need assigned courses, but the resource/video content for those courses belongs to Phase 2. It does not own the survey viewer or response storage; those belong to Phase 3.
