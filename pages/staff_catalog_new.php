<?php
declare(strict_types=1);

/**
 * Inserimento nuovo record di catalogo - Area staff
 *
 * Funzioni:
 * - Mostra un form per inserire un nuovo titolo in catalogo
 * - Salva i dati minimi nella tabella `biblio`
 * - Genera automaticamente una copia in `biblio_copy` con barcode "0{bibid}"
 * - Salva l'ISBN in `biblio_field` (tag 20, $a)
 * - Salva Editore e Anno in `biblio_field` (tag 260, $b e $c)
 * - Salva Descrizione fisica / pagine in `biblio_field` (tag 300, $a)
 * - Salva Riassunto (520 $a) e Nota generale (500 $a) in `biblio_field`
 *
 * Dipendenze:
 * - DB::conn() in lib/DB.php
 * - h() in lib/helpers.php
 *
 * Tabelle usate:
 * - biblio
 * - biblio_copy
 * - biblio_field
 * - material_type_dm
 * - collection_dm
 */

$pdo = DB::conn();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$baseUrl = function_exists('base_url') ? base_url() : '';

$staffUserId = isset($_SESSION['staff_user_id']) ? (int)$_SESSION['staff_user_id'] : 0;

/**
 * Protezione accesso staff:
 * se non loggato come staff → login con redirect.
 */
if ($staffUserId <= 0) {
    $target = 'index.php?page=login&redirect=' . urlencode('staff_catalog_new');
    header('Location: ' . $target);
    exit;
}

// -----------------------------------------------------------------------------
// Caricamento liste di dominio (materiali e collezioni)
// -----------------------------------------------------------------------------

$materiali   = [];
$collezioni  = [];
$errors      = [];
$successMsg  = '';
$newBibid    = null;
$newBarcode  = null;

// Materiali
try {
    $stmt = $pdo->query('SELECT code, description FROM material_type_dm ORDER BY description');
    $materiali = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $materiali = [];
}

// Collezioni
try {
    $stmt = $pdo->query('SELECT code, description FROM collection_dm ORDER BY description');
    $collezioni = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $collezioni = [];
}

// -----------------------------------------------------------------------------
// Valori di default / binding form
// -----------------------------------------------------------------------------

$data = [
    'title'            => '',
    'title_remainder'  => '',
    'author'           => '',
    'responsibility'   => '',
    'material_cd'      => '',
    'collection_cd'    => '',
    'call_nmbr1'       => '',
    'call_nmbr2'       => '',
    'call_nmbr3'       => '',
    'topic1'           => '',
    'topic2'           => '',
    'topic3'           => '',
    'topic4'           => '',
    'topic5'           => '',
    'isbn'             => '',
    'publisher'        => '',
    'pub_year'         => '',
    'pages'            => '', // per MARC 300 $a
    'summary'          => '',
    'notes'            => '',
];

// -----------------------------------------------------------------------------
// Gestione POST (salvataggio nuovo record)
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Raccolta campi
    $data['title']           = trim((string)($_POST['title'] ?? ''));
    $data['title_remainder'] = trim((string)($_POST['title_remainder'] ?? ''));
    $data['author']          = trim((string)($_POST['author'] ?? ''));
    $data['responsibility']  = trim((string)($_POST['responsibility'] ?? ''));
    $data['material_cd']     = trim((string)($_POST['material_cd'] ?? ''));
    $data['collection_cd']   = trim((string)($_POST['collection_cd'] ?? ''));
    $data['call_nmbr1']      = trim((string)($_POST['call_nmbr1'] ?? ''));
    $data['call_nmbr2']      = trim((string)($_POST['call_nmbr2'] ?? ''));
    $data['call_nmbr3']      = trim((string)($_POST['call_nmbr3'] ?? ''));
    $data['topic1']          = trim((string)($_POST['topic1'] ?? ''));
    $data['topic2']          = trim((string)($_POST['topic2'] ?? ''));
    $data['topic3']          = trim((string)($_POST['topic3'] ?? ''));
    $data['topic4']          = trim((string)($_POST['topic4'] ?? ''));
    $data['topic5']          = trim((string)($_POST['topic5'] ?? ''));
    $data['isbn']            = trim((string)($_POST['isbn'] ?? ''));
    $data['publisher']       = trim((string)($_POST['publisher'] ?? ''));
    $data['pub_year']        = trim((string)($_POST['pub_year'] ?? ''));
    $data['pages']           = trim((string)($_POST['pages'] ?? ''));
    $data['summary']         = trim((string)($_POST['summary'] ?? ''));
    $data['notes']           = trim((string)($_POST['notes'] ?? ''));

    // Validazione minima
    if ($data['title'] === '') {
        $errors[] = 'Il campo <strong>Titolo</strong> è obbligatorio.';
    }
    if ($data['author'] === '') {
        $errors[] = 'Il campo <strong>Autore</strong> è consigliato (può essere lasciato vuoto solo in casi particolari).';
    }
    if ($data['material_cd'] === '') {
        $errors[] = 'Seleziona un <strong>Tipo di materiale</strong>.';
    }
    if ($data['collection_cd'] === '') {
        $errors[] = 'Seleziona una <strong>Collocazione / sezione</strong>.';
    }

    if ($errors === []) {
        $now = date('Y-m-d H:i:s');

        try {
            // Iniziamo una transazione per inserire in biblio + biblio_copy + biblio_field
            $pdo->beginTransaction();

            // ---------------------------------------------------------
            // INSERT in biblio
            // ---------------------------------------------------------
            $sql = '
                INSERT INTO biblio (
                    create_dt,
                    last_change_dt,
                    last_change_userid,
                    material_cd,
                    collection_cd,
                    call_nmbr1,
                    call_nmbr2,
                    call_nmbr3,
                    title,
                    title_remainder,
                    responsibility_stmt,
                    author,
                    topic1,
                    topic2,
                    topic3,
                    topic4,
                    topic5
                ) VALUES (
                    :create_dt,
                    :last_change_dt,
                    :last_change_userid,
                    :material_cd,
                    :collection_cd,
                    :call_nmbr1,
                    :call_nmbr2,
                    :call_nmbr3,
                    :title,
                    :title_remainder,
                    :responsibility_stmt,
                    :author,
                    :topic1,
                    :topic2,
                    :topic3,
                    :topic4,
                    :topic5
                )
            ';

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':create_dt', $now, PDO::PARAM_STR);
            $stmt->bindValue(':last_change_dt', $now, PDO::PARAM_STR);
            $stmt->bindValue(':last_change_userid', $staffUserId, PDO::PARAM_INT);

            $stmt->bindValue(':material_cd',   $data['material_cd'], PDO::PARAM_STR);
            $stmt->bindValue(':collection_cd', $data['collection_cd'], PDO::PARAM_STR);
            $stmt->bindValue(':call_nmbr1',    $data['call_nmbr1'], PDO::PARAM_STR);
            $stmt->bindValue(':call_nmbr2',    $data['call_nmbr2'], PDO::PARAM_STR);
            $stmt->bindValue(':call_nmbr3',    $data['call_nmbr3'], PDO::PARAM_STR);

            $stmt->bindValue(':title',               $data['title'], PDO::PARAM_STR);
            $stmt->bindValue(':title_remainder',     $data['title_remainder'], PDO::PARAM_STR);
            $stmt->bindValue(':responsibility_stmt', $data['responsibility'], PDO::PARAM_STR);
            $stmt->bindValue(':author',              $data['author'], PDO::PARAM_STR);

            $stmt->bindValue(':topic1', $data['topic1'], PDO::PARAM_STR);
            $stmt->bindValue(':topic2', $data['topic2'], PDO::PARAM_STR);
            $stmt->bindValue(':topic3', $data['topic3'], PDO::PARAM_STR);
            $stmt->bindValue(':topic4', $data['topic4'], PDO::PARAM_STR);
            $stmt->bindValue(':topic5', $data['topic5'], PDO::PARAM_STR);

            $stmt->execute();

            $newBibid = (int)$pdo->lastInsertId();

           // ---------------------------------------------------------
            // INSERT automatico PRIMA copia in biblio_copy
            // barcode = bibid (5 cifre) + copy progressivo (2 cifre)
            // ---------------------------------------------------------
            if ($newBibid > 0) {
                try {
                    $nowDt = date('Y-m-d H:i:s');
            
                    // 1️⃣ calcoliamo il prossimo copyid PER QUESTO bibid
                    $stmtMax = $pdo->prepare('
                        SELECT COALESCE(MAX(copyid), 0) + 1
                        FROM biblio_copy
                        WHERE bibid = :bibid
                    ');
                    $stmtMax->execute([':bibid' => $newBibid]);
                    $nextCopyId = (int)$stmtMax->fetchColumn();
            
                    // 2️⃣ generiamo barcode univoco (coerente con storico)
                    // es: bibid 7116 + copy 1 → 0711601
                    $newBarcode =
                        str_pad((string)$newBibid, 5, '0', STR_PAD_LEFT) .
                        str_pad((string)$nextCopyId, 2, '0', STR_PAD_LEFT);
            
                    // 3️⃣ INSERT completo (tutti i NOT NULL coperti)
                    $stmtCopy = $pdo->prepare('
                        INSERT INTO biblio_copy (
                            bibid,
                            copyid,
                            create_dt,
                            barcode_nmbr,
                            status_cd,
                            status_begin_dt,
                            renewal_count
                        ) VALUES (
                            :bibid,
                            :copyid,
                            :create_dt,
                            :barcode,
                            :status_cd,
                            :status_begin_dt,
                            0
                        )
                    ');
                    $stmtCopy->execute([
                        ':bibid'           => $newBibid,
                        ':copyid'          => $nextCopyId,
                        ':create_dt'       => $nowDt,
                        ':barcode'         => $newBarcode,
                        ':status_cd'       => 'in',
                        ':status_begin_dt' => $nowDt,
                    ]);
            
                } catch (PDOException $e) {
                    // qui ora SE FALLISCE è un vero errore
                    $errors[] = 'Errore creazione copia automatica.';
                    throw $e;
                }
            }

            // ---------------------------------------------------------
            // Campi MARC in biblio_field (complementari ai campi base)
            // ---------------------------------------------------------
            if ($newBibid > 0) {
                // ISBN (20 $a)
                if ($data['isbn'] !== '') {
                    try {
                        $sqlIsbn = '
                            INSERT INTO biblio_field (
                                bibid,
                                tag,
                                subfield_cd,
                                field_data
                            ) VALUES (
                                :bibid,
                                20,
                                :subfield_cd,
                                :field_data
                            )
                        ';
                        $stmtIsbn = $pdo->prepare($sqlIsbn);
                        $stmtIsbn->bindValue(':bibid', $newBibid, PDO::PARAM_INT);
                        $stmtIsbn->bindValue(':subfield_cd', 'a', PDO::PARAM_STR);
                        $stmtIsbn->bindValue(':field_data', $data['isbn'], PDO::PARAM_STR);
                        $stmtIsbn->execute();
                    } catch (PDOException $e) {
                        // Ignoriamo: niente ISBN strutturato
                    }
                }

                // Editore (260 $b)
                if ($data['publisher'] !== '') {
                    try {
                        $sqlPub = '
                            INSERT INTO biblio_field (
                                bibid,
                                tag,
                                subfield_cd,
                                field_data
                            ) VALUES (
                                :bibid,
                                260,
                                :subfield_cd,
                                :field_data
                            )
                        ';
                        $stmtPub = $pdo->prepare($sqlPub);
                        $stmtPub->bindValue(':bibid', $newBibid, PDO::PARAM_INT);
                        $stmtPub->bindValue(':subfield_cd', 'b', PDO::PARAM_STR);
                        $stmtPub->bindValue(':field_data', $data['publisher'], PDO::PARAM_STR);
                        $stmtPub->execute();
                    } catch (PDOException $e) {
                        // Ignoriamo: niente editore strutturato
                    }
                }

                // Anno (260 $c)
                if ($data['pub_year'] !== '') {
                    try {
                        $sqlYear = '
                            INSERT INTO biblio_field (
                                bibid,
                                tag,
                                subfield_cd,
                                field_data
                            ) VALUES (
                                :bibid,
                                260,
                                :subfield_cd,
                                :field_data
                            )
                        ';
                        $stmtYear = $pdo->prepare($sqlYear);
                        $stmtYear->bindValue(':bibid', $newBibid, PDO::PARAM_INT);
                        $stmtYear->bindValue(':subfield_cd', 'c', PDO::PARAM_STR);
                        $stmtYear->bindValue(':field_data', $data['pub_year'], PDO::PARAM_STR);
                        $stmtYear->execute();
                    } catch (PDOException $e) {
                        // Ignoriamo: niente anno strutturato
                    }
                }

                // Descrizione fisica / pagine (300 $a)
                if ($data['pages'] !== '') {
                    try {
                        $sqlPages = '
                            INSERT INTO biblio_field (
                                bibid,
                                tag,
                                subfield_cd,
                                field_data
                            ) VALUES (
                                :bibid,
                                300,
                                :subfield_cd,
                                :field_data
                            )
                        ';
                        $stmtPages = $pdo->prepare($sqlPages);
                        $stmtPages->bindValue(':bibid', $newBibid, PDO::PARAM_INT);
                        $stmtPages->bindValue(':subfield_cd', 'a', PDO::PARAM_STR);
                        $stmtPages->bindValue(':field_data', $data['pages'], PDO::PARAM_STR);
                        $stmtPages->execute();
                    } catch (PDOException $e) {
                        // Ignoriamo: niente descrizione fisica strutturata
                    }
                }

                // Riassunto (520 $a)
                if ($data['summary'] !== '') {
                    try {
                        $sqlSummary = '
                            INSERT INTO biblio_field (
                                bibid,
                                tag,
                                subfield_cd,
                                field_data
                            ) VALUES (
                                :bibid,
                                520,
                                :subfield_cd,
                                :field_data
                            )
                        ';
                        $stmtSummary = $pdo->prepare($sqlSummary);
                        $stmtSummary->bindValue(':bibid', $newBibid, PDO::PARAM_INT);
                        $stmtSummary->bindValue(':subfield_cd', 'a', PDO::PARAM_STR);
                        $stmtSummary->bindValue(':field_data', $data['summary'], PDO::PARAM_STR);
                        $stmtSummary->execute();
                    } catch (PDOException $e) {
                        // Ignoriamo: niente 520
                    }
                }

                // Nota generale (500 $a)
                if ($data['notes'] !== '') {
                    try {
                        $sqlNotes = '
                            INSERT INTO biblio_field (
                                bibid,
                                tag,
                                subfield_cd,
                                field_data
                            ) VALUES (
                                :bibid,
                                500,
                                :subfield_cd,
                                :field_data
                            )
                        ';
                        $stmtNotes = $pdo->prepare($sqlNotes);
                        $stmtNotes->bindValue(':bibid', $newBibid, PDO::PARAM_INT);
                        $stmtNotes->bindValue(':subfield_cd', 'a', PDO::PARAM_STR);
                        $stmtNotes->bindValue(':field_data', $data['notes'], PDO::PARAM_STR);
                        $stmtNotes->execute();
                    } catch (PDOException $e) {
                        // Ignoriamo: niente 500
                    }
                }
            }

            $pdo->commit();

            // Messaggio di successo "base"
            if ($newBibid !== null) {
                if ($newBarcode !== null) {
                    $successMsg = 'Record creato correttamente.';
                } else {
                    $successMsg = 'Record creato correttamente (attenzione: la copia automatica non è stata creata).';
                }
            } else {
                $successMsg = 'Record creato correttamente.';
            }

            // Svuoto i campi per un nuovo inserimento
            foreach ($data as $k => $_) {
                $data[$k] = '';
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Si è verificato un errore durante il salvataggio del record. Riprova.';
        }
    }
}

?>
<section class="page-section page-staff-catalog-new">
    <h1>Nuovo record di catalogo</h1>

    <p>
        Compila i dati principali del titolo. Dopo il salvataggio verrà generata in automatico
        una copia in magazzino con un codice univoco (barcode) basato sul bibid.
    </p>

    <?php if ($successMsg !== ''): ?>
        <div class="generic-box" style="margin-top:0.75rem;">
            <p><?= $successMsg ?></p>

            <?php if ($newBibid !== null): ?>
                <ul style="margin:0.5rem 0 0.75rem 1.1rem;font-size:0.9rem;">
                    <li>Codice titolo (bibid): <strong><?= (int)$newBibid ?></strong></li>
                    <?php if ($newBarcode !== null): ?>
                        <li>Codice copia (barcode): <strong><?= h($newBarcode) ?></strong></li>
                    <?php endif; ?>
                </ul>

                <p style="margin:0.4rem 0 0.2rem 0;font-size:0.9rem;">
                    Azioni rapide:
                </p>
                <p style="margin-top:0.3rem;display:flex;flex-wrap:wrap;gap:0.5rem;">
                    <a
                        href="<?= h($baseUrl) ?>/index.php?page=item&amp;bibid=<?= (int)$newBibid ?>"
                        class="btn-secondary"
                    >
                        Apri scheda pubblica
                    </a>

                    <a
                        href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_new"
                        class="btn-link"
                    >
                        Inserisci un altro record
                    </a>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="generic-box" style="margin-top:0.75rem;">
            <?php foreach ($errors as $msg): ?>
                <p><?= $msg ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_catalog_new">
        <div class="search-row">
            <label for="title">Titolo <span style="color:#b91c1c;">*</span></label>
            <input
                type="text"
                id="title"
                name="title"
                value="<?= h($data['title']) ?>"
                required
            >
        </div>

        <div class="search-row">
            <label for="title_remainder">Complemento del titolo</label>
            <input
                type="text"
                id="title_remainder"
                name="title_remainder"
                value="<?= h($data['title_remainder']) ?>"
            >
        </div>

        <div class="search-row">
            <label for="author">Autore principale</label>
            <input
                type="text"
                id="author"
                name="author"
                value="<?= h($data['author']) ?>"
            >
        </div>

        <div class="search-row">
            <label for="responsibility">Altre responsabilità (cur., trad., ecc.)</label>
            <input
                type="text"
                id="responsibility"
                name="responsibility"
                value="<?= h($data['responsibility']) ?>"
            >
        </div>

        <div class="search-row-inline">
            <div style="flex:1 1 200px;">
                <label for="material_cd">Tipo di materiale <span style="color:#b91c1c;">*</span></label>
                <select id="material_cd" name="material_cd" required>
                    <option value="">— Seleziona —</option>
                    <?php foreach ($materiali as $m): ?>
                        <?php
                            $code = (string)($m['code'] ?? '');
                            $desc = (string)($m['description'] ?? $code);
                        ?>
                        <option
                            value="<?= h($code) ?>"
                            <?= $code === $data['material_cd'] ? 'selected' : '' ?>
                        >
                            <?= h($desc) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex:1 1 200px;">
                <label for="collection_cd">Collocazione / sezione <span style="color:#b91c1c;">*</span></label>
                <select id="collection_cd" name="collection_cd" required>
                    <option value="">— Seleziona —</option>
                    <?php foreach ($collezioni as $c): ?>
                        <?php
                            $code = (string)($c['code'] ?? '');
                            $desc = (string)($c['description'] ?? $code);
                        ?>
                        <option
                            value="<?= h($code) ?>"
                            <?= $code === $data['collection_cd'] ? 'selected' : '' ?>
                        >
                            <?= h($desc) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="search-row-inline">
            <div style="flex:1 1 120px;">
                <label for="call_nmbr1">Collocazione 1</label>
                <input
                    type="text"
                    id="call_nmbr1"
                    name="call_nmbr1"
                    value="<?= h($data['call_nmbr1']) ?>"
                >
            </div>
            <div style="flex:1 1 120px;">
                <label for="call_nmbr2">Collocazione 2</label>
                <input
                    type="text"
                    id="call_nmbr2"
                    name="call_nmbr2"
                    value="<?= h($data['call_nmbr2']) ?>"
                >
            </div>
            <div style="flex:1 1 120px;">
                <label for="call_nmbr3">Collocazione 3</label>
                <input
                    type="text"
                    id="call_nmbr3"
                    name="call_nmbr3"
                    value="<?= h($data['call_nmbr3']) ?>"
                >
            </div>
        </div>

        <div class="search-row-inline">
            <div style="flex:2 1 260px;">
                <label for="publisher">Editore</label>
                <input
                    type="text"
                    id="publisher"
                    name="publisher"
                    value="<?= h($data['publisher']) ?>"
                    placeholder="Es. Einaudi"
                >
            </div>
            <div style="flex:1 1 120px;">
                <label for="pub_year">Anno di pubblicazione</label>
                <input
                    type="text"
                    id="pub_year"
                    name="pub_year"
                    value="<?= h($data['pub_year']) ?>"
                    placeholder="Es. 1995"
                >
            </div>
        </div>

        <div class="search-row">
            <label for="pages">Descrizione fisica / pagine</label>
            <input
                type="text"
                id="pages"
                name="pages"
                value="<?= h($data['pages']) ?>"
                placeholder="Es. 320 pagine"
            >
            <p class="search-help" style="font-size:0.82rem;color:#666;margin-top:0.25rem;">
                Puoi indicare semplicemente il numero di pagine o una breve descrizione
                (es. <em>320 pagine, ill.</em>). Verrà salvato come campo MARC 300 $a.
            </p>
        </div>

        <div class="search-row">
            <label for="isbn">ISBN</label>
            <div class="search-row-inline" style="align-items:center;">
                <input
                    type="text"
                    id="isbn"
                    name="isbn"
                    value="<?= h($data['isbn']) ?>"
                    placeholder="Es. 9788800000000"
                    style="flex:1 1 220px;"
                >
                <button type="button" class="btn-primary" id="btn-isbn-lookup">
                    Compila dai cataloghi esterni
                </button>
            </div>
            <p class="search-help" style="font-size:0.82rem;color:#666;margin-top:0.25rem;">
                Il pulsante prova a recuperare dati da SBN (JSON) e, se necessario, da Google Books,
                per precompilare titolo, autore, editore, anno, soggetti e descrizione.
                L’ISBN viene anche salvato come MARC 20 $a.
            </p>
        </div>

        <div class="search-row">
            <label>Soggetti / parole chiave</label>
            <div class="search-row-inline">
                <input
                    type="text"
                    name="topic1"
                    id="topic1"
                    placeholder="Soggetto 1"
                    value="<?= h($data['topic1']) ?>"
                    style="flex:1 1 140px;"
                >
                <input
                    type="text"
                    name="topic2"
                    id="topic2"
                    placeholder="Soggetto 2"
                    value="<?= h($data['topic2']) ?>"
                    style="flex:1 1 140px;"
                >
                <input
                    type="text"
                    name="topic3"
                    id="topic3"
                    placeholder="Soggetto 3"
                    value="<?= h($data['topic3']) ?>"
                    style="flex:1 1 140px;"
                >
            </div>
            <div class="search-row-inline" style="margin-top:0.35rem;">
                <input
                    type="text"
                    name="topic4"
                    id="topic4"
                    placeholder="Soggetto 4"
                    value="<?= h($data['topic4']) ?>"
                    style="flex:1 1 140px;"
                >
                <input
                    type="text"
                    name="topic5"
                    id="topic5"
                    placeholder="Soggetto 5"
                    value="<?= h($data['topic5']) ?>"
                    style="flex:1 1 140px;"
                >
            </div>
        </div>

        <div class="search-row">
            <label for="summary">Riassunto / descrizione del contenuto</label>
            <textarea
                id="summary"
                name="summary"
                rows="4"
            ><?= h($data['summary']) ?></textarea>
            <p class="search-help" style="font-size:0.82rem;color:#666;margin-top:0.25rem;">
                Verrà salvato come campo MARC 520 $a ed è mostrato nella scheda titolo pubblica.
            </p>
        </div>

        <div class="search-row">
            <label for="notes">Note generali / note interne</label>
            <textarea
                id="notes"
                name="notes"
                rows="3"
            ><?= h($data['notes']) ?></textarea>
            <p class="search-help" style="font-size:0.82rem;color:#666;margin-top:0.25rem;">
                Salvato come MARC 500 $a. Può essere usato per note generali o informazioni aggiuntive.
            </p>
        </div>

        <div class="search-actions">
            <button type="submit" class="btn-primary">
                Salva record
            </button>

            <a class="btn-link" href="<?= h($baseUrl) ?>/index.php?page=staff">
                Torna alla dashboard staff
            </a>
        </div>
    </form>
</section>

<script>
// Lookup ISBN → riempie i campi dal servizio staff_isbn_lookup.php
document.addEventListener('DOMContentLoaded', function () {
    const btn   = document.getElementById('btn-isbn-lookup');
    const isbnI = document.getElementById('isbn');

    if (!btn || !isbnI) return;

    btn.addEventListener('click', function () {
        const isbn = (isbnI.value || '').trim();
        if (!isbn) {
            alert('Inserisci un ISBN prima di cercare.');
            return;
        }

        btn.disabled = true;
        const originalLabel = btn.textContent;
        btn.textContent = 'Consulto i cataloghi...';

        // Il file è in /public, e la pagina gira da /public/index.php?page=staff_catalog_new
        fetch('staff_isbn_lookup.php?isbn=' + encodeURIComponent(isbn), {
            headers: { 'Accept': 'application/json' }
        })
        .then(function (resp) {
            return resp.json();
        })
        .then(function (data) {
            if (!data || !data.ok) {
                alert((data && data.error) ? data.error : 'Nessun dato trovato.');
                return;
            }

            const byId = function (id) { return document.getElementById(id); };

            // Compila solo se il campo è ancora vuoto (non sovrascrive il lavoro umano)
            if (data.title && byId('title') && !byId('title').value) {
                byId('title').value = data.title;
            }
            if (data.subtitle && byId('title_remainder') && !byId('title_remainder').value) {
                byId('title_remainder').value = data.subtitle;
            }
            if (data.author && byId('author') && !byId('author').value) {
                byId('author').value = data.author;
            }
            if (data.publisher && byId('publisher') && !byId('publisher').value) {
                byId('publisher').value = data.publisher;
            }
            if (data.pub_year && byId('pub_year') && !byId('pub_year').value) {
                byId('pub_year').value = data.pub_year;
            }
            if (data.pages && byId('pages') && !byId('pages').value) {
                // Trasformiamo 320 → "320 pagine", in linea con molti 300 $a esistenti
                byId('pages').value = data.pages + ' pagine';
            }
            if (data.description && byId('summary') && !byId('summary').value) {
                byId('summary').value = data.description;
            }

            // Soggetti → prova a popolare topic1..topic5
            if (Array.isArray(data.subjects) && data.subjects.length) {
                const topicIds = ['topic1', 'topic2', 'topic3', 'topic4', 'topic5'];
                topicIds.forEach(function (tid, idx) {
                    const el = byId(tid);
                    if (!el) return;
                    if (!el.value && data.subjects[idx]) {
                        el.value = data.subjects[idx];
                    }
                });
            }
        })
        .catch(function (err) {
            console.error(err);
            alert('Errore nella chiamata ai cataloghi esterni.');
        })
        .finally(function () {
            btn.disabled = false;
            btn.textContent = originalLabel;
        });
    });
});
</script>
