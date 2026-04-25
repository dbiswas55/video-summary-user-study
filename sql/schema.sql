-- =====================================================================
-- User Study 2 - Video Detailed Summary Comparative Evaluation
-- Database: userstudy_vds
--
-- This script:
--   1. Creates the database (if missing)
--   2. Creates all tables
--   3. Inserts seed data
--
-- Passwords for seed users are set separately via:
--   python scripts/seed_passwords.py
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
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(150) NOT NULL,
    instructor VARCHAR(100),
    instructor_id VARCHAR(50) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    INDEX idx_subject (subject_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- VIDEOS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS videos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    video_id VARCHAR(50) NOT NULL,
    video_filename VARCHAR(200),
    duration_seconds INT,
    display_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_course (course_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SEGMENTS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS segments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    video_id INT NOT NULL,
    chapter_num INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    start_time VARCHAR(10),
    end_time VARCHAR(10),
    duration_seconds INT,
    fragment_file VARCHAR(255),
    summary_a_file VARCHAR(255) DEFAULT 'summary_a.txt',
    summary_b_file VARCHAR(255) DEFAULT 'summary_b.txt',
    version_a_source VARCHAR(50) DEFAULT 'transcript_only',
    version_b_source VARCHAR(50) DEFAULT 'multimodal',
    slide_range_start INT,
    slide_range_end INT,
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
    password_hash VARCHAR(255) NOT NULL DEFAULT '',
    subject_id INT,
    account_type ENUM('pre_issued', 'self_registered') DEFAULT 'self_registered',
    consent_given BOOLEAN DEFAULT FALSE,
    consent_version VARCHAR(20),
    consent_timestamp DATETIME,
    is_admin BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
    INDEX idx_username (username)
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
-- RESPONSES: RATINGS (Q2-Q5)
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
-- RESPONSES: COMMENTS (optional per dimension)
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


-- =====================================================================
-- SEED DATA
-- =====================================================================

-- Subjects
INSERT IGNORE INTO subjects (id, code, name) VALUES
    (1, 'BIO', 'Biology'),
    (2, 'CS',  'Computer Science');

-- Courses
INSERT IGNORE INTO courses (id, subject_id, code, name, instructor, instructor_id) VALUES
    (1, 1, 'BIOL2301', 'Human Anatomy & Physiology I',  'Chad Wayne',     'chad_wayne'),
    (2, 1, 'BIOL2321', 'Microbiology',                  'Richard Knapp',  'richard_knapp'),
    (3, 2, 'COSC1336', 'Computer Science & Programming','Instructor TBD', 'cosc_instructor_1'),
    (4, 2, 'COSC4393', 'Digital Image Processing',      'Instructor TBD', 'cosc_instructor_2');

-- Videos (one sample per course)
INSERT IGNORE INTO videos (id, course_id, title, video_id, video_filename, duration_seconds, display_order) VALUES
    (1, 1, 'Animal Digestion - Lecture 1', 'bio1_1', 'lecture.mp4', 1040, 1),
    (2, 2, 'Microbiology - Lecture 1',     'bio2_1', 'lecture.mp4',  900, 1),
    (3, 3, 'Multimodal Machine Learning',  'ml1_1',  'lecture.mp4', 1380, 1),
    (4, 4, 'Neural Networks Optimization', 'ml1_2',  'lecture.mp4',  600, 1);

-- Segments for video 1 (BIO bio1_1)
INSERT IGNORE INTO segments (id, video_id, chapter_num, title, start_time, end_time, duration_seconds, fragment_file, slide_range_start, slide_range_end, display_order) VALUES
    (1, 1, 1, 'Introduction to Animal Digestion and Nutrient Needs',     '00:00', '05:13', 313, 'bio1_1_chapter_1.php', 1,  5,  1),
    (2, 1, 2, 'Essential Nutrients: Fatty Acids, Vitamins, and Minerals','05:13', '07:51', 158, 'bio1_1_chapter_2.php', 6,  9,  2),
    (3, 1, 3, 'Malnourishment and Food Processing Stages',               '07:51', '11:55', 244, 'bio1_1_chapter_3.php', 10, 14, 3),
    (4, 1, 4, 'Ingestion, Digestion, Absorption, and Elimination',       '11:55', '17:20', 325, 'bio1_1_chapter_4.php', 15, 21, 4);

-- Segments for video 3 (CS ml1_1)
INSERT IGNORE INTO segments (id, video_id, chapter_num, title, start_time, end_time, duration_seconds, fragment_file, slide_range_start, slide_range_end, display_order) VALUES
    (5, 3, 1, 'Introduction to Multimodal Machine Learning', '00:00', '02:39', 159, 'ml1_1_chapter_1.php', 1,  3, 1),
    (6, 3, 2, 'Defining Multimodal and its Components',      '02:39', '07:58', 319, 'ml1_1_chapter_2.php', 4,  9, 2),
    (7, 3, 3, 'Historical Perspective of Multimodal Research','07:58', '23:00', 902, 'ml1_1_chapter_3.php', 10, 16, 3);

-- Seed users (passwords are set by scripts/seed_passwords.py)
INSERT IGNORE INTO users (id, username, password_hash, subject_id, account_type, consent_given, consent_version, consent_timestamp, is_admin) VALUES
    (1, 'testuser', '', 1, 'pre_issued', TRUE, 'v1.0', NOW(), FALSE),
    (2, 'admin',    '', NULL, 'pre_issued', TRUE, NULL, NULL, TRUE);
