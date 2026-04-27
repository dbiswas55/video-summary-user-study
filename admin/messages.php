<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

requireAdmin();

$pageTitle = 'Messages — Admin';
$pdo = getDb();

// Mark all as read when the page is opened
$pdo->exec('UPDATE contact_messages SET is_read = TRUE WHERE is_read = FALSE');

$messages = $pdo->query('
    SELECT m.id, m.user_id, m.name, m.email, m.subject, m.message, m.sent_at,
           u.username
    FROM contact_messages m
    LEFT JOIN users u ON u.id = m.user_id
    ORDER BY m.sent_at DESC
')->fetchAll();

$after_login  = array_values(array_filter($messages, fn($m) => $m['user_id'] !== null));
$before_login = array_values(array_filter($messages, fn($m) => $m['user_id'] === null));

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
        <div class="message-card">
          <div class="message-meta">
            <span class="message-sender"><?= e($msg['username']) ?></span>
            <?php if ($msg['email']): ?>
              <span class="message-email"><?= e($msg['email']) ?></span>
            <?php endif; ?>
            <span class="message-time"><?= e($msg['sent_at']) ?></span>
          </div>
          <div class="message-subject"><?= e($msg['subject']) ?></div>
          <div class="message-body"><?= nl2br(e($msg['message'])) ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <section class="admin-section">
    <h2>Sent before login <span class="section-count">(<?= count($before_login) ?>)</span></h2>
    <?php if (empty($before_login)): ?>
      <p class="muted-meta">No messages in this category.</p>
    <?php else: ?>
      <?php foreach ($before_login as $msg): ?>
        <div class="message-card">
          <div class="message-meta">
            <span class="message-sender"><?= e($msg['name'] ?? 'Anonymous') ?></span>
            <?php if ($msg['email']): ?>
              <span class="message-email"><?= e($msg['email']) ?></span>
            <?php endif; ?>
            <span class="message-time"><?= e($msg['sent_at']) ?></span>
          </div>
          <div class="message-subject"><?= e($msg['subject']) ?></div>
          <div class="message-body"><?= nl2br(e($msg['message'])) ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
</div>

<?php include __DIR__ . '/../app/includes/footer.php'; ?>
