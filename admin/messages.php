<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

requireAdmin();

$pageTitle = 'Messages — Admin';
$pageStyles = [
    'assets/css/admin.css',
    'assets/css/messages.css',
];
$pdo = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $messageId = (int)($_POST['message_id'] ?? 0);

    if ($action === 'delete' && $messageId > 0) {
        $stmt = $pdo->prepare('DELETE FROM contact_messages WHERE id = ?');
        $stmt->execute([$messageId]);
        setFlash('success', 'Message deleted.');
    }

    header('Location: ' . baseUrl('admin/messages.php'));
    exit;
}

$messages = $pdo->query('
    SELECT m.id, m.user_id, m.name, m.email, m.subject, m.message, m.sent_at, m.is_read,
           u.username
    FROM contact_messages m
    LEFT JOIN users u ON u.id = m.user_id
    ORDER BY m.sent_at DESC
')->fetchAll();

// Mark unread messages as read after loading them, so this page can still show
// which messages were new when the admin opened it.
$pdo->exec('UPDATE contact_messages SET is_read = TRUE WHERE is_read = FALSE');

$after_login  = array_values(array_filter($messages, fn($m) => $m['user_id'] !== null));
$before_login = array_values(array_filter($messages, fn($m) => $m['user_id'] === null));

function renderMessageItem($msg, $fallbackName) {
    $sender = $msg['username'] ?? $msg['name'] ?? $fallbackName;
    $status = $msg['is_read'] ? 'Read' : 'New';
    ?>
    <details class="message-item">
      <summary class="message-summary">
        <span class="message-toggle" aria-hidden="true">›</span>
        <span class="message-status <?= $msg['is_read'] ? 'is-read' : 'is-new' ?>"><?= e($status) ?></span>
        <span class="message-preview">
          <span class="message-preview-top">
            <span class="message-sender">From: <?= e($sender) ?></span>
            <time class="message-time" datetime="<?= e($msg['sent_at']) ?>"><?= e($msg['sent_at']) ?></time>
          </span>
          <span class="message-subject-line"><strong>Subject:</strong> <?= e($msg['subject']) ?></span>
        </span>
      </summary>
      <div class="message-detail">
        <div class="message-body"><?= e($msg['message']) ?></div>
        <div class="message-actions">
          <form method="POST" class="message-delete-form" onsubmit="return confirm('Delete this message?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="message_id" value="<?= e($msg['id']) ?>">
            <button type="submit" class="btn btn-secondary btn-sm btn-danger-soft">Delete</button>
          </form>
          <span class="message-action-note">
            Message ID #<?= e($msg['id']) ?><?= $msg['email'] ? ' · Email: ' . e($msg['email']) : '' ?>
          </span>
        </div>
      </div>
    </details>
    <?php
}

include __DIR__ . '/../app/includes/header.php';
?>

<div class="admin-wrap">
  <div class="admin-topbar">
    <a href="<?= baseUrl('admin/index.php') ?>" class="btn btn-secondary btn-sm">← Dashboard</a>
    <h1>Messages</h1>
  </div>

  <?php if (empty($messages)): ?>
    <div class="info-box">No messages yet.</div>
  <?php endif; ?>

  <section class="admin-section">
    <h2>Sent after login <span class="section-count">(<?= count($after_login) ?>)</span></h2>
    <?php if (empty($after_login)): ?>
      <p class="muted-meta">No messages in this category.</p>
    <?php else: ?>
      <?php foreach ($after_login as $msg): ?>
        <?php renderMessageItem($msg, 'Participant'); ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <section class="admin-section">
    <h2>Sent before login <span class="section-count">(<?= count($before_login) ?>)</span></h2>
    <?php if (empty($before_login)): ?>
      <p class="muted-meta">No messages in this category.</p>
    <?php else: ?>
      <?php foreach ($before_login as $msg): ?>
        <?php renderMessageItem($msg, 'Anonymous'); ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
</div>

<?php include __DIR__ . '/../app/includes/footer.php'; ?>
