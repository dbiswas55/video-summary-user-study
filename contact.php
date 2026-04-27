<?php
require_once __DIR__ . '/app/includes/auth.php';
require_once __DIR__ . '/app/includes/functions.php';
require_once __DIR__ . '/app/includes/mailer.php';

startSessionIfNeeded();

$pageTitle = 'Contact Admin — User Study';
$loggedIn  = isLoggedIn();
$user      = $loggedIn ? getCurrentUser() : null;
$errors    = [];
$success   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name']    ?? '');
    $email   = trim($_POST['email']   ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!$loggedIn && empty($name)) {
        $errors[] = 'Please enter your name.';
    }
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address, or leave it blank.';
    }
    if (empty($subject)) {
        $errors[] = 'Subject is required.';
    }
    if (empty($message)) {
        $errors[] = 'Message is required.';
    }
    if (strlen($message) > 5000) {
        $errors[] = 'Message must be under 5000 characters.';
    }

    if (empty($errors)) {
        $pdo  = getDb();
        $stmt = $pdo->prepare('
            INSERT INTO contact_messages (user_id, name, email, subject, message)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $loggedIn ? $user['id'] : null,
            $loggedIn ? $user['username'] : $name,
            $email ?: null,
            $subject,
            $message,
        ]);

        $config = require __DIR__ . '/app/config/config.php';
        $adminEmail = $config['mail']['admin_email'] ?? '';
        if ($adminEmail && isMailConfigured()) {
            $senderName = $loggedIn ? $user['username'] : $name;
            $body = "A new contact message was sent from the VideoPoints user study.\n\n"
                  . "Sender: {$senderName}\n"
                  . "Email: " . ($email ?: 'not provided') . "\n"
                  . "Subject: {$subject}\n\n"
                  . $message . "\n";
            sendAppEmail(
                $adminEmail,
                'User Study Contact: ' . $subject,
                $body,
                $email ?: '',
                $senderName
            );
        }

        $success = true;
    }
}

include __DIR__ . '/app/includes/header.php';
?>

<div class="contact-wrapper">
  <div class="contact-card">
    <h1>Contact Admin</h1>
    <p class="muted-meta">Have a question, need help, or want to leave feedback? Send a message to the study administrator.</p>

    <?php if ($success): ?>
      <div class="alert alert-success">Your message has been sent. Thank you!</div>
      <div class="form-actions" style="justify-content:flex-start; margin-top:0;">
        <?php if ($loggedIn): ?>
          <a href="<?= baseUrl('dashboard.php') ?>" class="btn btn-secondary">← Back to Dashboard</a>
        <?php else: ?>
          <a href="<?= baseUrl('index.php') ?>" class="btn btn-secondary">← Back to Home</a>
        <?php endif; ?>
      </div>
    <?php else: ?>

      <?php if ($errors): ?>
        <div class="alert alert-error">
          <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="auth-form">

        <?php if ($loggedIn): ?>
          <div class="contact-sender-info">
            Sending as: <strong><?= e($user['username']) ?></strong>
          </div>
        <?php else: ?>
          <label>
            <span>Your Name <span class="required-mark">*</span></span>
            <input type="text" name="name" value="<?= e($_POST['name'] ?? '') ?>" required maxlength="100" placeholder="How should we address you?">
          </label>
        <?php endif; ?>

        <label>
          <span>Email <span class="field-optional">(optional — include if you want a reply)</span></span>
          <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" maxlength="255" placeholder="your@email.com">
        </label>

        <label>
          <span>Subject <span class="required-mark">*</span></span>
          <input type="text" name="subject" value="<?= e($_POST['subject'] ?? '') ?>" required maxlength="200" placeholder="Brief summary of your message">
        </label>

        <label>
          <span>Message <span class="required-mark">*</span></span>
          <textarea name="message" rows="7" required maxlength="5000"
            placeholder="Write your message here..."><?= e($_POST['message'] ?? '') ?></textarea>
        </label>

        <div class="form-actions">
          <?php if ($loggedIn): ?>
            <a href="<?= baseUrl('dashboard.php') ?>" class="btn btn-secondary">Cancel</a>
          <?php else: ?>
            <a href="<?= baseUrl('index.php') ?>" class="btn btn-secondary">Cancel</a>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary">Send Message</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/app/includes/footer.php'; ?>
