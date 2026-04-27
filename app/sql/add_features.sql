-- Migration: Add email to users + contact_messages table
-- Run once. The ALTER TABLE will fail silently if email already exists.

USE userstudy_vds;

-- Add optional email to users (for password recovery hint)
ALTER TABLE users
    ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER username;

-- Contact messages: sent before OR after login
CREATE TABLE IF NOT EXISTS contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,          -- NULL = sent before/without login
    name VARCHAR(100) DEFAULT NULL,    -- filled in when not logged in
    email VARCHAR(255) DEFAULT NULL,   -- optional reply-to address
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_read BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_read (is_read)
) ENGINE=InnoDB;
