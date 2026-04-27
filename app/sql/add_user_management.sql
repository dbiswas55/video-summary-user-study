-- Migration: User management features
-- ────────────────────────────────────────────────────────────────────
-- Adds:
--   • login_token        — random URL-safe string for one-click admin-created
--                          user login (account/auto_login.php?token=…)
--   • UNIQUE on email    — enables sign-in by email; multiple NULLs OK
--   • UNIQUE on token    — required for fast token lookup
--
-- Run once. ALTER TABLE will fail if applied twice; that is expected.

USE userstudy_vds;

ALTER TABLE users
    ADD COLUMN login_token VARCHAR(64) DEFAULT NULL AFTER is_active;

CREATE UNIQUE INDEX idx_login_token ON users(login_token);
CREATE UNIQUE INDEX idx_email       ON users(email);
