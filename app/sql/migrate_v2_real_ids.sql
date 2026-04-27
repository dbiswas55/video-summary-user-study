-- =====================================================================
-- Migration: v2 — Real IDs + Clean segments table + 6 active videos
-- =====================================================================
-- Changes from v1:
--   - courses.id        = real course integer (527, 528, 531, 533)
--   - courses.instructor_id = INT (was VARCHAR)
--   - videos.video_id   = real integer; instructor_id INT; video_filename added
--   - segments          = redesigned: float start_s/end_s, no fragment_file,
--                         correct summary filenames, 1 segment per video folder
--   - Only 6 videos seeded (those with actual resource folders)
--
-- WARNING: Drops all response/progress data and re-seeds courses/videos/segments.
--          User accounts are preserved.
-- =====================================================================

USE userstudy_vds;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS responses_comments;
DROP TABLE IF EXISTS responses_ratings;
DROP TABLE IF EXISTS responses_familiarity;
DROP TABLE IF EXISTS user_segment_progress;
DROP TABLE IF EXISTS segments;
DROP TABLE IF EXISTS user_courses;
DROP TABLE IF EXISTS videos;
DROP TABLE IF EXISTS courses;

-- ---------------------------------------------------------------------
CREATE TABLE courses (
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

CREATE TABLE videos (
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

CREATE TABLE segments (
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
    display_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE,
    INDEX idx_video (video_id)
) ENGINE=InnoDB;

CREATE TABLE user_courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    course_id INT NOT NULL,
    selected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_course (user_id, course_id)
) ENGINE=InnoDB;

CREATE TABLE user_segment_progress (
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

CREATE TABLE responses_familiarity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    segment_id INT NOT NULL,
    answer ENUM('not_familiar', 'somewhat', 'familiar', 'very_familiar') NOT NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (segment_id) REFERENCES segments(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_familiarity (user_id, segment_id)
) ENGINE=InnoDB;

CREATE TABLE responses_ratings (
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

CREATE TABLE responses_comments (
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

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- RE-SEED
-- =====================================================================

INSERT INTO courses (id, subject_id, code, name, instructor, instructor_id) VALUES
    (527, 1, 'BIOL2301', 'Human Anatomy & Physiology I',   NULL, 1),
    (528, 1, 'BIOL2321', 'Microbiology',                   NULL, 116),
    (531, 2, 'COSC1336', 'Computer Science & Programming', NULL, 2207),
    (533, 2, 'COSC4393', 'Digital Image Processing',       NULL, 12394);

INSERT INTO videos (id, course_id, instructor_id, video_id, video_filename, display_order) VALUES
    (1, 528,   116,  9230, 'aolwNMIJBQU.mp4', 1),
    (2, 528,   116,  9232, 'aolwNMIJBQU.mp4', 2),
    (3, 528,   116,  9236, 'aolwNMIJBQU.mp4', 3),
    (4, 531,  2207,  9244, 'E_3gxQWaCoQ.mp4', 1),
    (5, 533, 12394,  9264, 'VIq5r7mCAyw.mp4', 1),
    (6, 533, 12394,  9265, 'VIq5r7mCAyw.mp4', 2);

INSERT INTO segments (id, video_id, chapter_num, title, start_s, end_s, duration_s, slide_range_start, slide_range_end, display_order) VALUES
    (1, 1, 1, 'Introduction to Animal Digestion and Nutrient Needs',                     0.0,    313.0,    313.0,  1,  5, 1),
    (2, 2, 2, 'Essential Nutrients: Fatty Acids, Vitamins, and Minerals',                0.0,    158.0,    158.0,  6,  9, 2),
    (3, 3, 3, 'Malnourishment, Undernourishment, and the Four Stages of Food Processing',0.0,    244.5,    244.5, 10, 14, 3),
    (4, 4, 1, 'Introduction to Neural Networks and Optimization',                        0.0, 227.6459, 227.6459,  1,  5, 1),
    (5, 5, 1, 'Introduction to Multimodal Machine Learning',                             0.0, 159.5531, 159.5531,  1,  3, 1),
    (6, 6, 3, 'Historical Perspective of Multimodal Research',                           0.0,    901.5,    901.5, 10, 16, 3);
