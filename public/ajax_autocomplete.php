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

$base       = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/');
$anyPattern = '%' . $q . '%';
$swPattern  = $q . '%';   // starts-with — usato per dare priorità
$results    = [];

function truncAc(string $s, int $max = 72): string {
    return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') . '…' : $s;
}

// ── Titoli (fino a 5, priorità "inizia con") ────────────────────────────────
try {
    $st = $pdo->prepare("
        SELECT bibid, title, author
        FROM biblio
        WHERE title LIKE ? AND opac_flg = 'Y' AND title <> ''
        ORDER BY CASE WHEN title LIKE ? THEN 0 ELSE 1 END, title
        LIMIT 5
    ");
    $st->execute([$anyPattern, $swPattern]);
    while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
        $label = truncAc(trim((string)$row['title']));
        $sub   = truncAc(trim((string)$row['author']), 48);
        $results[] = [
            'type'  => 'title',
            'label' => $label,
            'sub'   => $sub !== '' ? $sub : null,
            'url'   => $base . '/index.php?page=item&bibid=' . (int)$row['bibid'],
        ];
    }
} catch (\PDOException $e) {}

// ── Autori (fino a 3, priorità "inizia con") ────────────────────────────────
try {
    $st = $pdo->prepare("
        SELECT DISTINCT author
        FROM biblio
        WHERE author LIKE ? AND opac_flg = 'Y' AND author <> ''
        ORDER BY CASE WHEN author LIKE ? THEN 0 ELSE 1 END, author
        LIMIT 3
    ");
    $st->execute([$anyPattern, $swPattern]);
    while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
        $results[] = [
            'type'  => 'author',
            'label' => truncAc(trim((string)$row['author'])),
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
        ORDER BY CASE WHEN topic LIKE ? THEN 0 ELSE 1 END, cnt DESC, topic ASC
        LIMIT 3
    ");
    $st->execute([$anyPattern, $anyPattern, $anyPattern, $anyPattern, $anyPattern, $swPattern]);
    while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
        $results[] = [
            'type'  => 'topic',
            'label' => truncAc(trim((string)$row['topic'])),
            'sub'   => null,
            'url'   => $base . '/index.php?page=search&subject=' . urlencode(trim((string)$row['topic'])),
        ];
    }
} catch (\PDOException $e) {}

echo json_encode(['ok' => true, 'suggestions' => $results], JSON_UNESCAPED_UNICODE);
