<?php
/**
 * Questionnaire Submission Handler
 * Accepts POST from survey/viewer.php, writes responses to DB, redirects back.
 */

require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/functions.php';
require_once __DIR__ . '/../app/includes/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$pdo        = getDb();
$user_id    = (int)$_SESSION['user_id'];
$segment_id = (int)($_POST['segment_id'] ?? 0);

function ensureVisualResponsesTable($pdo) {
    $pdo->exec('
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
        ) ENGINE=InnoDB
    ');
}

function loadVisualLabels($instructor_id, $video_id, $chapter_num) {
    $path = getResourcePath($instructor_id, $video_id, 'chapter' . $chapter_num . '/metadata.json');
    if (!file_exists($path)) {
        return ['selected' => [], 'unselected' => []];
    }
    $meta = json_decode(file_get_contents($path), true);
    $visual = is_array($meta['visual_objects'] ?? null) ? $meta['visual_objects'] : [];
    return [
        'selected' => array_map(fn($i) => 'S' . ($i + 1), array_keys($visual['selected'] ?? [])),
        'unselected' => array_map(fn($i) => 'U' . ($i + 1), array_keys($visual['unselected'] ?? [])),
    ];
}

function validLabelSubset($raw, $valid_labels) {
    if (!is_array($raw)) {
        return [];
    }
    $valid = array_flip($valid_labels);
    $out = [];
    foreach ($raw as $label) {
        $label = (string)$label;
        if (isset($valid[$label]) && !in_array($label, $out, true)) {
            $out[] = $label;
        }
    }
    return $out;
}

// Validate segment exists and get the real video_id/chapter for redirect
$seg = $pdo->prepare('
    SELECT s.id, s.chapter_num, v.video_id, v.instructor_id
    FROM segments s
    JOIN videos v ON v.id = s.video_id
    WHERE s.id = ?
');
$seg->execute([$segment_id]);
$seg_row = $seg->fetch();
if (!$seg_row) {
    setFlash('error', 'Invalid segment.');
    header('Location: ' . baseUrl());
    exit;
}
$real_video_id = (int)$seg_row['video_id'];
$chapter_num   = (int)$seg_row['chapter_num'];
$instructor_id = (int)$seg_row['instructor_id'];
$viewer_url    = baseUrl('survey/viewer.php?vid=' . $real_video_id . '&chapter=' . $chapter_num);

$action     = ($_POST['action'] ?? 'submit') === 'save_later' ? 'save_later' : 'submit';
$study      = loadJsonConfig('study.json');
$dimensions = [];
foreach (($study['dimensions'] ?? []) as $dim) {
    $id = (string)($dim['id'] ?? '');
    if (preg_match('/^[a-z_]+$/', $id)) {
        $dimensions[] = $id;
    }
}
$valid_fam = [];
foreach (($study['familiarity_options'] ?? []) as $option) {
    $id = (string)($option['id'] ?? '');
    if (preg_match('/^[a-z_]+$/', $id)) {
        $valid_fam[] = $id;
    }
}
$rating_scale = $study['rating_scale'] ?? [];
$rating_min = (int)($rating_scale['min'] ?? 1);
$rating_max = (int)($rating_scale['max'] ?? 10);
if ($rating_min < 1 || $rating_max > 10 || $rating_min >= $rating_max) {
    $rating_min = 1;
    $rating_max = 10;
}
$errors     = [];
$visual_labels = loadVisualLabels($instructor_id, $real_video_id, $chapter_num);

if (!$dimensions || !$valid_fam) {
    setFlash('error', 'Survey questions are not configured.');
    header('Location: ' . $viewer_url);
    exit;
}

// ── Parse familiarity ─────────────────────────────────────────────────────────
$familiarity = $_POST['familiarity'] ?? '';
$has_familiarity = in_array($familiarity, $valid_fam, true);
if ($action === 'submit' && !$has_familiarity) {
    $errors[] = 'Familiarity answer is required.';
}

// ── Parse ratings ─────────────────────────────────────────────────────────────
$ratings     = [];
$raw_ratings = $_POST['rating'] ?? [];
$rating_count = 0;
$rating_question_count = 0;

foreach ($dimensions as $dim) {
    foreach (['A', 'B'] as $ver) {
        $val = (int)($raw_ratings[$dim][$ver] ?? 0);
        if ($val >= $rating_min && $val <= $rating_max) {
            $ratings[$dim][$ver] = $val;
            $rating_count++;
        } elseif ($action === 'submit') {
            $errors[] = "Rating for {$dim} Version {$ver} is required ({$rating_min}–{$rating_max}).";
        }
    }
    if (isset($ratings[$dim]['A'], $ratings[$dim]['B'])) {
        $rating_question_count++;
    }
}

if ($errors) {
    setFlash('error', implode(' ', $errors));
    header('Location: ' . $viewer_url);
    exit;
}

// ── Parse visual object responses ─────────────────────────────────────────────
$selection_quality_raw = $_POST['visual_selection_quality'] ?? '';
$selection_quality_rating = $selection_quality_raw === '' ? null : (int)$selection_quality_raw;
$has_selection_quality_rating = $selection_quality_rating !== null && $selection_quality_rating >= $rating_min && $selection_quality_rating <= $rating_max;
if ($action === 'submit' && !$has_selection_quality_rating) {
    $errors[] = "Visual object rating is required ({$rating_min}–{$rating_max}).";
}
$include_important = validLabelSubset($_POST['visual_include_important'] ?? [], $visual_labels['unselected']);
$exclude_unimportant = validLabelSubset($_POST['visual_exclude_unimportant'] ?? [], $visual_labels['selected']);
$include_important_none = ($_POST['visual_include_important_none'] ?? '0') === '1';
$exclude_unimportant_none = ($_POST['visual_exclude_unimportant_none'] ?? '0') === '1';
if ($include_important_none) {
    $include_important = [];
}
if ($exclude_unimportant_none) {
    $exclude_unimportant = [];
}
$has_include_important_answer = $include_important_none || count($include_important) > 0;
$has_exclude_unimportant_answer = $exclude_unimportant_none || count($exclude_unimportant) > 0;
if ($action === 'submit' && !$has_include_important_answer) {
    $errors[] = 'Visual object Q2 (Include Important) is required. Select important objects or choose None.';
}
if ($action === 'submit' && !$has_exclude_unimportant_answer) {
    $errors[] = 'Visual object Q3 (Exclude Unimportant) is required. Unselect unimportant objects or choose None.';
}

if ($errors) {
    setFlash('error', implode(' ', $errors));
    header('Location: ' . $viewer_url);
    exit;
}

// Determine target status from how many configured questions were answered
$required_count = 1 + count($dimensions) + 3;
$answered_count = ($has_familiarity ? 1 : 0) + $rating_question_count + ($has_selection_quality_rating ? 1 : 0) + ($has_include_important_answer ? 1 : 0) + ($has_exclude_unimportant_answer ? 1 : 0);
$has_any_text_response = $has_familiarity || $rating_count > 0;
$has_any_visual_response = $has_selection_quality_rating || $has_include_important_answer || $has_exclude_unimportant_answer;
if ($answered_count === 0 && !$has_any_text_response && !$has_any_visual_response) {
    $new_status = 'not_started';
} elseif ($answered_count < $required_count) {
    $new_status = 'in_progress';
} else {
    $new_status = 'completed';
}

// ── Write to DB ───────────────────────────────────────────────────────────────
try {
    ensureVisualResponsesTable($pdo);
    $pdo->beginTransaction();

    // Familiarity (only if provided)
    if ($has_familiarity) {
        $stmt = $pdo->prepare('
            INSERT INTO responses_familiarity (user_id, segment_id, answer)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE answer = VALUES(answer), submitted_at = NOW()
        ');
        $stmt->execute([$user_id, $segment_id, $familiarity]);
    }

    // Ratings (only those provided)
    if (!empty($ratings)) {
        $stmt = $pdo->prepare('
            INSERT INTO responses_ratings (user_id, segment_id, dimension, version, rating)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE rating = VALUES(rating), submitted_at = NOW()
        ');
        foreach ($ratings as $dim => $vers) {
            foreach ($vers as $ver => $val) {
                $stmt->execute([$user_id, $segment_id, $dim, $ver, $val]);
            }
        }
    }

    // Optional comments
    $raw_comments = $_POST['comment'] ?? [];
    if (is_array($raw_comments)) {
        $stmt_comment = $pdo->prepare('
            INSERT INTO responses_comments (user_id, segment_id, dimension, comment_text)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE comment_text = VALUES(comment_text), submitted_at = NOW()
        ');
        foreach ($dimensions as $dim) {
            $text = trim($raw_comments[$dim] ?? '');
            if ($text !== '') {
                $stmt_comment->execute([$user_id, $segment_id, $dim, $text]);
            } else {
                $pdo->prepare('
                    DELETE FROM responses_comments
                    WHERE user_id = ? AND segment_id = ? AND dimension = ?
                ')->execute([$user_id, $segment_id, $dim]);
            }
        }
    }

    // Visual object responses
    if ($has_any_visual_response) {
        $stmt_visual = $pdo->prepare('
            INSERT INTO responses_visual_objects
                (user_id, segment_id, selection_quality_rating, include_important_labels, include_important_none, exclude_unimportant_labels, exclude_unimportant_none)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                selection_quality_rating = VALUES(selection_quality_rating),
                include_important_labels = VALUES(include_important_labels),
                include_important_none = VALUES(include_important_none),
                exclude_unimportant_labels = VALUES(exclude_unimportant_labels),
                exclude_unimportant_none = VALUES(exclude_unimportant_none),
                submitted_at = NOW()
        ');
        $stmt_visual->execute([
            $user_id,
            $segment_id,
            $has_selection_quality_rating ? $selection_quality_rating : null,
            json_encode($include_important, JSON_UNESCAPED_UNICODE),
            $include_important_none ? 1 : 0,
            json_encode($exclude_unimportant, JSON_UNESCAPED_UNICODE),
            $exclude_unimportant_none ? 1 : 0,
        ]);
    }

    // Update progress — never downgrade a completed segment
    if ($new_status === 'completed') {
        $pdo->prepare('
            INSERT INTO user_segment_progress (user_id, segment_id, status, started_at, completed_at)
            VALUES (?, ?, \'completed\', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                status = \'completed\',
                started_at = COALESCE(started_at, NOW()),
                completed_at = NOW()
        ')->execute([$user_id, $segment_id]);
    } else {
        // not_started or in_progress: only write if row doesn't exist or is not completed
        $pdo->prepare('
            INSERT INTO user_segment_progress (user_id, segment_id, status, started_at)
            VALUES (?, ?, ?, IF(? = \'in_progress\', NOW(), NULL))
            ON DUPLICATE KEY UPDATE
                status = IF(status = \'completed\', \'completed\', VALUES(status)),
                started_at = IF(
                    status = \'completed\',
                    started_at,
                    IF(VALUES(status) = \'in_progress\', COALESCE(started_at, NOW()), started_at)
                )
        ')->execute([$user_id, $segment_id, $new_status, $new_status]);
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    setFlash('error', 'Failed to save responses. Please try again.');
    header('Location: ' . $viewer_url);
    exit;
}

if ($action === 'save_later') {
    setFlash('success', 'Progress saved. You can return to finish this segment anytime.');
    header('Location: ' . baseUrl('dashboard.php'));
} else {
    setFlash('success', 'Your responses have been saved. Thank you!');
    header('Location: ' . baseUrl('dashboard.php'));
}
exit;
