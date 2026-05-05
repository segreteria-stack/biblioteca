<?php
declare(strict_types=1);

/**
 * ajax_bulk_description.php
 *
 * Endpoint POST chiamato dal browser per l'arricchimento riassunti.
 * Standalone — NON passa da public/index.php (stesso pattern di cover_save.php).
 *
 * Input:  GET  action=(preview|run)&limit=N&offset=M
 * Output: JSON { ok: true|false, ... }
 */

define('ROOT', dirname(__DIR__));

require ROOT . '/config.php';
require ROOT . '/lib/DB.php';
require ROOT . '/lib/helpers.php';

header('Content-Type: application/json; charset=utf-8');

// --- Connessione DB ---
try {
    $pdo = DB::conn();
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'DB: ' . $e->getMessage()]);
    exit;
}

// --- Auth ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Non autenticato']);
    exit;
}

$apiKey     = $cfg['google_books']['api_key'] ?? '';
$apiEnabled = !empty($cfg['google_books']['enabled']) && $apiKey !== '';

if (!$apiEnabled) {
    echo json_encode(['ok' => false, 'error' => 'Google Books API non configurata']);
    exit;
}

/* ================================================================
 * Helpers
 * ================================================================ */
function bkd_normalizeIsbn(string $raw): string
{
    $clean = preg_replace('/[^0-9Xx]/', '', trim($raw));
    return strtoupper((string)$clean);
}

function bkd_isValidIsbn(string $isbn): bool
{
    $len = strlen($isbn);
    if ($len !== 10 && $len !== 13) {
        return false;
    }
    if ($len === 10) {
        return preg_match('/^[0-9]{9}[0-9X]$/', $isbn) === 1;
    }
    return preg_match('/^(978|979)[0-9]{10}$/', $isbn) === 1;
}

function bkd_gbDescription(string $isbn, string $apiKey): array
{
    $url = 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . rawurlencode($isbn);
    if ($apiKey !== '') {
        $url .= '&key=' . rawurlencode($apiKey);
    }

    $ctx = stream_context_create([
        'http'  => ['timeout' => 5, 'header' => 'Accept: application/json'],
        'https' => ['timeout' => 5],
    ]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) {
        return ['description' => '', 'title' => ''];
    }

    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['items'])) {
        return ['description' => '', 'title' => ''];
    }

    $vi   = $data['items'][0]['volumeInfo'] ?? [];
    $desc = trim((string)($vi['description'] ?? ''));
    $title = trim((string)($vi['title'] ?? ''));

    return ['description' => $desc, 'title' => $title];
}

function bkd_fetchCandidates(\PDO $pdo, int $limit, int $offset): array
{
    $st = $pdo->prepare("
        SELECT b.bibid, b.title, isbn_f.field_data AS isbn
        FROM biblio_field isbn_f
        JOIN biblio b ON b.bibid = isbn_f.bibid
        WHERE isbn_f.tag = 20
          AND isbn_f.subfield_cd = 'a'
          AND isbn_f.field_data IS NOT NULL
          AND isbn_f.field_data != ''
          AND NOT EXISTS (
              SELECT 1 FROM biblio_field desc_f
              WHERE desc_f.bibid = isbn_f.bibid
                AND desc_f.tag = 520
                AND desc_f.subfield_cd = 'a'
          )
        ORDER BY b.bibid ASC, isbn_f.field_data ASC
        LIMIT :limit OFFSET :offset
    ");
    $st->bindValue(':limit',  $limit,  \PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, \PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(\PDO::FETCH_ASSOC);
}

/* ================================================================
 * Azioni
 * ================================================================ */
$action = $_GET['action'] ?? '';
$limit  = max(1, min(100, (int)($_GET['limit'] ?? 20)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

// --- PREVIEW ---
if ($action === 'preview') {
    $rows = bkd_fetchCandidates($pdo, $limit, $offset);
    echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- RUN ---
if ($action === 'run') {
    $rows    = bkd_fetchCandidates($pdo, $limit, $offset);
    $results = [];

    if (empty($rows)) {
        echo json_encode([
            'ok'        => true,
            'total'     => 0,
            'saved'     => 0,
            'results'   => [],
            'remaining' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ins = $pdo->prepare("
        INSERT INTO biblio_field (bibid, tag, ind1_cd, ind2_cd, subfield_cd, field_data)
        VALUES (:bibid, 520, NULL, NULL, 'a', :field_data)
    ");

    $pdo->beginTransaction();

    try {
        foreach ($rows as $row) {
            $isbn = bkd_normalizeIsbn((string)$row['isbn']);

            if ($isbn === '' || !bkd_isValidIsbn($isbn)) {
                $results[] = [
                    'bibid'   => $row['bibid'],
                    'title'   => mb_strimwidth((string)$row['title'], 0, 60, '…'),
                    'isbn'    => $row['isbn'],
                    'status'  => 'skip',
                    'source'  => 'invalid_isbn',
                    'preview' => '',
                ];
                continue;
            }

            $res = bkd_gbDescription($isbn, $apiKey);

            if ($res['description'] !== '') {
                $ins->execute([
                    ':bibid'      => $row['bibid'],
                    ':field_data' => $res['description'],
                ]);
                $status = 'ok';
            } else {
                $status = 'not_found';
            }

            $results[] = [
                'bibid'   => $row['bibid'],
                'title'   => mb_strimwidth((string)$row['title'], 0, 60, '…'),
                'isbn'    => $row['isbn'],
                'status'  => $status,
                'source'  => 'google_books',
                'preview' => mb_strimwidth($res['description'], 0, 120, '…'),
            ];
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        echo json_encode([
            'ok'    => false,
            'error' => 'DB: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $saved = count(array_filter($results, fn($r) => $r['status'] === 'ok'));

    // Conta rimanenti
    $remaining = (int)$pdo->query("
        SELECT COUNT(DISTINCT isbn_f.bibid)
        FROM biblio_field isbn_f
        WHERE isbn_f.tag = 20
          AND isbn_f.subfield_cd = 'a'
          AND isbn_f.field_data IS NOT NULL
          AND isbn_f.field_data != ''
          AND NOT EXISTS (
              SELECT 1 FROM biblio_field desc_f
              WHERE desc_f.bibid = isbn_f.bibid
                AND desc_f.tag = 520
                AND desc_f.subfield_cd = 'a'
          )
    ")->fetchColumn();

    echo json_encode([
        'ok'        => true,
        'total'     => count($results),
        'saved'     => $saved,
        'results'   => $results,
        'remaining' => $remaining,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Azione non riconosciuta ---
echo json_encode(['ok' => false, 'error' => 'Azione non riconosciuta.']);
exit;