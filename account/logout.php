<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

logoutUser();
setFlash('info', 'You have been signed out.');
header('Location: ' . baseUrl('index.php'));
exit;
