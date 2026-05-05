<?php
declare(strict_types=1);

function h(?string $v): string
{
    return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string
{
    global $cfg;
    $base = '';
    if (is_array($cfg ?? null) && isset($cfg['app']['base_url'])) {
        $base = (string)$cfg['app']['base_url'];
    }
    return rtrim($base, '/');
}

// CSRF per patron (chiave separata da quella staff)
function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['_csrf_patron'])) {
        $_SESSION['_csrf_patron'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['_csrf_patron'];
}

function csrf_check(?string $token): bool
{
    if ($token === null || $token === '') {
        return false;
    }
    $stored = (string)($_SESSION['_csrf_patron'] ?? '');
    return $stored !== '' && hash_equals($stored, $token);
}
