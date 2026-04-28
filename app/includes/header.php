<?php
require_once __DIR__ . '/functions.php';
$config = require __DIR__ . '/../config/config.php';
$study  = loadJsonConfig('study.json');
$headerUser = isLoggedIn() ? getCurrentUser() : null;
$flash  = getFlash();
$pageStyles = $pageStyles ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? $study['study_title']) ?></title>
<link rel="icon" href="<?= baseUrl('assets/favicon.svg') ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= assetUrl('assets/css/main.css') ?>">
<?php foreach ((array)$pageStyles as $style): ?>
<link rel="stylesheet" href="<?= assetUrl($style) ?>">
<?php endforeach; ?>
</head>
<body>

<header class="site-header">
  <div class="header-inner">
    <a href="<?= baseUrl($headerUser ? 'dashboard.php' : 'index.php') ?>" class="brand">
      <span class="brand-text"><?= e($study['study_title']) ?></span>
    </a>
    <nav class="user-nav">
      <?php if ($headerUser): ?>
        <a href="<?= baseUrl('dashboard.php') ?>" class="nav-link">Home</a>
        <?php if ($headerUser['is_admin']): ?>
          <?php
            $pdo = getDb();
            $unread = (int)$pdo->query('SELECT COUNT(*) FROM contact_messages WHERE is_read = FALSE')->fetchColumn();
          ?>
          <a href="<?= baseUrl('admin/index.php') ?>" class="nav-link">Admin<?= $unread > 0 ? ' <span class="nav-badge">' . $unread . '</span>' : '' ?></a>
        <?php endif; ?>
        <a href="<?= baseUrl('account/profile.php') ?>" class="nav-link">Profile</a>
        <a href="<?= baseUrl('contact.php') ?>" class="nav-link">Contact</a>
        <a href="<?= baseUrl('account/logout.php') ?>" class="nav-link">Logout</a>
      <?php else: ?>
        <a href="<?= baseUrl('contact.php') ?>" class="nav-link">Contact</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="site-main">

<?php if ($flash): ?>
  <div class="flash flash-<?= e($flash['type']) ?>">
    <?= e($flash['message']) ?>
  </div>
<?php endif; ?>
