<?php
// Alias legacy: user_login -> patron_login
$title = 'Accesso Patron';
$base = rtrim((string)($cfg['app']['base_url'] ?? ''), '/');
header('Location: ' . $base . '/index.php?page=patron_login');
exit;
