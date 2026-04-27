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

// Validate segment exists and get the real video_id for redirect
$seg = $pdo->prepare('
    SELECT s.id, v.video_id
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
$viewer_url    = baseUrl('survey/viewer.php?vid=' . $real_video_id);

$action     = ($_POST['action'] ?? 'submit') === 'save_later' ? 'save_later' : 'submit';
$dimensions = ['faithfulness', 'completeness', 'coherence', 'usefulness'];
$valid_fam  = ['not_familiar', 'somewhat', 'familiar', 'very_familiar'];
$errors     = [];

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

foreach ($dimensions as $dim) {
    foreach (['A', 'B'] as $ver) {
        $val = (int)($raw_ratings[$dim][$ver] ?? 0);
        if ($val >= 1 && $val <= 10) {
            $ratings[$dim][$ver] = $val;
            $rating_count++;
        } elseif ($action === 'submit') {
            $errors[] = "Rating for {$dim} Version {$ver} is required (1–10).";
        }
    }
}

if ($errors) {
    setFlash('error', implode(' ', $errors));
    header('Location: ' . $viewer_url);
    exit;
}

// Determine target status from how many of the 9 questions were answered
$answered_count = ($has_familiarity ? 1 : 0) + $rating_count;
if ($answered_count === 0) {
    $new_status = 'not_started';
} elseif ($answered_count < 9) {
    $new_status = 'in_progress';
} else {
    $new_status = 'completed';
}

// ── Write to DB ───────────────────────────────────────────────────────────────
try {
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
            }
        }
    }

    // Update progress — never downgrade a completed segment
    if ($new_status === 'completed') {
        $pdo->prepare('
            INSERT INTO user_segment_progress (user_id, segment_id, status, completed_at)
            VALUES (?, ?, "completed", NOW())
            ON DUPLICATE KEY UPDATE status = "completed", completed_at = NOW()
        ')->execute([$user_id, $segment_id]);
    } else {
        // not_started or in_progress: only write if row doesn't exist or is not completed
        $pdo->prepare('
            INSERT INTO user_segment_progress (user_id, segment_id, status)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE status = IF(status = "completed", "completed", VALUES(status))
        ')->execute([$user_id, $segment_id, $new_status]);
    }

    $pdo->commit();

} catch (Throwable $e) {
    $pdo->rollBack();
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
