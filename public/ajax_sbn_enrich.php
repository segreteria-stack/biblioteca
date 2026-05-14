<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

require ROOT . '/config.php';
require ROOT . '/lib/DB.php';
require ROOT . '/lib/helpers.php';
require ROOT . '/lib/SbnClient.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['staff_user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Non autenticato']);
    exit;
}

$sbnKey    = $cfg['sbn']['consumer_key']    ?? '';
$sbnSecret = $cfg['sbn']['consumer_secret'] ?? '';

if ($sbnKey === '' || $sbnSecret === '') {
    echo json_encode(['ok' => false, 'error' => 'Credenziali SBN non configurate']);
    exit;
}

$action = $_GET['action'] ?? '';
$client = new SbnClient($sbnKey, $sbnSecret);

function fieldExists(\PDO $pdo, int $bibid, int $tag, string $subfield): bool
{
    $st = $pdo->prepare("
        SELECT 1 FROM biblio_field 
        WHERE bibid = ? AND tag = ? AND subfield_cd = ?
        LIMIT 1
    ");
    $st->execute([$bibid, $tag, $subfield]);
    return (bool)$st->fetch();
}

function insertField(\PDO $pdo, int $bibid, int $tag, string $subfield, $value): bool
{
    if (is_array($value)) {
        $value = implode('; ', array_filter($value, fn($v) => $v !== null && $v !== ''));
    } elseif ($value === null) {
        return false;
    } else {
        $value = (string)$value;
    }

    if ($value === '') return false;
    if (fieldExists($pdo, $bibid, $tag, $subfield)) return false;

    $st = $pdo->prepare("
        INSERT INTO biblio_field (bibid, tag, ind1_cd, ind2_cd, subfield_cd, field_data)
        VALUES (?, ?, NULL, NULL, ?, ?)
    ");
    $st->execute([$bibid, $tag, $subfield, $value]);
    return true;
}

function insertFields(\PDO $pdo, int $bibid, int $tag, string $subfield, array $values): int
{
    $count = 0;
    // Prepara le query una sola volta fuori dal loop
    $dup = $pdo->prepare("
        SELECT 1 FROM biblio_field
        WHERE bibid = ? AND tag = ? AND subfield_cd = ? AND field_data = ? LIMIT 1
    ");
    $ins = $pdo->prepare("
        INSERT INTO biblio_field (bibid, tag, ind1_cd, ind2_cd, subfield_cd, field_data)
        VALUES (?, ?, NULL, NULL, ?, ?)
    ");
    foreach ($values as $value) {
        if (is_array($value)) {
            $value = implode('; ', array_filter($value, fn($v) => $v !== null && $v !== ''));
        } elseif ($value === null) {
            continue;
        } else {
            $value = trim((string)$value);
        }
        if ($value === '') continue;
        // Controlla duplicato ESATTO (bibid + tag + subfield + field_data):
        // fieldExists() controlla solo (bibid, tag, subfield) e blocca tutti i soggetti
        // dopo il primo — qui controlliamo il valore completo per permettere multi-valore.
        $dup->execute([$bibid, $tag, $subfield, $value]);
        if ($dup->fetch()) continue;
        $ins->execute([$bibid, $tag, $subfield, $value]);
        $count++;
    }
    return $count;
}

function updateBiblioAuthor(\PDO $pdo, int $bibid, string $autore): bool
{
    $st = $pdo->prepare("
        SELECT 1 FROM biblio 
        WHERE bibid = ? AND (author IS NOT NULL AND author != '')
        LIMIT 1
    ");
    $st->execute([$bibid]);
    if ($st->fetch()) return false;

    $upd = $pdo->prepare("UPDATE biblio SET author = ? WHERE bibid = ?");
    $upd->execute([$autore, $bibid]);
    return true;
}

/**
 * Scrive i topic1..5 in biblio a partire da un array di soggetti.
 * Non sovrascrive se i topic sono già popolati.
 */
function updateTopics(\PDO $pdo, int $bibid, array $soggetti): void
{
    if (empty($soggetti)) return;

    // Controlla se i topic sono già popolati
    $check = $pdo->prepare("
        SELECT 1 FROM biblio 
        WHERE bibid = ? AND (topic1 IS NOT NULL AND topic1 != '')
        LIMIT 1
    ");
    $check->execute([$bibid]);
    if ($check->fetch()) return;

    $topics    = array_slice(array_filter($soggetti, fn($s) => $s !== '' && $s !== null), 0, 5);
    $topicData = array_pad($topics, 5, null);

    $upd = $pdo->prepare("
        UPDATE biblio SET topic1 = ?, topic2 = ?, topic3 = ?, topic4 = ?, topic5 = ?
        WHERE bibid = ?
    ");
    $upd->execute([
        $topicData[0] ?? null,
        $topicData[1] ?? null,
        $topicData[2] ?? null,
        $topicData[3] ?? null,
        $topicData[4] ?? null,
        $bibid,
    ]);
}

/**
 * Restituisce il material_cd di default (da default_flg='Y', poi primo codice).
 */
function defaultMaterialCd(\PDO $pdo): int
{
    $st = $pdo->query("SELECT code FROM material_type_dm WHERE default_flg = 'Y' ORDER BY code LIMIT 1");
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if ($row) return (int)$row['code'];
    $st = $pdo->query("SELECT code FROM material_type_dm ORDER BY code LIMIT 1");
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    return $row ? (int)$row['code'] : 1;
}

/**
 * Restituisce il collection_cd di default (da default_flg='Y', poi primo codice).
 */
function defaultCollectionCd(\PDO $pdo): int
{
    $st = $pdo->query("SELECT code FROM collection_dm WHERE default_flg = 'Y' ORDER BY code LIMIT 1");
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if ($row) return (int)$row['code'];
    $st = $pdo->query("SELECT code FROM collection_dm ORDER BY code LIMIT 1");
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    return $row ? (int)$row['code'] : 1;
}

/**
 * Inserisce o aggiorna un campo MARC (upsert su tag+subfield_cd).
 * Utile per ISBN: se già presente con valore diverso, aggiorna.
 */
function upsertField(\PDO $pdo, int $bibid, int $tag, string $subfield, ?string $value): bool
{
    if ($value === null || $value === '') return false;
    if (fieldExists($pdo, $bibid, $tag, $subfield)) {
        $pdo->prepare("UPDATE biblio_field SET field_data = ? WHERE bibid = ? AND tag = ? AND subfield_cd = ?")
            ->execute([$value, $bibid, $tag, $subfield]);
        return true;
    }
    $pdo->prepare("INSERT INTO biblio_field (bibid, tag, ind1_cd, ind2_cd, subfield_cd, field_data) VALUES (?, ?, NULL, NULL, ?, ?)")
        ->execute([$bibid, $tag, $subfield, $value]);
    return true;
}

/**
 * Genera il prossimo (copyid, barcode) per un bibid.
 * Barcode = bibid a 5 cifre + copyid a 2 cifre.
 */
/**
 * Inserisce una nuova copia lasciando che MySQL assegni copyid via AUTO_INCREMENT
 * per-gruppo (MyISAM composite PK bibid+copyid) — atomico, senza race condition.
 * Restituisce [copyid, barcode].
 */
function insertCopy(\PDO $pdo, int $bibid, string $status = 'in', string $barcode = ''): array
{
    // INSERT senza copyid: MySQL lo assegna atomicamente (per-group AUTO_INCREMENT)
    $pdo->prepare('INSERT INTO biblio_copy (bibid,create_dt,barcode_nmbr,status_cd,status_begin_dt,renewal_count) VALUES (?,NOW(),\'\',?,NOW(),0)')
        ->execute([$bibid, $status]);
    $copyid     = (int)$pdo->lastInsertId();
    $autoBarcode = str_pad((string)$bibid, 5, '0', STR_PAD_LEFT)
                 . str_pad((string)$copyid, 2, '0', STR_PAD_LEFT);
    $finalBarcode = ($barcode !== '' && strlen($barcode) <= 20) ? $barcode : $autoBarcode;
    $pdo->prepare('UPDATE biblio_copy SET barcode_nmbr=? WHERE bibid=? AND copyid=?')
        ->execute([$finalBarcode, $bibid, $copyid]);
    return [$copyid, $finalBarcode];
}

/**
 * Popola (o aggiorna) biblio_index_ext con ISBN, anno, editore, luogo.
 * Usata da search_advanced.php e item.php per la ricerca avanzata.
 */
function syncIndexExt(\PDO $pdo, int $bibid, string $isbn, string $pubYear, string $publisher, string $pubPlace): void
{
    $year = (int)preg_replace('/[^0-9]/', '', substr($pubYear, 0, 4));
    $pdo->prepare('INSERT INTO biblio_index_ext (bibid,isbn,pub_year,publisher,pub_place)
                   VALUES (?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE
                     isbn=VALUES(isbn), pub_year=VALUES(pub_year),
                     publisher=VALUES(publisher), pub_place=VALUES(pub_place)')
        ->execute([$bibid, $isbn !== '' ? $isbn : null, $year > 0 ? $year : null,
                   $publisher !== '' ? $publisher : null, $pubPlace !== '' ? $pubPlace : null]);
}

/* ================================================================
 * Azione: test_connection
 * ================================================================ */
if ($action === 'test_connection') {
    try {
        $test = $client->testConnection();
        echo json_encode([
            'ok'    => true,
            'msg'   => 'Connessione SBN riuscita',
            'token' => $test['token'],
        ]);
    } catch (Throwable $e) {
        echo json_encode([
            'ok'    => false,
            'error' => $e->getMessage(),
        ]);
    }
    exit;
}

/* ================================================================
 * Azione: search_sbn
 * ================================================================ */
if ($action === 'search_sbn') {
    $q = trim($_GET['q'] ?? '');
    $type = $_GET['type'] ?? 'isbn';

    if ($q === '') {
        echo json_encode(['ok' => false, 'error' => 'Query mancante']);
        exit;
    }

    try {
        $res = match($type) {
            'isbn'    => $client->searchByIsbn($q),
            'titolo'  => $client->searchByTitle($q, 20),
            'autore'  => $client->searchByAuthor($q, 20),
            default   => $client->advancedSearch(['monocampo' => $q, 'page-size' => 20]),
        };

        $docs = $res['docs'] ?? ($res['response']['docs'] ?? []);
        $results = [];

        foreach ($docs as $doc) {
            $data = $client->extractFullData($doc);
            $results[] = [
                'bid_sbn'         => $data['bid_sbn'],
                'titolo'          => $data['titolo'],
                'autore'          => $data['autore'],
                'editore'         => $data['editore'],
                'luogo'           => $data['luogo'],
                'anno'            => $data['anno'],
                'isbn'            => $data['isbn_sbn'],
                'lingua'          => $data['lingua'],
                'paese'           => $data['paese'],
                'dewey_code'      => $data['dewey_code'],
                'dewey_des'       => $data['dewey_des'],
                'collezione'      => $data['collezione'],
                'titolo_uniforme' => $data['titolo_uniforme'],
                'note'            => $data['note'],
                'abstract'        => $data['abstract'],
                'indice'          => $data['indice'],
                'bibliografia'    => $data['bibliografia'],
                'dimensioni'      => $data['dimensioni'],
                'illustrazioni'   => $data['illustrazioni'],
                'soggetti'        => $data['soggetti'],
                'opac_link'       => $data['bid_sbn'] ? SbnClient::opacLink($data['bid_sbn']) : null,
            ];
        }

        echo json_encode([
            'ok'      => true,
            'total'   => count($results),
            'results' => $results,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ================================================================
 * Azione: preview_record
 * ================================================================ */
if ($action === 'preview_record') {
    $bid = trim($_GET['bid'] ?? '');
    $bid = str_replace(['IT\\ICCU\\', '\\'], '', $bid);
    
    if ($bid === '') {
        echo json_encode(['ok' => false, 'error' => 'BID mancante']);
        exit;
    }

    try {
        $res = $client->getByBid($bid);
        $doc = $client->extractFirstDoc($res);
        if (!$doc) {
            echo json_encode(['ok' => false, 'error' => 'Record SBN non trovato']);
            exit;
        }

        $data = $client->extractFullData($doc);

        $pdo = DB::conn();
        $check = $pdo->prepare("
            SELECT bibid FROM biblio_field 
            WHERE tag = 901 AND subfield_cd = 'a' AND field_data = ?
            LIMIT 1
        ");
        $check->execute([$bid]);
        $alreadyImported = (bool)$check->fetch();

        echo json_encode([
            'ok'              => true,
            'already_imported'=> $alreadyImported,
            'bid_sbn'         => $data['bid_sbn'],
            'titolo'          => $data['titolo'],
            'autore'          => $data['autore'],
            'editore'         => $data['editore'],
            'luogo'           => $data['luogo'],
            'anno'            => $data['anno'],
            'isbn'            => $data['isbn_sbn'],
            'lingua'          => $data['lingua'],
            'paese'           => $data['paese'],
            'dewey_code'      => $data['dewey_code'],
            'dewey_des'       => $data['dewey_des'],
            'collezione'      => $data['collezione'],
            'titolo_uniforme' => $data['titolo_uniforme'],
            'note'            => $data['note'],
            'abstract'        => $data['abstract'],
            'indice'          => $data['indice'],
            'bibliografia'    => $data['bibliografia'],
            'dimensioni'      => $data['dimensioni'],
            'illustrazioni'   => $data['illustrazioni'],
            'soggetti'        => $data['soggetti'],
            'sbn_link'        => $data['bid_sbn'] ? SbnClient::opacLink($data['bid_sbn']) : null,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ================================================================
 * Azione: import_record
 * ================================================================ */
if ($action === 'import_record') {
    $bid = trim($_GET['bid'] ?? '');
    $bid = str_replace(['IT\\ICCU\\', '\\'], '', $bid);
    
    if ($bid === '') {
        echo json_encode(['ok' => false, 'error' => 'BID mancante']);
        exit;
    }

    try {
        $pdo = DB::conn();

        $check = $pdo->prepare("
            SELECT bibid FROM biblio_field 
            WHERE tag = 901 AND subfield_cd = 'a' AND field_data = ?
            LIMIT 1
        ");
        $check->execute([$bid]);
        if ($check->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Record già presente nel catalogo']);
            exit;
        }

        $res = $client->getByBid($bid);
        $doc = $client->extractFirstDoc($res);
        if (!$doc) {
            echo json_encode(['ok' => false, 'error' => 'Record SBN non trovato']);
            exit;
        }

        $data = $client->extractFullData($doc);

        $matCd = defaultMaterialCd($pdo);
        $colCd = defaultCollectionCd($pdo);

        $ins = $pdo->prepare("
            INSERT INTO biblio (title, author, material_cd, collection_cd, opac_flg, create_dt, last_change_dt, last_change_userid)
            VALUES (?, ?, ?, ?, 'Y', NOW(), NOW(), ?)
        ");
        $ins->execute([
            $data['titolo'] ?? '[Senza titolo]',
            $data['autore'] ?? '',
            $matCd,
            $colCd,
            $_SESSION['staff_user_id'] ?? 0,
        ]);
        $bibid = (int)$pdo->lastInsertId();

        $inserted = [];

        if (insertField($pdo, $bibid, 901, 'a', $bid)) $inserted[] = 'bid_sbn';
        if (upsertField($pdo, $bibid, 20, 'a', $data['isbn_sbn'])) $inserted[] = 'isbn';
        if (insertField($pdo, $bibid, 100, 'a', $data['autore'])) $inserted[] = 'autore_marc';
        if (!empty($data['autore'])) {
            $upd = $pdo->prepare("UPDATE biblio SET author = ? WHERE bibid = ?");
            $upd->execute([$data['autore'], $bibid]);
        }
        if (insertField($pdo, $bibid, 260, 'a', $data['luogo'])) $inserted[] = 'luogo';
        if (insertField($pdo, $bibid, 260, 'b', $data['editore'])) $inserted[] = 'editore';
        if (insertField($pdo, $bibid, 260, 'c', $data['anno'])) $inserted[] = 'anno';
        if (insertField($pdo, $bibid, 82, 'a', $data['dewey_code'])) $inserted[] = 'dewey';
        if (insertField($pdo, $bibid, 41, 'a', $data['lingua'])) $inserted[] = 'lingua';
        if (insertField($pdo, $bibid, 44, 'a', $data['paese'])) $inserted[] = 'paese';
        if (insertField($pdo, $bibid, 240, 'a', $data['titolo_uniforme'])) $inserted[] = 'titolo_uniforme';
        if (insertField($pdo, $bibid, 490, 'a', $data['collezione'])) $inserted[] = 'collezione';
        if (insertField($pdo, $bibid, 500, 'a', $data['note'])) $inserted[] = 'note';
        if (insertField($pdo, $bibid, 520, 'a', $data['abstract'])) $inserted[] = 'abstract';
        if (insertField($pdo, $bibid, 505, 'a', $data['indice'])) $inserted[] = 'indice';
        if (insertField($pdo, $bibid, 504, 'a', $data['bibliografia'])) $inserted[] = 'bibliografia';
        if (insertField($pdo, $bibid, 300, 'a', $data['dimensioni'])) $inserted[] = 'dimensioni';
        if (insertField($pdo, $bibid, 300, 'b', $data['illustrazioni'])) $inserted[] = 'illustrazioni';

        $soggettiCount = insertFields($pdo, $bibid, 650, 'a', $data['soggetti']);
        if ($soggettiCount > 0) $inserted[] = "soggetti({$soggettiCount})";

        // FIX: popola topic1..5 anche in import_record
        updateTopics($pdo, $bibid, $data['soggetti'] ?? []);

        // Crea copia fisica in biblio_copy (copyid assegnato atomicamente da MySQL)
        [$copyid, $barcode] = insertCopy($pdo, $bibid);
        $inserted[] = 'copia';

        syncIndexExt($pdo, $bibid, (string)($data['isbn_sbn'] ?? ''), (string)($data['anno'] ?? ''),
                     (string)($data['editore'] ?? ''), (string)($data['luogo'] ?? ''));

        echo json_encode([
            'ok'      => true,
            'bibid'   => $bibid,
            'copyid'  => $copyid,
            'inserted'=> $inserted,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        error_log('SBN import_record error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ================================================================
 * Azione: run_batch
 * ================================================================ */
if ($action === 'run_batch') {
    $limit  = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    try {
        $pdo = DB::conn();

        $st = $pdo->prepare("
            SELECT b.bibid, b.title, b.author, isbn_f.field_data AS isbn
            FROM biblio b
            JOIN biblio_field isbn_f 
                ON isbn_f.bibid = b.bibid 
                AND isbn_f.tag = 20 
                AND isbn_f.subfield_cd = 'a'
                AND isbn_f.field_data IS NOT NULL
                AND isbn_f.field_data != ''
            WHERE NOT EXISTS (
                SELECT 1 FROM biblio_field bid_f
                WHERE bid_f.bibid = b.bibid
                  AND bid_f.tag = 901
                  AND bid_f.subfield_cd = 'a'
                  AND SUBSTRING(bid_f.field_data, 1, 7) = 'IT\\\\ICCU'
            )
            GROUP BY b.bibid, b.title, b.author, isbn_f.field_data
            ORDER BY b.bibid ASC
            LIMIT ? OFFSET ?
        ");
        $st->execute([$limit, $offset]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

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

        $results = [];
        $savedCount = 0;

        foreach ($rows as $row) {
            $bibid = (int)$row['bibid'];
            $isbn  = $row['isbn'] ?? '';
            $title = $row['title'] ?? '';
            $currentAuthor = $row['author'] ?? '';

            if ($isbn === '') {
                $results[] = [
                    'bibid'  => $bibid,
                    'title'  => mb_strimwidth($title, 0, 60, '…'),
                    'isbn'   => '',
                    'status' => 'skip',
                    'reason' => 'isbn_empty',
                    'detail' => 'Campo MARC 020 $a vuoto',
                ];
                continue;
            }

            $cleanIsbn = preg_replace('/[^0-9X]/i', '', strtoupper($isbn));
            if (!preg_match('/^[0-9]{9,13}[0-9X]?$/', $cleanIsbn)) {
                $results[] = [
                    'bibid'  => $bibid,
                    'title'  => mb_strimwidth($title, 0, 60, '…'),
                    'isbn'   => $isbn,
                    'status' => 'skip',
                    'reason' => 'isbn_invalid',
                    'detail' => 'ISBN non valido: ' . $cleanIsbn,
                ];
                continue;
            }

            try {
                $res = $client->searchByIsbn($cleanIsbn);
            } catch (RuntimeException $e) {
                $msg = $e->getMessage();
                $reason = 'sbn_error';
                $detail = $msg;

                if (str_contains($msg, 'OAuth2 failed')) {
                    $reason = 'oauth_failed';
                    $detail = 'Autenticazione fallita. Verifica credenziali.';
                } elseif (str_contains($msg, 'HTTP 401')) {
                    $reason = 'unauthorized';
                    $detail = 'Token non valido.';
                } elseif (str_contains($msg, 'HTTP 403')) {
                    $reason = 'forbidden';
                    $detail = 'Accesso negato.';
                } elseif (str_contains($msg, 'HTTP 429')) {
                    $reason = 'rate_limited';
                    $detail = 'Troppe richieste.';
                } elseif (str_contains($msg, 'HTTP 500')) {
                    $reason = 'server_error';
                    $detail = 'Errore server SBN.';
                } elseif (str_contains($msg, 'HTTP 503')) {
                    $reason = 'service_unavailable';
                    $detail = 'Servizio non disponibile.';
                } elseif (str_contains($msg, 'Network error') || str_contains($msg, 'empty response')) {
                    $reason = 'network_error';
                    $detail = 'Impossibile connettersi a SBN.';
                }

                $results[] = [
                    'bibid'  => $bibid,
                    'title'  => mb_strimwidth($title, 0, 60, '…'),
                    'isbn'   => $isbn,
                    'status' => 'error',
                    'reason' => $reason,
                    'detail' => $detail,
                ];
                continue;
            }

            $doc = $client->extractFirstDoc($res);

            if (!$doc) {
                $results[] = [
                    'bibid'  => $bibid,
                    'title'  => mb_strimwidth($title, 0, 60, '…'),
                    'isbn'   => $isbn,
                    'status' => 'not_found',
                    'reason' => 'sbn_no_match',
                    'detail' => 'ISBN ' . $cleanIsbn . ' non trovato.',
                ];
                continue;
            }

            $data = $client->extractFullData($doc);
            $inserted = [];

            if ($data['bid_sbn'] && insertField($pdo, $bibid, 901, 'a', $data['bid_sbn'])) {
                $inserted[] = 'bid_sbn';
            }
            if ($data['isbn_sbn'] && upsertField($pdo, $bibid, 20, 'a', $data['isbn_sbn'])) {
                $inserted[] = 'isbn';
            }
            if ($data['autore'] && insertField($pdo, $bibid, 100, 'a', $data['autore'])) {
                $inserted[] = 'autore_marc';
            }
            if ($data['autore']) {
                $pdo->prepare("UPDATE biblio SET author = ? WHERE bibid = ? AND (author IS NULL OR author = '')")
                    ->execute([$data['autore'], $bibid]);
            }
            if ($data['luogo'] && insertField($pdo, $bibid, 260, 'a', $data['luogo'])) {
                $inserted[] = 'luogo';
            }
            if ($data['editore'] && insertField($pdo, $bibid, 260, 'b', $data['editore'])) {
                $inserted[] = 'editore';
            }
            if ($data['anno'] && insertField($pdo, $bibid, 260, 'c', $data['anno'])) {
                $inserted[] = 'anno';
            }
            if ($data['dewey_code'] && insertField($pdo, $bibid, 82, 'a', $data['dewey_code'])) {
                $inserted[] = 'dewey';
            }
            if ($data['lingua'] && insertField($pdo, $bibid, 41, 'a', $data['lingua'])) {
                $inserted[] = 'lingua';
            }
            if ($data['paese'] && insertField($pdo, $bibid, 44, 'a', $data['paese'])) {
                $inserted[] = 'paese';
            }
            if ($data['collezione'] && insertField($pdo, $bibid, 490, 'a', $data['collezione'])) {
                $inserted[] = 'collezione';
            }
            if ($data['titolo_uniforme'] && insertField($pdo, $bibid, 240, 'a', $data['titolo_uniforme'])) {
                $inserted[] = 'titolo_uniforme';
            }
            if ($data['note'] && insertField($pdo, $bibid, 500, 'a', $data['note'])) {
                $inserted[] = 'note';
            }
            if ($data['abstract'] && insertField($pdo, $bibid, 520, 'a', $data['abstract'])) {
                $inserted[] = 'abstract';
            }
            if ($data['indice'] && insertField($pdo, $bibid, 505, 'a', $data['indice'])) {
                $inserted[] = 'indice';
            }
            if ($data['bibliografia'] && insertField($pdo, $bibid, 504, 'a', $data['bibliografia'])) {
                $inserted[] = 'bibliografia';
            }

            $soggettiCount = insertFields($pdo, $bibid, 650, 'a', $data['soggetti']);
            if ($soggettiCount > 0) $inserted[] = "soggetti({$soggettiCount})";

            // FIX: popola topic1..5 anche in run_batch
            updateTopics($pdo, $bibid, $data['soggetti'] ?? []);

            $savedCount += count($inserted) > 0 ? 1 : 0;

            $results[] = [
                'bibid'     => $bibid,
                'title'     => mb_strimwidth($title, 0, 60, '…'),
                'isbn'      => $isbn,
                'status'    => count($inserted) > 0 ? 'ok' : 'no_new_data',
                'reason'    => count($inserted) > 0 ? 'enriched' : 'already_complete',
                'detail'    => count($inserted) > 0 
                    ? 'Inseriti: ' . implode(', ', $inserted) 
                    : 'Tutti i campi erano già presenti.',
                'inserted'  => $inserted,
                'bid_sbn'   => $data['bid_sbn'],
                'sbn_link'  => $data['bid_sbn'] ? SbnClient::opacLink($data['bid_sbn']) : null,
            ];
        }

        $remaining = (int)$pdo->query("
            SELECT COUNT(*) FROM (
                SELECT b.bibid
                FROM biblio b
                JOIN biblio_field isbn_f 
                    ON isbn_f.bibid = b.bibid 
                    AND isbn_f.tag = 20 
                    AND isbn_f.subfield_cd = 'a'
                    AND isbn_f.field_data IS NOT NULL
                    AND isbn_f.field_data != ''
                WHERE NOT EXISTS (
                    SELECT 1 FROM biblio_field bid_f
                    WHERE bid_f.bibid = b.bibid
                      AND bid_f.tag = 901
                      AND bid_f.subfield_cd = 'a'
                      AND SUBSTRING(bid_f.field_data, 1, 7) = 'IT\\\\ICCU'
                )
                GROUP BY b.bibid
            ) t
        ")->fetchColumn();

        echo json_encode([
            'ok'        => true,
            'total'     => count($results),
            'saved'     => $savedCount,
            'results'   => $results,
            'remaining' => $remaining,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        error_log('SBN run_batch error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ================================================================
 * Azione: enrich_single
 * ================================================================ */
if ($action === 'enrich_single') {
    $bibid = (int)($_GET['bibid'] ?? 0);
    if ($bibid <= 0) {
        echo json_encode(['ok' => false, 'error' => 'BIBID mancante']);
        exit;
    }

    try {
        $pdo = DB::conn();

        $st = $pdo->prepare("
            SELECT b.bibid, b.title, b.author, isbn_f.field_data AS isbn
            FROM biblio b
            LEFT JOIN biblio_field isbn_f 
                ON isbn_f.bibid = b.bibid 
                AND isbn_f.tag = 20 
                AND isbn_f.subfield_cd = 'a'
            WHERE b.bibid = ?
            LIMIT 1
        ");
        $st->execute([$bibid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'Record non trovato']);
            exit;
        }

        $isbn = $row['isbn'] ?? '';
        if ($isbn === '') {
            echo json_encode(['ok' => false, 'error' => 'ISBN mancante nel record']);
            exit;
        }

        $enriched = $client->enrich([
            'bibid'  => $row['bibid'],
            'titolo' => $row['title'],
            'isbn'   => $isbn,
        ]);

        if (!empty($enriched['_sbn_error'])) {
            echo json_encode([
                'ok'     => false,
                'error'  => 'SBN: ' . $enriched['_sbn_error'],
                'detail' => 'ISBN ' . $isbn . ' — ' . (
                    $enriched['_sbn_error'] === 'not_found'
                        ? 'non trovato nel catalogo SBN'
                        : 'errore di comunicazione con SBN'
                ),
            ]);
            exit;
        }

        $currentAuthor = $row['author'] ?? '';
        $data = $client->extractFullData($enriched['_sbn_enriched'] ? $enriched : null);
        $inserted = [];

        if (!empty($data['bid_sbn'])) {
            if (insertField($pdo, $bibid, 901, 'a', $data['bid_sbn'])) $inserted[] = 'bid_sbn';
        }
        if (!empty($data['isbn_sbn'])) {
            if (upsertField($pdo, $bibid, 20, 'a', $data['isbn_sbn'])) $inserted[] = 'isbn';
        }
        if (!empty($data['autore'])) {
            if (insertField($pdo, $bibid, 100, 'a', $data['autore'])) $inserted[] = 'autore_marc';
            $pdo->prepare("UPDATE biblio SET author = ? WHERE bibid = ? AND (author IS NULL OR author = '')")
                ->execute([$data['autore'], $bibid]);
        }
        if (!empty($data['luogo'])) {
            if (insertField($pdo, $bibid, 260, 'a', $data['luogo'])) $inserted[] = 'luogo';
        }
        if (!empty($data['editore'])) {
            if (insertField($pdo, $bibid, 260, 'b', $data['editore'])) $inserted[] = 'editore';
        }
        if (!empty($data['anno'])) {
            if (insertField($pdo, $bibid, 260, 'c', $data['anno'])) $inserted[] = 'anno';
        }
        if (!empty($data['dewey_code'])) {
            if (insertField($pdo, $bibid, 82, 'a', $data['dewey_code'])) $inserted[] = 'dewey';
        }
        if (!empty($data['lingua'])) {
            if (insertField($pdo, $bibid, 41, 'a', $data['lingua'])) $inserted[] = 'lingua';
        }
        if (!empty($data['paese'])) {
            if (insertField($pdo, $bibid, 44, 'a', $data['paese'])) $inserted[] = 'paese';
        }
        if (!empty($data['collezione'])) {
            if (insertField($pdo, $bibid, 490, 'a', $data['collezione'])) $inserted[] = 'collezione';
        }
        if (!empty($data['titolo_uniforme'])) {
            if (insertField($pdo, $bibid, 240, 'a', $data['titolo_uniforme'])) $inserted[] = 'titolo_uniforme';
        }
        if (!empty($data['note'])) {
            if (insertField($pdo, $bibid, 500, 'a', $data['note'])) $inserted[] = 'note';
        }
        if (!empty($data['abstract'])) {
            if (insertField($pdo, $bibid, 520, 'a', $data['abstract'])) $inserted[] = 'abstract';
        }
        if (!empty($data['indice'])) {
            if (insertField($pdo, $bibid, 505, 'a', $data['indice'])) $inserted[] = 'indice';
        }
        if (!empty($data['bibliografia'])) {
            if (insertField($pdo, $bibid, 504, 'a', $data['bibliografia'])) $inserted[] = 'bibliografia';
        }

        $soggettiCount = insertFields($pdo, $bibid, 650, 'a', $data['soggetti'] ?? []);
        if ($soggettiCount > 0) $inserted[] = "soggetti({$soggettiCount})";

        // FIX: popola topic1..5 anche in enrich_single
        updateTopics($pdo, $bibid, $data['soggetti'] ?? []);

        echo json_encode([
            'ok'       => true,
            'bibid'    => $bibid,
            'original' => [
                'titolo'  => $row['title'],
                'isbn'    => $isbn,
                'autore'  => $currentAuthor,
            ],
            'enriched' => [
                'titolo'          => $data['titolo'] ?? null,
                'autore'          => $data['autore'] ?? null,
                'editore'         => $data['editore'] ?? null,
                'luogo'           => $data['luogo'] ?? null,
                'anno'            => $data['anno'] ?? null,
                'bid_sbn'         => $data['bid_sbn'] ?? null,
                'isbn_sbn'        => $data['isbn_sbn'] ?? null,
                'dewey_code'      => $data['dewey_code'] ?? null,
                'lingua'          => $data['lingua'] ?? null,
                'paese'           => $data['paese'] ?? null,
                'collezione'      => $data['collezione'] ?? null,
                'titolo_uniforme' => $data['titolo_uniforme'] ?? null,
                'soggetti'        => $data['soggetti'] ?? [],
            ],
            'inserted'  => $inserted,
            'sbn_link'  => !empty($data['bid_sbn']) ? SbnClient::opacLink($data['bid_sbn']) : null,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        error_log('SBN enrich_single error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ================================================================
 * Azione: stats
 * ================================================================ */
if ($action === 'stats') {
    try {
        $pdo = DB::conn();

        $total = (int)$pdo->query("SELECT COUNT(*) FROM biblio")->fetchColumn();

        $withIsbn = (int)$pdo->query("
            SELECT COUNT(DISTINCT bibid) FROM biblio_field 
            WHERE tag = 20 AND subfield_cd = 'a' 
            AND field_data IS NOT NULL AND field_data != ''
        ")->fetchColumn();

        $withBidSbn = (int)$pdo->query("
            SELECT COUNT(DISTINCT bibid) FROM biblio_field 
            WHERE tag = 901 AND subfield_cd = 'a' 
            AND SUBSTRING(field_data, 1, 7) = 'IT\\\\ICCU'
        ")->fetchColumn();

        $toEnrich = (int)$pdo->query("
            SELECT COUNT(*) FROM (
                SELECT b.bibid
                FROM biblio b
                JOIN biblio_field isbn_f 
                    ON isbn_f.bibid = b.bibid 
                    AND isbn_f.tag = 20 
                    AND isbn_f.subfield_cd = 'a'
                    AND isbn_f.field_data IS NOT NULL
                    AND isbn_f.field_data != ''
                WHERE NOT EXISTS (
                    SELECT 1 FROM biblio_field bid_f
                    WHERE bid_f.bibid = b.bibid
                      AND bid_f.tag = 901
                      AND bid_f.subfield_cd = 'a'
                      AND SUBSTRING(bid_f.field_data, 1, 7) = 'IT\\\\ICCU'
                )
                GROUP BY b.bibid
            ) t
        ")->fetchColumn();

        echo json_encode([
            'ok'           => true,
            'total'        => $total,
            'with_isbn'    => $withIsbn,
            'without_isbn' => $total - $withIsbn,
            'with_bid_sbn' => $withBidSbn,
            'to_enrich'    => $toEnrich,
        ]);

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ================================================================
 * Azione: stats_no_bid
 * ================================================================ */
if ($action === 'stats_no_bid') {
    try {
        $pdo = DB::conn();
        $total = (int)$pdo->query("
            SELECT COUNT(*) FROM biblio b
            WHERE NOT EXISTS (
                SELECT 1 FROM biblio_field bf
                WHERE bf.bibid = b.bibid
                  AND bf.tag = 901
                  AND bf.subfield_cd = 'a'
                  AND SUBSTRING(bf.field_data, 1, 7) = 'IT\\\\ICCU'
            )
        ")->fetchColumn();
        echo json_encode(['ok' => true, 'total' => $total]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ================================================================
 * Azione: list_no_bid
 * ================================================================ */
if ($action === 'list_no_bid') {
    $limit  = max(1, min(50, (int)($_GET['limit'] ?? 10)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    try {
        $pdo = DB::conn();
        $st = $pdo->prepare("
            SELECT b.bibid, b.title, b.author, isbn_f.field_data AS isbn
            FROM biblio b
            LEFT JOIN biblio_field isbn_f 
                ON isbn_f.bibid = b.bibid 
                AND isbn_f.tag = 20 
                AND isbn_f.subfield_cd = 'a'
            WHERE NOT EXISTS (
                SELECT 1 FROM biblio_field bf
                WHERE bf.bibid = b.bibid
                  AND bf.tag = 901
                  AND bf.subfield_cd = 'a'
                  AND SUBSTRING(bf.field_data, 1, 7) = 'IT\\\\ICCU'
            )
            ORDER BY b.bibid ASC
            LIMIT ? OFFSET ?
        ");
        $st->execute([$limit, $offset]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'   => true,
            'rows' => $rows,
            'total'=> count($rows),
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ================================================================
 * Azione: enrich_by_bid
 * ================================================================ */
if ($action === 'enrich_by_bid') {
    $bibid = (int)($_GET['bibid'] ?? 0);
    $bid   = trim($_GET['bid'] ?? '');
    $bid   = str_replace(['IT\\ICCU\\', '\\'], '', $bid);

    if ($bibid <= 0 || $bid === '') {
        echo json_encode(['ok' => false, 'error' => 'BIBID o BID mancante']);
        exit;
    }

    try {
        $pdo = DB::conn();

        $check = $pdo->prepare("
            SELECT 1 FROM biblio_field
            WHERE bibid = ? AND tag = 901 AND subfield_cd = 'a' AND SUBSTRING(field_data, 1, 7) = 'IT\\\\ICCU'
            LIMIT 1
        ");
        $check->execute([$bibid]);
        if ($check->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Record già arricchito con BID SBN']);
            exit;
        }

        $res = $client->getByBid($bid);
        $doc = $client->extractFirstDoc($res);
        if (!$doc) {
            echo json_encode(['ok' => false, 'error' => 'Record SBN non trovato per BID: ' . $bid]);
            exit;
        }

        $data = $client->extractFullData($doc);
        $inserted = [];

        if ($data['bid_sbn'] && insertField($pdo, $bibid, 901, 'a', $data['bid_sbn'])) {
            $inserted[] = 'bid_sbn';
        }
        if ($data['isbn_sbn'] && upsertField($pdo, $bibid, 20, 'a', $data['isbn_sbn'])) {
            $inserted[] = 'isbn';
        }
        if ($data['autore'] && insertField($pdo, $bibid, 100, 'a', $data['autore'])) {
            $inserted[] = 'autore_marc';
        }
        if ($data['autore']) {
            $pdo->prepare("UPDATE biblio SET author = ? WHERE bibid = ? AND (author IS NULL OR author = '')")
                ->execute([$data['autore'], $bibid]);
        }
        if ($data['titolo']) {
            $pdo->prepare("UPDATE biblio SET title = ? WHERE bibid = ? AND (title IS NULL OR title = '' OR title = '[Senza titolo]')")
                ->execute([$data['titolo'], $bibid]);
        }
        if ($data['luogo'] && insertField($pdo, $bibid, 260, 'a', $data['luogo'])) {
            $inserted[] = 'luogo';
        }
        if ($data['editore'] && insertField($pdo, $bibid, 260, 'b', $data['editore'])) {
            $inserted[] = 'editore';
        }
        if ($data['anno'] && insertField($pdo, $bibid, 260, 'c', $data['anno'])) {
            $inserted[] = 'anno';
        }
        if ($data['dewey_code'] && insertField($pdo, $bibid, 82, 'a', $data['dewey_code'])) {
            $inserted[] = 'dewey';
        }
        if ($data['lingua'] && insertField($pdo, $bibid, 41, 'a', $data['lingua'])) {
            $inserted[] = 'lingua';
        }
        if ($data['paese'] && insertField($pdo, $bibid, 44, 'a', $data['paese'])) {
            $inserted[] = 'paese';
        }
        if ($data['collezione'] && insertField($pdo, $bibid, 490, 'a', $data['collezione'])) {
            $inserted[] = 'collezione';
        }
        if ($data['titolo_uniforme'] && insertField($pdo, $bibid, 240, 'a', $data['titolo_uniforme'])) {
            $inserted[] = 'titolo_uniforme';
        }
        if ($data['note'] && insertField($pdo, $bibid, 500, 'a', $data['note'])) {
            $inserted[] = 'note';
        }
        if ($data['abstract'] && insertField($pdo, $bibid, 520, 'a', $data['abstract'])) {
            $inserted[] = 'abstract';
        }
        if ($data['indice'] && insertField($pdo, $bibid, 505, 'a', $data['indice'])) {
            $inserted[] = 'indice';
        }
        if ($data['bibliografia'] && insertField($pdo, $bibid, 504, 'a', $data['bibliografia'])) {
            $inserted[] = 'bibliografia';
        }
        if ($data['dimensioni'] && insertField($pdo, $bibid, 300, 'a', $data['dimensioni'])) {
            $inserted[] = 'dimensioni';
        }

        $soggettiCount = insertFields($pdo, $bibid, 650, 'a', $data['soggetti']);
        if ($soggettiCount > 0) $inserted[] = "soggetti({$soggettiCount})";

        // FIX: usa updateTopics() centralizzato (non sovrascrive se già popolati)
        updateTopics($pdo, $bibid, $data['soggetti'] ?? []);

        echo json_encode([
            'ok'       => true,
            'bibid'    => $bibid,
            'bid_sbn'  => $data['bid_sbn'],
            'inserted' => $inserted,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ================================================================
 * Azione: enrich_by_data
 * ================================================================ */
if ($action === 'enrich_by_data') {
    $bibid = (int)($_GET['bibid'] ?? 0);

    $input = file_get_contents('php://input');
    $data = [];
    if ($input !== '') {
        $json = json_decode($input, true);
        if (is_array($json)) $data = $json;
    }

    if (!empty($data['bid_sbn'])) {
        $data['bid_sbn'] = str_replace(['IT\\ICCU\\', '\\'], '', $data['bid_sbn']);
    }

    if ($bibid <= 0 || empty($data['bid_sbn'])) {
        echo json_encode(['ok' => false, 'error' => 'BIBID o dati mancanti']);
        exit;
    }

    try {
        $pdo = DB::conn();

        $check = $pdo->prepare("
            SELECT 1 FROM biblio_field
            WHERE bibid = ? AND tag = 901 AND subfield_cd = 'a' AND SUBSTRING(field_data, 1, 7) = 'IT\\\\ICCU'
            LIMIT 1
        ");
        $check->execute([$bibid]);
        if ($check->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Record già arricchito con BID SBN']);
            exit;
        }

        $inserted = [];
        $bid = $data['bid_sbn'];

        if (insertField($pdo, $bibid, 901, 'a', $bid)) $inserted[] = 'bid_sbn';
        if (!empty($data['isbn']) && insertField($pdo, $bibid, 20, 'a', $data['isbn'])) $inserted[] = 'isbn';
        if (!empty($data['autore']) && insertField($pdo, $bibid, 100, 'a', $data['autore'])) {
            $inserted[] = 'autore_marc';
            $pdo->prepare("UPDATE biblio SET author = ? WHERE bibid = ? AND (author IS NULL OR author = '')")
                ->execute([$data['autore'], $bibid]);
        }
        if (!empty($data['editore']) && insertField($pdo, $bibid, 260, 'b', $data['editore'])) $inserted[] = 'editore';
        if (!empty($data['anno']) && insertField($pdo, $bibid, 260, 'c', $data['anno'])) $inserted[] = 'anno';
        if (!empty($data['dewey_code']) && insertField($pdo, $bibid, 82, 'a', $data['dewey_code'])) $inserted[] = 'dewey';
        if (!empty($data['lingua']) && insertField($pdo, $bibid, 41, 'a', $data['lingua'])) $inserted[] = 'lingua';
        if (!empty($data['paese']) && insertField($pdo, $bibid, 44, 'a', $data['paese'])) $inserted[] = 'paese';
        if (!empty($data['collezione']) && insertField($pdo, $bibid, 490, 'a', $data['collezione'])) $inserted[] = 'collezione';
        if (!empty($data['titolo_uniforme']) && insertField($pdo, $bibid, 240, 'a', $data['titolo_uniforme'])) $inserted[] = 'titolo_uniforme';
        if (!empty($data['note']) && insertField($pdo, $bibid, 500, 'a', $data['note'])) $inserted[] = 'note';
        if (!empty($data['abstract']) && insertField($pdo, $bibid, 520, 'a', $data['abstract'])) $inserted[] = 'abstract';
        if (!empty($data['indice']) && insertField($pdo, $bibid, 505, 'a', $data['indice'])) $inserted[] = 'indice';
        if (!empty($data['bibliografia']) && insertField($pdo, $bibid, 504, 'a', $data['bibliografia'])) $inserted[] = 'bibliografia';
        if (!empty($data['dimensioni']) && insertField($pdo, $bibid, 300, 'a', $data['dimensioni'])) $inserted[] = 'dimensioni';

        $soggetti = [];
        if (!empty($data['soggetti'])) {
            $soggetti = is_array($data['soggetti']) ? $data['soggetti'] : [$data['soggetti']];
        }
        $soggettiCount = insertFields($pdo, $bibid, 650, 'a', $soggetti);
        if ($soggettiCount > 0) $inserted[] = "soggetti({$soggettiCount})";

        // FIX: usa updateTopics() centralizzato (non sovrascrive se già popolati)
        updateTopics($pdo, $bibid, $soggetti);

        echo json_encode([
            'ok'       => true,
            'bibid'    => $bibid,
            'bid_sbn'  => $bid,
            'inserted' => $inserted,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ================================================================
 * Azione: import_record_with_data
 * Crea un nuovo record in biblio usando i dati editati dal form
 * (non ri-fetcha da SBN). Accetta JSON via POST.
 * ================================================================ */
if ($action === 'import_record_with_data') {
    $input = file_get_contents('php://input');
    $data  = [];
    if ($input !== '') {
        $json = json_decode($input, true);
        if (is_array($json)) $data = $json;
    }

    // Normalizza BID
    if (!empty($data['bid_sbn'])) {
        $data['bid_sbn'] = str_replace(['IT\\ICCU\\', '\\'], '', $data['bid_sbn']);
    }

    if (empty($data['bid_sbn'])) {
        echo json_encode(['ok' => false, 'error' => 'bid_sbn mancante nei dati']);
        exit;
    }

    $bid = $data['bid_sbn'];

    try {
        $pdo = DB::conn();

        // Controlla duplicato
        $check = $pdo->prepare("
            SELECT bibid FROM biblio_field
            WHERE tag = 901 AND subfield_cd = 'a' AND field_data = ?
            LIMIT 1
        ");
        $check->execute([$bid]);
        if ($row = $check->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Record già presente nel catalogo (BIBID ' . $row['bibid'] . ')']);
            exit;
        }

        $titolo  = trim($data['titolo']  ?? '') ?: '[Senza titolo]';
        $autore  = trim($data['autore']  ?? '');
        $matCd   = trim($data['material_cd']   ?? '1') ?: '1';
        $colCd   = trim($data['collection_cd'] ?? '1') ?: '1';
        $call1   = trim($data['call_nmbr1'] ?? '');
        $call2   = trim($data['call_nmbr2'] ?? '');
        $call3   = trim($data['call_nmbr3'] ?? '');

        // Crea record principale in biblio (con collocazione)
        $ins = $pdo->prepare("
            INSERT INTO biblio (title, author, material_cd, collection_cd, call_nmbr1, call_nmbr2, call_nmbr3, create_dt, last_change_dt, last_change_userid)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
        ");
        $ins->execute([
            $titolo,
            $autore,
            $matCd,
            $colCd,
            $call1 ?: null,
            $call2 ?: null,
            $call3 ?: null,
            $_SESSION['staff_user_id'] ?? 0,
        ]);
        $bibid = (int)$pdo->lastInsertId();

        $inserted = [];

        // Campi MARC da dati form
        if (insertField($pdo, $bibid, 901, 'a', $bid))                        $inserted[] = 'bid_sbn';
        if (!empty($data['isbn']) && insertField($pdo, $bibid, 20,  'a', $data['isbn']))   $inserted[] = 'isbn';
        if ($autore !== '' && insertField($pdo, $bibid, 100, 'a', $autore))    $inserted[] = 'autore_marc';
        if (!empty($data['luogo'])   && insertField($pdo, $bibid, 260, 'a', $data['luogo']))    $inserted[] = 'luogo';
        if (!empty($data['editore']) && insertField($pdo, $bibid, 260, 'b', $data['editore']))  $inserted[] = 'editore';
        if (!empty($data['anno'])    && insertField($pdo, $bibid, 260, 'c', $data['anno']))     $inserted[] = 'anno';
        if (!empty($data['dewey_code'])      && insertField($pdo, $bibid, 82,  'a', $data['dewey_code']))      $inserted[] = 'dewey';
        if (!empty($data['lingua'])          && insertField($pdo, $bibid, 41,  'a', $data['lingua']))          $inserted[] = 'lingua';
        if (!empty($data['paese'])           && insertField($pdo, $bibid, 44,  'a', $data['paese']))           $inserted[] = 'paese';
        if (!empty($data['titolo_uniforme']) && insertField($pdo, $bibid, 240, 'a', $data['titolo_uniforme'])) $inserted[] = 'titolo_uniforme';
        if (!empty($data['collezione'])      && insertField($pdo, $bibid, 490, 'a', $data['collezione']))      $inserted[] = 'collezione';
        if (!empty($data['note'])            && insertField($pdo, $bibid, 500, 'a', $data['note']))            $inserted[] = 'note';
        if (!empty($data['abstract'])        && insertField($pdo, $bibid, 520, 'a', $data['abstract']))        $inserted[] = 'abstract';
        if (!empty($data['indice'])          && insertField($pdo, $bibid, 505, 'a', $data['indice']))          $inserted[] = 'indice';
        if (!empty($data['bibliografia'])    && insertField($pdo, $bibid, 504, 'a', $data['bibliografia']))    $inserted[] = 'bibliografia';
        if (!empty($data['dimensioni'])      && insertField($pdo, $bibid, 300, 'a', $data['dimensioni']))      $inserted[] = 'dimensioni';
        if (!empty($data['illustrazioni'])   && insertField($pdo, $bibid, 300, 'b', $data['illustrazioni']))   $inserted[] = 'illustrazioni';

        // Soggetti: arrivano già come array dal form JS
        $soggetti = [];
        if (!empty($data['soggetti'])) {
            $soggetti = is_array($data['soggetti']) ? $data['soggetti'] : [$data['soggetti']];
            $soggetti = array_filter(array_map('trim', $soggetti), fn($s) => $s !== '');
        }
        $soggettiCount = insertFields($pdo, $bibid, 650, 'a', array_values($soggetti));
        if ($soggettiCount > 0) $inserted[] = "soggetti({$soggettiCount})";

        // Topic denormalizzati
        updateTopics($pdo, $bibid, array_values($soggetti));

        // Collocazione fisica (copia 1) — SEMPRE creata; copyid atomico via AUTO_INCREMENT MySQL
        $barcode = trim($data['barcode']   ?? '');
        $status  = trim($data['status_cd'] ?? 'in') ?: 'in';
        [$copyid, $barcode] = insertCopy($pdo, $bibid, $status, $barcode);
        $inserted[] = 'copia';

        syncIndexExt($pdo, $bibid, (string)($data['isbn'] ?? ''), (string)($data['anno'] ?? ''),
                     (string)($data['editore'] ?? ''), (string)($data['luogo'] ?? ''));

        echo json_encode([
            'ok'       => true,
            'bibid'    => $bibid,
            'copyid'   => $copyid,
            'inserted' => $inserted,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        error_log('SBN import_record_with_data error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Azione non riconosciuta']);
exit;