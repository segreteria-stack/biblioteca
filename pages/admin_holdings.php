<?php
declare(strict_types=1);
$bibid = (int)($_GET['bibid'] ?? 0);
$dest  = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/') . '/index.php?page=staff_catalog_edit';
if ($bibid > 0) {
    $dest .= '&edit_bibid=' . $bibid . '#copies';
}
header('Location: ' . $dest, true, 301);
exit;
