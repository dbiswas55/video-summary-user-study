-- Rename subject codes to standard disciplinary abbreviations.
-- Run once against existing databases that still have the old codes.

UPDATE subjects SET code = 'BIOL' WHERE code = 'BIO';
UPDATE subjects SET code = 'COSC' WHERE code = 'CS';
