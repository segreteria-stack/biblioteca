<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

require ROOT . '/config.php';
require ROOT . '/lib/DB.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Solo richieste XHR
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));

if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'suggestions' => []]);
    exit;
}

try {
    $pdo = DB::conn();
} catch (\Throwable $e) {
    echo json_encode(['ok' => false]);
    exit;
}

$base    = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/');
$pattern = '%' . $q . '%';
$results = [];

// ── Titoli (fino a 5) ───────────────────────────────────────────────────────
try {
    $st = $pdo->prepare("
        SELECT bibid, title, author
        FROM biblio
        WHERE title LIKE ? AND opac_flg = 'Y' AND title <> ''
        ORDER BY title
        LIMIT 5
    ");
    $st->execute([$pattern]);
    while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
        $label = trim((string)$row['title']);
        $sub   = trim((string)$row['author']);
        $results[] = [
            'type'  => 'title',
            'label' => $label,
            'sub'   => $sub !== '' ? $sub : null,
            'url'   => $base . '/index.php?page=item&bibid=' . (int)$row['bibid'],
        ];
    }
} catch (\PDOException $e) {}

// ── Autori (fino a 3, distinti) ─────────────────────────────────────────────
try {
    $st = $pdo->prepare("
        SELECT DISTINCT author
        FROM biblio
        WHERE author LIKE ? AND opac_flg = 'Y' AND author <> ''
        ORDER BY author
        LIMIT 3
    ");
    $st->execute([$pattern]);
    while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
        $results[] = [
            'type'  => 'author',
            'label' => trim((string)$row['author']),
            'sub'   => null,
            'url'   => $base . '/index.php?page=search&q=' . urlencode(trim((string)$row['author'])),
        ];
    }
} catch (\PDOException $e) {}

// ── Soggetti (fino a 3, per frequenza) ──────────────────────────────────────
try {
    $st = $pdo->prepare("
        SELECT topic, COUNT(*) AS cnt
        FROM (
            SELECT topic1 AS topic FROM biblio WHERE topic1 LIKE ? AND opac_flg = 'Y' AND topic1 <> ''
            UNION ALL
            SELECT topic2 FROM biblio WHERE topic2 LIKE ? AND opac_flg = 'Y' AND topic2 <> ''
            UNION ALL
            SELECT topic3 FROM biblio WHERE topic3 LIKE ? AND opac_flg = 'Y' AND topic3 <> ''
            UNION ALL
            SELECT topic4 FROM biblio WHERE topic4 LIKE ? AND opac_flg = 'Y' AND topic4 <> ''
            UNION ALL
            SELECT topic5 FROM biblio WHERE topic5 LIKE ? AND opac_flg = 'Y' AND topic5 <> ''
        ) t
        GROUP BY topic
        ORDER BY cnt DESC, topic ASC
        LIMIT 3
    ");
    $st->execute([$pattern, $pattern, $pattern, $pattern, $pattern]);
    while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
        $results[] = [
            'type'  => 'topic',
            'label' => trim((string)$row['topic']),
            'sub'   => null,
            'url'   => $base . '/index.php?page=search&subject=' . urlencode(trim((string)$row['topic'])),
        ];
    }
} catch (\PDOException $e) {}

echo json_encode(['ok' => true, 'suggestions' => $results], JSON_UNESCAPED_UNICODE);
