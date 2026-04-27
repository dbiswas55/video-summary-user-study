-- Migration: Add version_assignment column to segments
-- Controls which generated summary is presented as "Version A" vs "Version B".
-- 'normal'  → Version A = transcript_summary.txt, Version B = multimodal_summary.txt
-- 'swapped' → Version A = multimodal_summary.txt, Version B = transcript_summary.txt
--
-- This also updates summary_a_file / summary_b_file to reflect the assignment,
-- since the viewer reads those columns directly.

USE userstudy_vds;

ALTER TABLE segments
    ADD COLUMN version_assignment ENUM('normal', 'swapped') NOT NULL DEFAULT 'normal'
    AFTER summary_b_file;

-- Apply swapped assignment to half the seeded segments (ids 2, 4, 6).
-- Swap their summary_a_file / summary_b_file values accordingly.
UPDATE segments
SET version_assignment = 'swapped',
    summary_a_file     = 'multimodal_summary.txt',
    summary_b_file     = 'transcript_summary.txt'
WHERE id IN (2, 4, 6);
