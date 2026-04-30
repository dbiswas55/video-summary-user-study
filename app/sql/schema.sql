-- =====================================================================
-- User Study 2 - Video Detailed Summary Comparative Evaluation
-- Database: userstudy_vds
-- =====================================================================

CREATE DATABASE IF NOT EXISTS userstudy_vds
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE userstudy_vds;

-- ---------------------------------------------------------------------
-- SUBJECTS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(10) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- COURSES
-- id            = real course integer ID (e.g. 527, 528, 531, 533)
-- instructor_id = real instructor integer ID (e.g. 1, 116, 2207, 12394)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS courses (
    id INT NOT NULL PRIMARY KEY,
    subject_id INT NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(150) NOT NULL,
    instructor VARCHAR(100),
    instructor_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    INDEX idx_subject (subject_id),
    INDEX idx_instructor (instructor_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- VIDEOS
-- id              = internal auto-increment PK (referenced by segments)
-- video_id        = real video integer ID (e.g. 9230, 9244)
-- instructor_id   = denormalized from courses for direct path building
-- video_filename  = mp4 filename inside the video resource folder
--
-- Resource paths:
--   video/transcript: {resources_root}/i{instructor_id}/v{video_id}/
--   chapters:         {resources_root}/i{instructor_id}/v{video_id}/chapter{N}/
--   video file:       {video_root_url}/i{instructor_id}/v{video_id}/{video_filename}
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS videos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    instructor_id INT NOT NULL,
    title VARCHAR(200) NOT NULL DEFAULT '',
    video_id INT NOT NULL,
    video_filename VARCHAR(200) NOT NULL DEFAULT '',
    display_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_video_id (video_id),
    INDEX idx_course (course_id),
    INDEX idx_instructor (instructor_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SEGMENTS
-- One segment per chapter folder under a video resource folder.
-- start_s / end_s are seconds within the full video/transcript timeline.
-- slide_range_start/end track position within the original lecture deck.
-- summary_a_file / summary_b_file are chapter-relative paths such as
-- chapter2/transcript_summary.txt or chapter2/multimodal_summary.txt.
-- version_assignment hides the generation method from participants:
--   normal  = A transcript-only, B multimodal
--   swapped = A multimodal, B transcript-only
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS segments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    video_id INT NOT NULL,
    chapter_num INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    start_s DECIMAL(10,4) NOT NULL DEFAULT 0.0,
    end_s DECIMAL(10,4) NOT NULL DEFAULT 0.0,
    duration_s DECIMAL(10,4) NOT NULL DEFAULT 0.0,
    slide_range_start INT DEFAULT 0,
    slide_range_end INT DEFAULT 0,
    summary_a_file VARCHAR(100) NOT NULL DEFAULT 'transcript_summary.txt',
    summary_b_file VARCHAR(100) NOT NULL DEFAULT 'multimodal_summary.txt',
    version_assignment ENUM('normal', 'swapped') NOT NULL DEFAULT 'normal',
    display_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE,
    INDEX idx_video (video_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- USERS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL DEFAULT '',
    subject_id INT,
    account_type ENUM('pre_issued', 'self_registered') DEFAULT 'self_registered',
    consent_given BOOLEAN DEFAULT FALSE,
    consent_version VARCHAR(20),
    consent_timestamp DATETIME,
    is_admin BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    login_token VARCHAR(64) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
    UNIQUE KEY idx_email (email),
    UNIQUE KEY idx_login_token (login_token),
    INDEX idx_username (username),
    INDEX idx_active (is_active)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- USER_COURSES (many-to-many)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    course_id INT NOT NULL,
    selected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_course (user_id, course_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- USER SEGMENT PROGRESS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_segment_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    segment_id INT NOT NULL,
    status ENUM('not_started', 'in_progress', 'completed') DEFAULT 'not_started',
    started_at DATETIME,
    completed_at DATETIME,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (segment_id) REFERENCES segments(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_segment (user_id, segment_id),
    INDEX idx_user (user_id),
    INDEX idx_segment (segment_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- RESPONSES: FAMILIARITY (Q1)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS responses_familiarity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    segment_id INT NOT NULL,
    answer ENUM('not_familiar', 'somewhat', 'familiar', 'very_familiar') NOT NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (segment_id) REFERENCES segments(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_familiarity (user_id, segment_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- RESPONSES: RATINGS (per dimension, per version)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS responses_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    segment_id INT NOT NULL,
    dimension ENUM('faithfulness', 'completeness', 'coherence', 'usefulness') NOT NULL,
    version ENUM('A', 'B') NOT NULL,
    rating TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 10),
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (segment_id) REFERENCES segments(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_rating (user_id, segment_id, dimension, version),
    INDEX idx_dimension (dimension)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- RESPONSES: COMMENTS (optional free text per dimension)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS responses_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    segment_id INT NOT NULL,
    dimension ENUM('faithfulness', 'completeness', 'coherence', 'usefulness') NOT NULL,
    comment_text TEXT,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (segment_id) REFERENCES segments(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_comment (user_id, segment_id, dimension)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- RESPONSES: VISUAL OBJECT SELECTION
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS responses_visual_objects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    segment_id INT NOT NULL,
    selection_quality_rating TINYINT DEFAULT NULL CHECK (selection_quality_rating BETWEEN 1 AND 10),
    include_important_labels TEXT DEFAULT NULL,
    include_important_none TINYINT(1) NOT NULL DEFAULT 0,
    exclude_unimportant_labels TEXT DEFAULT NULL,
    exclude_unimportant_none TINYINT(1) NOT NULL DEFAULT 0,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (segment_id) REFERENCES segments(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_visual_objects (user_id, segment_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CONTACT MESSAGES
-- Messages may be sent before login (user_id NULL) or after login.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    name VARCHAR(100) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_read BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_read (is_read),
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB;


-- =====================================================================
-- SEED DATA
-- =====================================================================

INSERT IGNORE INTO subjects (id, code, name) VALUES
    (1, 'BIOL', 'Biology'),
    (2, 'COSC', 'Computer Science');

-- ---------------------------------------------------------------------
-- Courses
-- ---------------------------------------------------------------------
INSERT IGNORE INTO courses (id, subject_id, code, name, instructor, instructor_id) VALUES
    (527, 1, 'BIOL2321', 'Microbiology for Science Majors', 'Richard Knapp',    1),
    (528, 1, 'BIOL2301', 'Human Anatomy & Physiology I',    'Chad Wayne',       116),
    (532, 1, 'BIOL4315', 'Neuroscience',                    'Jokubas Ziburkus', 2),
    (531, 2, 'COSC1336', 'Computer Science & Programming',  'Jaspal Subhlok',   2207),
    (533, 2, 'COSC4393', 'Digital Image Processing',        'Pranav Mantini',   12394);

-- ---------------------------------------------------------------------
-- No video, segment, response, progress, or message rows are seeded here.
-- Add videos/chapters from resource folders by setting operation = "add"
-- in scripts/sync_videos.py, then running:
--   python scripts/sync_videos.py
-- ---------------------------------------------------------------------

-- Default admin/test users are managed by:
--   set operation = "setup" in scripts/db.py, then run python scripts/db.py
-- or:
--   set operation = "default-users" in scripts/db.py, then run python scripts/db.py
