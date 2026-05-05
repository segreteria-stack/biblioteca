<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/PatronAuth.php';

// Logout logico (sessione patron)
PatronAuth::logout();

// Redirect manuale (redirect() NON esiste nel frontend)
$base = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/');

header('Location: ' . $base . '/index.php');
exit;
