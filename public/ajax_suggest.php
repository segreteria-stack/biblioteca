<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

require ROOT . '/config.php';
require ROOT . '/lib/DB.php';
require ROOT . '/lib/helpers.php';

if (!defined('SUGGEST_LIMIT')) define('SUGGEST_LIMIT', 10);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$field   = trim((string)($_GET['field'] ?? 'title'));
$q       = trim((string)($_GET['q']     ?? ''));
$allowed = ['title', 'author', 'subject', 'publisher'];

if (!in_array($field, $allowed, true) || $q === '' || mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $pdo     = DB::conn();
    $limit   = (int)SUGGEST_LIMIT;
    $pattern = '%' . $q . '%';

    switch ($field) {
        case 'title':
            $stmt = $pdo->prepare("
                SELECT DISTINCT
                    TRIM(CONCAT(
                        COALESCE(title, ''),
                        IF(title_remainder IS NOT NULL AND title_remainder != '',
                           CONCAT(' ', title_remainder), '')
                    )) AS val
                FROM biblio
                WHERE opac_flg = 'Y'
                  AND (title LIKE ? OR title_remainder LIKE ?)
                  AND title IS NOT NULL AND title != ''
                ORDER BY val
                LIMIT ?
            ");
            $stmt->execute([$pattern, $pattern, $limit]);
            break;

        case 'author':
            $stmt = $pdo->prepare("
                SELECT DISTINCT author AS val
                FROM biblio
                WHERE opac_flg = 'Y'
                  AND author LIKE ?
                  AND author IS NOT NULL AND author != ''
                ORDER BY author
                LIMIT ?
            ");
            $stmt->execute([$pattern, $limit]);
            break;

        case 'subject':
            $stmt = $pdo->prepare("
                SELECT DISTINCT subject AS val FROM (
                    SELECT topic1 AS subject FROM biblio WHERE opac_flg = 'Y' AND topic1 LIKE ?
                    UNION
                    SELECT topic2 FROM biblio WHERE opac_flg = 'Y' AND topic2 LIKE ?
                    UNION
                    SELECT topic3 FROM biblio WHERE opac_flg = 'Y' AND topic3 LIKE ?
                    UNION
                    SELECT topic4 FROM biblio WHERE opac_flg = 'Y' AND topic4 LIKE ?
                    UNION
                    SELECT topic5 FROM biblio WHERE opac_flg = 'Y' AND topic5 LIKE ?
                ) tmp
                WHERE subject IS NOT NULL AND subject != ''
                ORDER BY val
                LIMIT ?
            ");
            $stmt->execute([$pattern, $pattern, $pattern, $pattern, $pattern, $limit]);
            break;

        case 'publisher':
            $stmt = $pdo->prepare("
                SELECT DISTINCT publisher AS val
                FROM biblio_index_ext
                WHERE publisher LIKE ?
                  AND publisher IS NOT NULL AND publisher != ''
                ORDER BY publisher
                LIMIT ?
            ");
            $stmt->execute([$pattern, $limit]);
            break;

        default:
            echo json_encode([]);
            exit;
    }

    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(array_values(array_filter($rows, static fn($v) => $v !== null && $v !== '')));

} catch (\Throwable $e) {
    echo json_encode([]);
}
exit;
