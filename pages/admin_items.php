<?php
declare(strict_types=1);
// Redirect permanente alla pagina moderna equivalente
header('Location: ' . rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/') . '/index.php?page=staff_search', true, 301);
exit;
