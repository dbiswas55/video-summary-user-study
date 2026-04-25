<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

logoutUser();
setFlash('info', 'You have been signed out.');
header('Location: ' . baseUrl('index.php'));
exit;
