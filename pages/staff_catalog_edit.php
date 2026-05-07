<?php
/**
 * Area Staff - Modifica record esistente + Gestione Copie avanzata
 *
 * Schema reale anpiudine-or1d94_2:
 * - biblio_copy: MyISAM, PK (bibid, copyid), copyid AUTO_INCREMENT
 * - biblio_status_dm: 9 stati (8, crt, hld, in, ln, lst, mnd, ord, out)
 * - biblio_status_hist: InnoDB, storico movimenti
 * - material_cd / collection_cd: smallint(6)
 *
 * PHP 8.3
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// Protezione
// -----------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    /** @var array<string,mixed> $cfg */
    $baseUrl = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/');
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=staff_catalog_edit');
    exit;
}

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------
/** @var array<string,mixed> $cfg */
$baseUrl  = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/');
$pdo      = DB::conn();
$errors   = [];
$messages = [];
$skipEditLoading = false;

// -----------------------------------------------------------------------------
// Helper: htmlspecialchars wrapper
// -----------------------------------------------------------------------------
if (!function_exists('h')) {
    function h(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// -----------------------------------------------------------------------------
// Liste domini
// -----------------------------------------------------------------------------
$materialList   = [];
$collectionList = [];
$statusList     = [];

try {
    $materialList   = $pdo->query('SELECT code, description FROM material_type_dm ORDER BY description')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $collectionList = $pdo->query('SELECT code, description FROM collection_dm ORDER BY description')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statusList     = $pdo->query('SELECT code, description FROM biblio_status_dm ORDER BY description')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    // silent
}

// Mappa status per label
$statusLabels = [];
foreach ($statusList as $s) {
    $statusLabels[$s['code']] = $s['description'];
}

// Colori stato
$statusColors = [
    'in'  => '#16a34a',
    'out' => '#dc2626',
    'hld' => '#ca8a04',
    'ln'  => '#9333ea',
    'mnd' => '#ea580c',
    'dis' => '#6b7280',
    'lst' => '#7c3aed',
    'ord' => '#0891b2',
    'crt' => '#059669',
    '8'   => '#be123c',
];

// -----------------------------------------------------------------------------
// Helper: validazione barcode unico
// -----------------------------------------------------------------------------
function isBarcodeUnique(PDO $pdo, string $barcode, ?int $excludeCopyId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM biblio_copy WHERE barcode_nmbr = :barcode';
    $params = [':barcode' => $barcode];

    if ($excludeCopyId !== null) {
        $sql .= ' AND copyid != :exclude';
        $params[':exclude'] = $excludeCopyId;
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return (int)$stmt->fetchColumn() === 0;
}

// -----------------------------------------------------------------------------
// Helper: genera barcode deterministico
// -----------------------------------------------------------------------------
function generateSafeBarcode(PDO $pdo, int $copyid): string
{
    $barcode = str_pad((string)$copyid, 7, '0', STR_PAD_LEFT);
    if (!isBarcodeUnique($pdo, $barcode, $copyid)) {
        $barcode = 'C' . str_pad((string)$copyid, 5, '0', STR_PAD_LEFT) . substr(uniqid(), -2);
    }
    return $barcode;
}

// -----------------------------------------------------------------------------
// Helper MARC
// -----------------------------------------------------------------------------
function staff_updateMarcSimpleField(PDO $pdo, int $bibid, int $tag, string $subfield, string $value): void
{
    $value = trim($value);
    try {
        $del = $pdo->prepare('DELETE FROM biblio_field WHERE bibid = :bibid AND tag = :tag AND subfield_cd = :sub');
        $del->execute([':bibid' => $bibid, ':tag' => $tag, ':sub' => $subfield]);

        if ($value === '') return;

        $ins = $pdo->prepare('INSERT INTO biblio_field (bibid, tag, subfield_cd, field_data) VALUES (:bibid, :tag, :sub, :data)');
        $ins->execute([':bibid' => $bibid, ':tag' => $tag, ':sub' => $subfield, ':data' => $value]);
    } catch (PDOException $e) {
        // silent
    }
}

// =============================================================================
// GESTIONE POST
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = trim((string)($_POST['action'] ?? ''));

    // -------------------------------------------------------------------------
    // AGGIUNTA NUOVA COPIA
    // -------------------------------------------------------------------------
    if ($postAction === 'add_copy') {
        $newBibid = (int)($_POST['bibid'] ?? 0);
        $newDesc  = trim((string)($_POST['copy_desc'] ?? ''));
        $manualBarcode = trim((string)($_POST['barcode_manual'] ?? ''));
        $useManual = isset($_POST['use_manual_barcode']) && $manualBarcode !== '';

        if ($newBibid <= 0) {
            $errors[] = 'BibID non valido.';
        } else {
            try {
                if ($useManual) {
                    if (!preg_match('/^[A-Z0-9\-]{3,20}$/i', $manualBarcode)) {
                        throw new RuntimeException('Barcode manuale non valido (3-20 caratteri alfanumerici).');
                    }
                    if (!isBarcodeUnique($pdo, $manualBarcode)) {
                        throw new RuntimeException('Barcode già esistente: ' . $manualBarcode);
                    }
                    $finalBarcode = strtoupper($manualBarcode);
                }

                $nowDt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

                $ins = $pdo->prepare('
                    INSERT INTO biblio_copy (bibid, create_dt, copy_desc, barcode_nmbr, status_cd, status_begin_dt, due_back_dt, mbrid, renewal_count)
                    VALUES (:bibid, :create_dt, :copy_desc, :barcode, :status_cd, :status_begin_dt, NULL, NULL, 0)
                ');
                $ins->execute([
                    ':bibid'           => $newBibid,
                    ':create_dt'        => $nowDt,
                    ':copy_desc'        => $newDesc !== '' ? $newDesc : null,
                    ':barcode'          => $useManual ? $finalBarcode : 'TMP' . uniqid(),
                    ':status_cd'        => 'in',
                    ':status_begin_dt'  => $nowDt,
                ]);

                $newCopyId = (int)$pdo->lastInsertId();
                if ($newCopyId <= 0) {
                    throw new RuntimeException('Impossibile determinare copyid.');
                }

                if (!$useManual) {
                    $finalBarcode = generateSafeBarcode($pdo, $newCopyId);
                    $upd = $pdo->prepare('UPDATE biblio_copy SET barcode_nmbr = :barcode WHERE copyid = :copyid AND bibid = :bibid LIMIT 1');
                    $upd->execute([
                        ':barcode' => $finalBarcode,
                        ':copyid'  => $newCopyId,
                        ':bibid'   => $newBibid,
                    ]);
                }

                $messages[] = 'Copia creata: copyid ' . $newCopyId . ' — barcode ' . $finalBarcode;

                header('Location: ' . $baseUrl . '/index.php?page=staff_catalog_edit&edit_bibid=' . $newBibid . '&new_barcode=' . urlencode($finalBarcode) . '#copies');
                exit;

            } catch (Throwable $e) {
                $errors[] = 'Errore creazione copia: ' . $e->getMessage();
            }
        }
    }

    // -------------------------------------------------------------------------
    // MODIFICA COPIA ESISTENTE
    // -------------------------------------------------------------------------
    if ($postAction === 'update_copy') {
        $bibid      = (int)($_POST['bibid'] ?? 0);
        $copyid     = (int)($_POST['copyid'] ?? 0);
        $newBarcode = trim((string)($_POST['barcode_nmbr'] ?? ''));
        $newDesc    = trim((string)($_POST['copy_desc'] ?? ''));
        $newStatus  = trim((string)($_POST['status_cd'] ?? ''));

        if ($bibid <= 0 || $copyid <= 0) {
            $errors[] = 'Parametri non validi.';
        } elseif ($newBarcode === '' || !preg_match('/^[A-Z0-9\-]{3,20}$/i', $newBarcode)) {
            $errors[] = 'Barcode non valido.';
        } elseif (!isBarcodeUnique($pdo, $newBarcode, $copyid)) {
            $errors[] = 'Barcode già in uso.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT status_cd, mbrid FROM biblio_copy WHERE copyid = :copyid AND bibid = :bibid');
                $stmt->execute([':copyid' => $copyid, ':bibid' => $bibid]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($current === false) {
                    $errors[] = 'Copia non trovata.';
                } elseif ($current['mbrid'] !== null && $newStatus !== 'out') {
                    $errors[] = 'Copia in prestito: usa il modulo prestiti per restituirla.';
                } else {
                    $upd = $pdo->prepare('
                        UPDATE biblio_copy
                        SET barcode_nmbr = :barcode, copy_desc = :desc, status_cd = :status, status_begin_dt = NOW()
                        WHERE copyid = :copyid AND bibid = :bibid LIMIT 1
                    ');
                    $upd->execute([
                        ':barcode' => strtoupper($newBarcode),
                        ':desc'    => $newDesc !== '' ? $newDesc : null,
                        ':status'  => $newStatus,
                        ':copyid'  => $copyid,
                        ':bibid'   => $bibid,
                    ]);
                    $messages[] = 'Copia #' . $copyid . ' aggiornata.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Errore aggiornamento copia.';
            }
        }
        $_GET['edit_bibid'] = (string)$bibid;
    }

    // -------------------------------------------------------------------------
    // SCARTO LOGICO (soft delete)
    // -------------------------------------------------------------------------
    if ($postAction === 'discard_copy') {
        $bibid  = (int)($_POST['bibid'] ?? 0);
        $copyid = (int)($_POST['copyid'] ?? 0);

        if ($bibid > 0 && $copyid > 0) {
            try {
                $stmt = $pdo->prepare('SELECT mbrid FROM biblio_copy WHERE bibid = :bibid AND copyid = :copyid');
                $stmt->execute([':bibid' => $bibid, ':copyid' => $copyid]);
                $mbrid = $stmt->fetchColumn();

                if ($mbrid === false) {
                    $errors[] = 'Copia non trovata.';
                } elseif ($mbrid !== null) {
                    $errors[] = 'Copia in prestito: restituirla prima di scartarla.';
                } else {
                    $upd = $pdo->prepare('
                        UPDATE biblio_copy
                        SET status_cd = \'dis\', status_begin_dt = NOW(), due_back_dt = NULL
                        WHERE bibid = :bibid AND copyid = :copyid LIMIT 1
                    ');
                    $upd->execute([':bibid' => $bibid, ':copyid' => $copyid]);
                    $messages[] = 'Copia #' . $copyid . ' scartata.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Errore scarto.';
            }
        }
        $_GET['edit_bibid'] = (string)$bibid;
    }

    // -------------------------------------------------------------------------
    // ELIMINAZIONE FISICA (solo se mai prestata e non in prestito)
    // -------------------------------------------------------------------------
    if ($postAction === 'force_delete_copy') {
        $bibid  = (int)($_POST['bibid'] ?? 0);
        $copyid = (int)($_POST['copyid'] ?? 0);

        if ($bibid > 0 && $copyid > 0) {
            try {
                $stmt = $pdo->prepare('SELECT mbrid FROM biblio_copy WHERE bibid = :bibid AND copyid = :copyid');
                $stmt->execute([':bibid' => $bibid, ':copyid' => $copyid]);
                $mbrid = $stmt->fetchColumn();

                if ($mbrid === false) {
                    $errors[] = 'Copia non trovata.';
                } elseif ($mbrid !== null) {
                    $errors[] = 'Copia in prestito.';
                } else {
                    // Verifica storico prestiti
                    $histStmt = $pdo->prepare("SELECT COUNT(*) FROM biblio_status_hist WHERE copyid = :copyid AND status_cd IN ('out', 'ln')");
                    $histStmt->execute([':copyid' => $copyid]);
                    $loanCount = (int)$histStmt->fetchColumn();

                    if ($loanCount > 0) {
                        $errors[] = 'Copia con storico prestiti: usa Scarta.';
                    } else {
                        $pdo->prepare('DELETE FROM biblio_copy_fields WHERE bibid = :bibid AND copyid = :copyid')
                            ->execute([':bibid' => $bibid, ':copyid' => $copyid]);

                        $pdo->prepare('DELETE FROM biblio_copy WHERE bibid = :bibid AND copyid = :copyid LIMIT 1')
                            ->execute([':bibid' => $bibid, ':copyid' => $copyid]);

                        $messages[] = 'Copia #' . $copyid . ' eliminata.';
                    }
                }
            } catch (Throwable $e) {
                $errors[] = 'Errore eliminazione.';
            }
        }
        $_GET['edit_bibid'] = (string)$bibid;
    }

    // -------------------------------------------------------------------------
    // UPDATE RECORD
    // -------------------------------------------------------------------------
    if ($postAction === 'update') {
        $upBibid = (int)($_POST['bibid'] ?? 0);

        if ($upBibid <= 0) {
            $errors[] = 'Record non valido.';
        } elseif (trim((string)($_POST['title'] ?? '')) === '') {
            $errors[] = 'Il titolo non può essere vuoto.';
        } elseif ((int)($_POST['material_cd'] ?? 0) <= 0) {
            $errors[] = 'Seleziona un Tipo di materiale valido.';
        } elseif ((int)($_POST['collection_cd'] ?? 0) <= 0) {
            $errors[] = 'Seleziona una Collezione valida.';
        } else {
            try {
                $stmt = $pdo->prepare('
                    UPDATE biblio
                    SET title = :title, title_remainder = :tr, responsibility_stmt = :resp,
                        author = :author, call_nmbr1 = :c1, call_nmbr2 = :c2, call_nmbr3 = :c3,
                        material_cd = :mat, collection_cd = :coll,
                        topic1 = :t1, topic2 = :t2, topic3 = :t3, topic4 = :t4, topic5 = :t5,
                        last_change_dt = NOW(), last_change_userid = :userid
                    WHERE bibid = :bibid LIMIT 1
                ');
                $stmt->execute([
                    ':title' => trim((string)($_POST['title'] ?? '')),
                    ':tr'    => trim((string)($_POST['title_remainder'] ?? '')),
                    ':resp'  => trim((string)($_POST['responsibility_stmt'] ?? '')),
                    ':author'=> trim((string)($_POST['author'] ?? '')),
                    ':c1'    => trim((string)($_POST['call_nmbr1'] ?? '')),
                    ':c2'    => trim((string)($_POST['call_nmbr2'] ?? '')),
                    ':c3'    => trim((string)($_POST['call_nmbr3'] ?? '')),
                    ':mat'   => (int)($_POST['material_cd'] ?? 0),
                    ':coll'  => (int)($_POST['collection_cd'] ?? 0),
                    ':t1'    => trim((string)($_POST['topic1'] ?? '')),
                    ':t2'    => trim((string)($_POST['topic2'] ?? '')),
                    ':t3'    => trim((string)($_POST['topic3'] ?? '')),
                    ':t4'    => trim((string)($_POST['topic4'] ?? '')),
                    ':t5'    => trim((string)($_POST['topic5'] ?? '')),
                    ':userid'=> (int)$_SESSION['staff_user_id'],
                    ':bibid' => $upBibid,
                ]);

                staff_updateMarcSimpleField($pdo, $upBibid, 20,  'a', trim((string)($_POST['isbn'] ?? '')));
                staff_updateMarcSimpleField($pdo, $upBibid, 260, 'b', trim((string)($_POST['publisher'] ?? '')));
                staff_updateMarcSimpleField($pdo, $upBibid, 260, 'c', trim((string)($_POST['pub_year'] ?? '')));
                staff_updateMarcSimpleField($pdo, $upBibid, 300, 'a', trim((string)($_POST['pages'] ?? '')));
                staff_updateMarcSimpleField($pdo, $upBibid, 520, 'a', trim((string)($_POST['summary'] ?? '')));
                staff_updateMarcSimpleField($pdo, $upBibid, 500, 'a', trim((string)($_POST['notes'] ?? '')));

                $messages[] = 'Record aggiornato.';
            } catch (PDOException $e) {
                $errors[] = 'Errore salvataggio.';
            }
        }
        if (!$skipEditLoading) {
            $_GET['edit_bibid'] = (string)$upBibid;
        }
    }

    // -------------------------------------------------------------------------
    // DELETE RECORD
    // -------------------------------------------------------------------------
    if ($postAction === 'delete') {
        $delBibid = (int)($_POST['bibid'] ?? 0);
        if ($delBibid > 0) {
            try {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM biblio_copy WHERE bibid = :bibid');
                $stmt->execute([':bibid' => $delBibid]);
                $copyCount = (int)$stmt->fetchColumn();

                if ($copyCount > 0) {
                    $errors[] = 'Elimina prima tutte le ' . $copyCount . ' copie collegate.';
                } else {
                    $pdo->prepare('DELETE FROM biblio_field WHERE bibid = :bibid')->execute([':bibid' => $delBibid]);
                    $pdo->prepare('DELETE FROM biblio WHERE bibid = :bibid LIMIT 1')->execute([':bibid' => $delBibid]);
                    $messages[] = 'Record #' . $delBibid . ' eliminato.';
                    $skipEditLoading = true;
                    $_GET['edit_bibid'] = '';
                }
            } catch (Throwable $e) {
                $errors[] = 'Errore eliminazione record.';
            }
        }
    }
}

// =============================================================================
// LETTURA GET
// =============================================================================

$isSearchRequest = isset($_GET['do_search']);
$bibidStr      = trim((string)($_GET['bibid'] ?? ''));
$isbnStr       = trim((string)($_GET['isbn'] ?? ''));
$titleStr      = trim((string)($_GET['title'] ?? ''));
$editBibidStr  = trim((string)($_GET['edit_bibid'] ?? ''));

$bibidFilter = ($bibidStr !== '' && ctype_digit($bibidStr)) ? (int)$bibidStr : 0;
$editBibid   = ($editBibidStr !== '' && ctype_digit($editBibidStr)) ? (int)$editBibidStr : 0;

// -----------------------------------------------------------------------------
// Ricerca
// -----------------------------------------------------------------------------
$results = [];

if ($isSearchRequest) {
    if ($bibidFilter === 0 && $isbnStr === '' && $titleStr === '') {
        $errors[] = 'Inserisci almeno un criterio di ricerca.';
    } else {
        try {
            if ($bibidFilter > 0) {
                $stmt = $pdo->prepare('SELECT bibid, title, author FROM biblio WHERE bibid = :bibid LIMIT 1');
                $stmt->execute([':bibid' => $bibidFilter]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                $whereParts = [];
                $params = [];

                if ($titleStr !== '') {
                    $whereParts[] = 'b.title LIKE :title';
                    $params[':title'] = '%' . $titleStr . '%';
                }
                if ($isbnStr !== '') {
                    $whereParts[] = 'bf.field_data LIKE :isbn';
                    $params[':isbn'] = '%' . $isbnStr . '%';
                }

                $sql = 'SELECT DISTINCT b.bibid, b.title, b.author FROM biblio b';
                if (isset($params[':isbn'])) {
                    $sql .= ' JOIN biblio_field bf ON bf.bibid = b.bibid AND bf.tag = 20 AND bf.subfield_cd = \'a\'';
                }
                $sql .= ' WHERE ' . implode(' AND ', $whereParts) . ' ORDER BY b.bibid DESC LIMIT 50';

                $stmt = $pdo->prepare($sql);
                foreach ($params as $k => $v) {
                    $stmt->bindValue($k, $v, PDO::PARAM_STR);
                }
                $stmt->execute();
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (PDOException $e) {
            $errors[] = 'Errore durante la ricerca.';
        }
    }
}

// -----------------------------------------------------------------------------
// Caricamento record
// -----------------------------------------------------------------------------
$editRecord    = null;
$editCopyCount = 0;
$editCopies    = [];

if (!$skipEditLoading && $editBibid > 0) {
    try {
        $sql = '
            SELECT b.*,
                (SELECT bf1.field_data FROM biblio_field bf1 WHERE bf1.bibid = b.bibid AND bf1.tag IN (260,264) AND bf1.subfield_cd = \'b\' ORDER BY bf1.fieldid LIMIT 1) AS publisher,
                (SELECT bf2.field_data FROM biblio_field bf2 WHERE bf2.bibid = b.bibid AND bf2.tag IN (260,264) AND bf2.subfield_cd = \'c\' ORDER BY bf2.fieldid LIMIT 1) AS pub_year,
                (SELECT bf3.field_data FROM biblio_field bf3 WHERE bf3.bibid = b.bibid AND bf3.tag = 300 AND bf3.subfield_cd = \'a\' ORDER BY bf3.fieldid LIMIT 1) AS pages,
                (SELECT bf4.field_data FROM biblio_field bf4 WHERE bf4.bibid = b.bibid AND bf4.tag = 520 AND bf4.subfield_cd = \'a\' ORDER BY bf4.fieldid LIMIT 1) AS summary_520,
                (SELECT bf5.field_data FROM biblio_field bf5 WHERE bf5.bibid = b.bibid AND bf5.tag = 500 AND bf5.subfield_cd = \'a\' ORDER BY bf5.fieldid LIMIT 1) AS notes_500,
                (SELECT bf6.field_data FROM biblio_field bf6 WHERE bf6.bibid = b.bibid AND bf6.tag = 20 AND bf6.subfield_cd = \'a\' ORDER BY bf6.fieldid LIMIT 1) AS isbn
            FROM biblio b
            WHERE b.bibid = :bibid
            LIMIT 1
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':bibid' => $editBibid]);
        $editRecord = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($editRecord) {
            $stmt2 = $pdo->prepare('SELECT COUNT(*) FROM biblio_copy WHERE bibid = :bibid AND status_cd NOT IN (\'lst\', \'dis\')');
            $stmt2->execute([':bibid' => $editBibid]);
            $editCopyCount = (int)$stmt2->fetchColumn();

            $stmt3 = $pdo->prepare('
                SELECT c.bibid, c.copyid, c.barcode_nmbr, c.status_cd, c.copy_desc, c.due_back_dt,
                       c.mbrid, m.first_name, m.last_name
                FROM biblio_copy c
                LEFT JOIN member m ON c.mbrid = m.mbrid
                WHERE c.bibid = :bibid
                ORDER BY c.copyid
            ');
            $stmt3->execute([':bibid' => $editBibid]);
            $editCopies = $stmt3->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $errors[] = 'Record non trovato.';
        }
    } catch (PDOException $e) {
        $errors[] = 'Errore caricamento record.';
    }
}
?>
<section class="page-section page-staff-catalog-edit">
    <header class="staff-header">
        <h1>Modifica record esistente</h1>
        <p class="staff-header-subtitle">Cerca per bibid, ISBN o titolo</p>
    </header>

    <?php if (!empty($messages)): ?>
    <div class="alert--success">
        <?php foreach ($messages as $msg): ?><p><?= h($msg) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert--error">
        <?php foreach ($errors as $msg): ?><p><?= h($msg) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- RICERCA -->
    <section class="staff-block staff-block--search">
        <h2>Ricerca record</h2>
        <form method="get" action="<?= h($baseUrl) ?>/index.php" class="staff-search-form">
            <input type="hidden" name="page" value="staff_catalog_edit">
            <input type="hidden" name="do_search" value="1">

            <div class="search-row">
                <label for="s-bibid">bibid</label>
                <input type="text" id="s-bibid" name="bibid" value="<?= h($bibidStr) ?>" placeholder="es. 1234" inputmode="numeric">
            </div>
            <div class="search-row">
                <label for="s-isbn">ISBN</label>
                <input type="text" id="s-isbn" name="isbn" value="<?= h($isbnStr) ?>" placeholder="es. 97888...">
            </div>
            <div class="search-row">
                <label for="s-title">Titolo (parziale)</label>
                <input type="text" id="s-title" name="title" value="<?= h($titleStr) ?>" placeholder="Una o più parole del titolo">
            </div>
            <div class="search-actions">
                <button type="submit" class="btn-primary">Cerca</button>
                <a class="btn-secondary" href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_edit">Pulisci</a>
                <a class="btn-link" href="<?= h($baseUrl) ?>/index.php?page=staff">Dashboard</a>
            </div>
        </form>
    </section>

    <!-- RISULTATI -->
    <?php if ($isSearchRequest): ?>
    <section class="staff-block staff-block--results">
        <h2>Risultati</h2>
        <?php if (empty($results)): ?>
            <p>Nessun record trovato.</p>
        <?php else: ?>
            <ul class="result-list">
            <?php foreach ($results as $row):
                $rbib = (int)$row['bibid'];
                $rtitle = trim((string)($row['title'] ?? '')) ?: '[Senza titolo]';
            ?>
                <li class="staff-result-item">
                    <div class="result-title"><strong>#<?= $rbib ?></strong> — <?= h($rtitle) ?></div>
                    <?php if (!empty($row['author'])): ?>
                    <div class="result-author"><?= h($row['author']) ?></div>
                    <?php endif; ?>
                    <a class="btn-link" href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_edit&edit_bibid=<?= $rbib ?>">Modifica</a>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- MODIFICA RECORD -->
    <?php if ($editRecord):
        $ebib = (int)$editRecord['bibid'];
    ?>
    <section class="staff-block staff-block--edit">
        <h2>Modifica record #<?= $ebib ?></h2>

        <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_catalog_edit&edit_bibid=<?= $ebib ?>" class="staff-edit-form">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="bibid" value="<?= $ebib ?>">

            <div class="search-row">
                <label>bibid</label>
                <input type="text" value="<?= $ebib ?>" disabled>
            </div>
            <div class="search-row">
                <label for="e-title">Titolo *</label>
                <input type="text" id="e-title" name="title" value="<?= h(trim((string)($editRecord['title'] ?? ''))) ?>" required>
            </div>
            <div class="search-row">
                <label for="e-title-rem">Complemento titolo</label>
                <input type="text" id="e-title-rem" name="title_remainder" value="<?= h(trim((string)($editRecord['title_remainder'] ?? ''))) ?>">
            </div>
            <div class="search-row">
                <label for="e-resp">Responsabilità</label>
                <input type="text" id="e-resp" name="responsibility_stmt" value="<?= h(trim((string)($editRecord['responsibility_stmt'] ?? ''))) ?>">
            </div>
            <div class="search-row">
                <label for="e-author">Autore</label>
                <input type="text" id="e-author" name="author" value="<?= h(trim((string)($editRecord['author'] ?? ''))) ?>">
            </div>

            <div class="search-row search-row-inline">
                <div style="flex:1 1 0%;"><label>Collocazione 1</label><input type="text" name="call_nmbr1" value="<?= h(trim((string)($editRecord['call_nmbr1'] ?? ''))) ?>"></div>
                <div style="flex:1 1 0%;"><label>Collocazione 2</label><input type="text" name="call_nmbr2" value="<?= h(trim((string)($editRecord['call_nmbr2'] ?? ''))) ?>"></div>
                <div style="flex:1 1 0%;"><label>Collocazione 3</label><input type="text" name="call_nmbr3" value="<?= h(trim((string)($editRecord['call_nmbr3'] ?? ''))) ?>"></div>
            </div>

            <div class="search-row search-row-inline">
                <div style="flex:1 1 0%;">
                    <label>Tipo materiale</label>
                    <select name="material_cd">
                        <option value="">--</option>
                        <?php foreach ($materialList as $mat):
                            $mCode = (string)$mat['code'];
                            $sel = ($mCode === (string)($editRecord['material_cd'] ?? '')) ? 'selected' : '';
                        ?>
                        <option value="<?= h($mCode) ?>" <?= $sel ?>><?= h($mat['description'] ?? $mCode) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1 1 0%;">
                    <label>Collezione</label>
                    <select name="collection_cd">
                        <option value="">--</option>
                        <?php foreach ($collectionList as $coll):
                            $cCode = (string)$coll['code'];
                            $sel = ($cCode === (string)($editRecord['collection_cd'] ?? '')) ? 'selected' : '';
                        ?>
                        <option value="<?= h($cCode) ?>" <?= $sel ?>><?= h($coll['description'] ?? $cCode) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="search-row search-row-inline">
                <div style="flex:2 1 0%;"><label>Editore (260/264 $b)</label><input type="text" name="publisher" value="<?= h(trim((string)($editRecord['publisher'] ?? ''))) ?>" placeholder="Es. Einaudi"></div>
                <div style="flex:1 1 0%;"><label>Anno (260/264 $c)</label><input type="text" name="pub_year" value="<?= h(trim((string)($editRecord['pub_year'] ?? ''))) ?>" placeholder="Es. 1995"></div>
            </div>
            <div class="search-row"><label>Pagine (300 $a)</label><input type="text" name="pages" value="<?= h(trim((string)($editRecord['pages'] ?? ''))) ?>" placeholder="Es. 320 pagine, ill."></div>
            <div class="search-row"><label>ISBN (020 $a)</label><input type="text" name="isbn" value="<?= h(trim((string)($editRecord['isbn'] ?? ''))) ?>" placeholder="Es. 9788800000000"></div>
            <div class="search-row"><label>Riassunto (520 $a)</label><textarea name="summary" rows="3"><?= h(trim((string)($editRecord['summary_520'] ?? ''))) ?></textarea></div>
            <div class="search-row"><label>Note (500 $a)</label><textarea name="notes" rows="3"><?= h(trim((string)($editRecord['notes_500'] ?? ''))) ?></textarea></div>

            <?php for ($i = 1; $i <= 5; $i++): ?>
            <div class="search-row">
                <label>Soggetto <?= $i ?></label>
                <input type="text" name="topic<?= $i ?>" value="<?= h(trim((string)($editRecord['topic' . $i] ?? ''))) ?>">
            </div>
            <?php endfor; ?>

            <div class="search-actions" style="margin-top:0.9rem;">
                <button type="submit" class="btn-primary">Salva modifiche</button>
                <a class="btn-secondary" href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_edit&edit_bibid=<?= $ebib ?>">Annulla</a>
            </div>
        </form>

        <!-- COPIE -->
        <div id="copies" class="staff-copy-section" style="margin-top:2rem;">
            <h3>Copie collegate</h3>

            <?php if (!empty($_GET['new_barcode'])): ?>
            <div class="copy-new-barcode">
                <div class="copy-new-barcode-label">Nuova copia creata — Barcode:</div>
                <input type="text" readonly value="<?= h($_GET['new_barcode']) ?>" onclick="this.select()">
            </div>
            <?php endif; ?>

            <p style="margin:0.25rem 0 0.75rem;">
                <button type="button" class="btn-add-copy" id="btn-open-add-copy">+ Aggiungi nuova copia</button>
            </p>

            <?php if (!empty($editCopies)): ?>
            <div style="overflow-x:auto;">
                <table class="copy-table">
                    <thead>
                        <tr>
                            <th>CopyID</th>
                            <th>Barcode</th>
                            <th>Stato</th>
                            <th>Prestatario</th>
                            <th>Note</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($editCopies as $copy):
                        $cid = (int)$copy['copyid'];
                        $status = $copy['status_cd'];
                        $inLoan = in_array($status, ['out', 'ln', 'hld'], true);
                        $color = $statusColors[$status] ?? '#374151';
                    ?>
                        <tr class="view-<?= $cid ?>">
                            <td><?= $cid ?></td>
                            <td class="copy-barcode"><?= h($copy['barcode_nmbr']) ?></td>
                            <td>
                                <span style="color:<?= $color ?>;font-weight:600;"><?= h($statusLabels[$status] ?? $status) ?></span>
                            </td>
                            <td>
                                <?= $copy['mbrid'] ? h(trim(($copy['first_name'] ?? '') . ' ' . ($copy['last_name'] ?? ''))) : '—' ?>
                            </td>
                            <td><?= h($copy['copy_desc'] ?? '') ?: '—' ?></td>
                            <td>
                                <?php if (!$inLoan): ?>
                                    <button type="button" class="btn-link" onclick="toggleEdit(<?= $cid ?>)">Modifica</button>
                                <?php else: ?>
                                    <small style="color:#9ca3af;">In prestito</small>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <!-- Riga modifica inline -->
                        <tr class="edit-<?= $cid ?>" style="display:none;">
                            <td colspan="6" class="copy-edit-row">
                                <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_catalog_edit&edit_bibid=<?= $ebib ?>">
                                    <input type="hidden" name="action" value="update_copy">
                                    <input type="hidden" name="bibid" value="<?= $ebib ?>">
                                    <input type="hidden" name="copyid" value="<?= $cid ?>">

                                    <div class="copy-inline-form">
                                        <div class="copy-inline-field" style="flex:1 1 140px;">
                                            <label>Barcode</label>
                                            <input type="text" name="barcode_nmbr" value="<?= h($copy['barcode_nmbr']) ?>" required pattern="[A-Z0-9\-]{3,20}" style="font-family:monospace;">
                                        </div>
                                        <div class="copy-inline-field" style="flex:1 1 120px;">
                                            <label>Stato</label>
                                            <select name="status_cd">
                                                <?php foreach ($statusList as $s):
                                                    if (in_array($s['code'], ['out', 'ln'], true)) continue;
                                                ?>
                                                <option value="<?= h($s['code']) ?>" <?= $status === $s['code'] ? 'selected' : '' ?>><?= h($s['description']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="copy-inline-field" style="flex:2 1 200px;">
                                            <label>Nota</label>
                                            <input type="text" name="copy_desc" value="<?= h($copy['copy_desc'] ?? '') ?>">
                                        </div>
                                        <div class="copy-inline-actions">
                                            <button type="submit" class="btn-primary" style="padding:0.35rem 0.7rem;font-size:0.85rem;">Salva</button>
                                            <button type="button" class="btn-secondary" style="padding:0.35rem 0.7rem;font-size:0.85rem;" onclick="toggleEdit(<?= $cid ?>)">Annulla</button>
                                        </div>
                                    </div>
                                </form>

                                <div class="copy-danger-actions">
                                    <?php if ($status !== 'dis'): ?>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Scartare questa copia? Verrà archiviata con stato Scartato.')">
                                        <input type="hidden" name="action" value="discard_copy">
                                        <input type="hidden" name="bibid" value="<?= $ebib ?>">
                                        <input type="hidden" name="copyid" value="<?= $cid ?>">
                                        <button type="submit" class="btn-link--danger">🗑️ Scarta</button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if (!$inLoan): ?>
                                    <form method="post" style="display:inline" onsubmit="return confirm('ELIMINARE DEFINITIVAMENTE?')">
                                        <input type="hidden" name="action" value="force_delete_copy">
                                        <input type="hidden" name="bibid" value="<?= $ebib ?>">
                                        <input type="hidden" name="copyid" value="<?= $cid ?>">
                                        <button type="submit" class="btn-link--delete">❌ Elimina fisica</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p style="color:#666;">Nessuna copia collegata.</p>
            <?php endif; ?>
        </div>

        <!-- MODAL AGGIUNGI COPIA -->
        <div id="modal-add-copy" class="copy-modal-overlay" style="display:none;">
            <div id="modal-backdrop" class="copy-modal-backdrop"></div>
            <div role="dialog" aria-modal="true" class="copy-modal-box">
                <div class="copy-modal-header">
                    <h3>Aggiungi nuova copia</h3>
                    <button type="button" id="btn-close-add-copy" class="btn-secondary" style="padding:0.25rem 0.6rem;">Chiudi</button>
                </div>
                <div class="copy-modal-body">
                    <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_catalog_edit&edit_bibid=<?= $ebib ?>">
                        <input type="hidden" name="action" value="add_copy">
                        <input type="hidden" name="bibid" value="<?= $ebib ?>">

                        <div class="search-row">
                            <label>
                                <input type="checkbox" name="use_manual_barcode" id="use-manual-barcode" style="margin-right:0.35rem;">
                                Usa barcode manuale
                            </label>
                        </div>
                        <div class="search-row" id="manual-barcode-box" style="display:none;">
                            <label for="new-barcode">Barcode manuale</label>
                            <input type="text" id="new-barcode" name="barcode_manual" maxlength="20" pattern="[A-Z0-9\-]{3,20}" placeholder="Es. 9788801234567">
                            <p class="search-help">Deve essere univoco. Se non spunti la casella, si genera automaticamente (7 cifre).</p>
                        </div>

                        <div class="search-row">
                            <label for="new-desc">Nota copia</label>
                            <input type="text" id="new-desc" name="copy_desc" maxlength="160" placeholder="Es. Donazione / Fondo...">
                        </div>

                        <div class="search-actions" style="margin-top:0.75rem;">
                            <button type="submit" class="btn-primary">Salva copia</button>
                            <button type="button" class="btn-secondary" id="btn-cancel-add-copy">Annulla</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ELIMINA RECORD -->
        <section class="staff-delete-box">
            <h3>Elimina record</h3>
            <p style="font-size:0.9rem;color:#7f1d1d;margin-bottom:0.6rem;">Operazione <strong>definitiva</strong>.</p>
            <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_catalog_edit&edit_bibid=<?= $ebib ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="bibid" value="<?= $ebib ?>">

                <?php if ($editCopyCount > 0): ?>
                <div class="staff-delete-warning">
                    ⚠️ <?= $editCopyCount ?> copie attive collegate. Elimina prima tutte le copie.
                </div>
                <?php endif; ?>

                <button type="submit" class="btn-secondary btn-delete" <?= $editCopyCount > 0 ? 'disabled' : '' ?> onclick="return confirm('Eliminare DEFINITIVAMENTE?');">Elimina record</button>
            </form>
        </section>

        <script>
        (function() {
            var modal = document.getElementById('modal-add-copy');
            var backdrop = document.getElementById('modal-backdrop');

            function openModal() {
                if (!modal) return;
                modal.style.display = 'block';
                var input = document.getElementById('new-desc');
                if (input) setTimeout(function(){ input.focus(); }, 0);
            }
            function closeModal() {
                if (!modal) return;
                modal.style.display = 'none';
            }

            document.getElementById('btn-open-add-copy')?.addEventListener('click', function(e) { e.preventDefault(); openModal(); });
            document.getElementById('btn-close-add-copy')?.addEventListener('click', closeModal);
            document.getElementById('btn-cancel-add-copy')?.addEventListener('click', closeModal);
            backdrop?.addEventListener('click', closeModal);
            document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

            document.getElementById('use-manual-barcode')?.addEventListener('change', function() {
                document.getElementById('manual-barcode-box').style.display = this.checked ? 'block' : 'none';
                if (this.checked) setTimeout(function(){ document.getElementById('new-barcode').focus(); }, 0);
            });
        })();

        function toggleEdit(cid) {
            document.querySelectorAll('.view-' + cid).forEach(function(el) {
                el.style.display = el.style.display === 'none' ? '' : 'none';
            });
            var edit = document.querySelector('.edit-' + cid);
            edit.style.display = edit.style.display === 'none' ? '' : 'none';
        }
        </script>
    </section>
    <?php endif; ?>
</section>
