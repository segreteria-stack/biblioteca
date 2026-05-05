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
    if ($base === '') {
        $base = '';
    }
    return rtrim($base, '/');
}
