<?php
require_once __DIR__ . '/functions.php';
$config = require __DIR__ . '/../config/config.php';
$study  = loadJsonConfig('study.json');
$user   = isLoggedIn() ? getCurrentUser() : null;
$flash  = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? $study['study_title']) ?></title>
<link rel="stylesheet" href="<?= baseUrl('assets/css/main.css') ?>">
</head>
<body>

<header class="site-header">
  <div class="header-inner">
    <a href="<?= baseUrl($user ? 'dashboard.php' : 'index.php') ?>" class="brand">
      <span class="brand-mark">VDS</span>
      <span class="brand-text"><?= e($study['study_title']) ?></span>
    </a>
    <?php if ($user): ?>
      <nav class="user-nav">
        <span class="user-name">👤 <?= e($user['username']) ?></span>
        <?php if ($user['is_admin']): ?>
          <a href="<?= baseUrl('admin/index.php') ?>" class="nav-link">Admin</a>
        <?php endif; ?>
        <a href="<?= baseUrl('logout.php') ?>" class="nav-link">Logout</a>
      </nav>
    <?php endif; ?>
  </div>
</header>

<main class="site-main">

<?php if ($flash): ?>
  <div class="flash flash-<?= e($flash['type']) ?>">
    <?= e($flash['message']) ?>
  </div>
<?php endif; ?>
