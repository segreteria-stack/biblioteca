<?php
declare(strict_types=1);
// Alias legacy: user_login -> patron_login
$title = 'Accesso Patron';
$base = base_url();
header('Location: ' . $base . '/index.php?page=patron_login');
exit;
