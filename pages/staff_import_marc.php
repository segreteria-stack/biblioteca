<?php
declare(strict_types=1);

/**
 * Importazione MARCXML — Area staff
 *
 * Importa record da file MARCXML (singolo <record> o <collection>).
 * Mappa: 245$a titolo, 245$b complemento, 100/110/111$a autore,
 *        650$a soggetti (max 5), 020$a ISBN.
 * Opzionalmente crea copia #1 in biblio_copy con status 'in'.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$baseUrl = function_exists('base_url') ? base_url() : '';

if (empty($_SESSION['staff_user_id'])) {
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=staff_import_marc');
    exit;
}

$staffUserId = (int)$_SESSION['staff_user_id'];

/** @var \PDO $pdo */

$errors = [];
$okMsgs = [];

// ---------------------------------------------------------------------------
// Helper: estrae subfield da un nodo datafield MARCXML
// ---------------------------------------------------------------------------
function marc_subfields_xml(SimpleXMLElement $record, string $tag, string $code): array
{
    $vals = [];
    foreach ($record->datafield as $df) {
        if ((string)$df['tag'] === $tag) {
            foreach ($df->subfield as $sf) {
                if ((string)$sf['code'] === $code) {
                    $vals[] = trim((string)$sf);
                }
            }
        }
    }
    return $vals;
}

function marc_field_xml(SimpleXMLElement $record, string $tag, string $code): ?string
{
    $vals = marc_subfields_xml($record, $tag, $code);
    return $vals !== [] ? $vals[0] : null;
}

function marc_topics_xml(SimpleXMLElement $record, int $max = 5): array
{
    $topics = [];
    foreach ($record->datafield as $df) {
        if ((string)$df['tag'] === '650') {
            foreach ($df->subfield as $sf) {
                if ((string)$sf['code'] === 'a') {
                    $v = trim((string)$sf);
                    if ($v !== '') {
                        $topics[] = $v;
                    }
                    if (count($topics) >= $max) {
                        return $topics;
                    }
                }
            }
        }
    }
    return $topics;
}

function marc_isbn_xml(SimpleXMLElement $record): ?string
{
    $v = marc_field_xml($record, '020', 'a');
    if ($v === null) {
        return null;
    }
    $v = strtoupper(preg_replace('/[^0-9Xx]/', '', $v));
    return $v !== '' ? $v : null;
}

// ---------------------------------------------------------------------------
// Gestione POST
// ---------------------------------------------------------------------------
$importCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $createCopy = isset($_POST['create_copy']);

    if (!isset($_FILES['marcxml']) || (int)$_FILES['marcxml']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Caricamento file non riuscito. Verifica che il file sia selezionato e non superi il limite.';
    } else {
        $xml = @file_get_contents((string)$_FILES['marcxml']['tmp_name']);
        if ($xml === false) {
            $errors[] = 'Impossibile leggere il file caricato.';
        } else {
            try {
                $sx = new SimpleXMLElement($xml);

                $records = [];
                if ($sx->getName() === 'collection') {
                    foreach ($sx->record as $r) {
                        $records[] = $r;
                    }
                } elseif ($sx->getName() === 'record') {
                    $records[] = $sx;
                } else {
                    throw new \Exception('Formato XML non riconosciuto (atteso &lt;collection&gt; o &lt;record&gt;).');
                }

                $insBiblio = $pdo->prepare('
                    INSERT INTO biblio
                        (title, title_remainder, author,
                         topic1, topic2, topic3, topic4, topic5,
                         opac_flg, create_dt, last_change_dt, last_change_userid)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, \'Y\', NOW(), NOW(), ?)
                ');

                $insField = $pdo->prepare('
                    INSERT INTO biblio_field (bibid, tag, subfield_cd, field_data)
                    VALUES (:bibid, :tag, :sub, :data)
                ');

                $insCopy = $pdo->prepare('
                    INSERT INTO biblio_copy
                        (bibid, copyid, barcode_nmbr, status_cd, create_dt, status_begin_dt, renewal_count)
                    VALUES (?, ?, ?, \'in\', NOW(), NOW(), 0)
                ');

                $pdo->beginTransaction();

                foreach ($records as $rec) {
                    $titleMain = marc_field_xml($rec, '245', 'a') ?? '';
                    $titleRem  = marc_field_xml($rec, '245', 'b') ?? '';
                    $author    = marc_field_xml($rec, '100', 'a')
                        ?? marc_field_xml($rec, '110', 'a')
                        ?? marc_field_xml($rec, '111', 'a')
                        ?? '';
                    $topics = marc_topics_xml($rec, 5);
                    $t = array_pad($topics, 5, null);
                    $isbn = marc_isbn_xml($rec);

                    $insBiblio->execute([
                        $titleMain, $titleRem, $author,
                        $t[0], $t[1], $t[2], $t[3], $t[4],
                        $staffUserId,
                    ]);
                    $bibid = (int)$pdo->lastInsertId();

                    // ISBN in biblio_field (tag 20 $a)
                    if ($isbn !== null) {
                        $insField->execute([
                            ':bibid' => $bibid, ':tag' => 20,
                            ':sub' => 'a', ':data' => $isbn,
                        ]);
                    }

                    if ($createCopy) {
                        $stmtMax = $pdo->prepare(
                            'SELECT COALESCE(MAX(copyid), 0) + 1 FROM biblio_copy WHERE bibid = ?'
                        );
                        $stmtMax->execute([$bibid]);
                        $copyid  = (int)$stmtMax->fetchColumn();
                        $barcode = str_pad((string)$bibid, 5, '0', STR_PAD_LEFT)
                                 . str_pad((string)$copyid, 2, '0', STR_PAD_LEFT);
                        $insCopy->execute([$bibid, $copyid, $barcode]);
                    }

                    $importCount++;
                }

                $pdo->commit();
                $okMsgs[] = 'Import completato: ' . $importCount . ' record' . ($importCount !== 1 ? 'i' : '') . ' inseriti.';

            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Errore durante l\'import: ' . h($e->getMessage());
            }
        }
    }
}
?>
<section class="page-section page-staff-import-marc">
    <nav class="breadcrumb-staff" style="font-size:0.88rem;margin-bottom:1.25rem;">
        <a href="<?= h($baseUrl) ?>/index.php?page=staff">Dashboard</a> ›
        <a href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_entry">Inserisci record</a> ›
        Import MARCXML
    </nav>

    <h1>Import MARCXML</h1>
    <p>
        Carica un file <strong>MARCXML</strong> (<code>&lt;record&gt;</code> singolo o <code>&lt;collection&gt;</code>).
        Vengono importati i campi: <code>245$a</code> titolo, <code>245$b</code> complemento,
        <code>100/110/111$a</code> autore, <code>650$a</code> soggetti (max 5), <code>020$a</code> ISBN.
    </p>

    <?php if ($okMsgs !== []): ?>
        <div class="generic-box" style="margin:0.75rem 0;border-left:4px solid #16a34a;">
            <?php foreach ($okMsgs as $msg): ?>
                <p><?= h($msg) ?></p>
            <?php endforeach; ?>
            <p style="margin-top:0.5rem;">
                <a href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_entry" class="btn-secondary">
                    Torna all'inserimento
                </a>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="generic-box" style="margin:0.75rem 0;border-left:4px solid #b91c1c;">
            <?php foreach ($errors as $msg): ?>
                <p><?= h($msg) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" style="margin-top:1.25rem;">

        <div class="search-row">
            <label for="marcxml">File MARCXML (.xml) <span style="color:#b91c1c;">*</span></label>
            <input type="file" id="marcxml" name="marcxml" accept=".xml" required>
        </div>

        <div class="search-row">
            <label style="display:flex;align-items:center;gap:0.5rem;font-weight:400;cursor:pointer;">
                <input type="checkbox" name="create_copy" value="1">
                Crea automaticamente una copia disponibile (<em>status: in</em>) per ogni record
            </label>
        </div>

        <div class="search-actions">
            <button type="submit" class="btn-primary">Importa</button>
            <a class="btn-link" href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_entry">Annulla</a>
        </div>
    </form>

    <details style="margin-top:1.5rem;">
        <summary style="cursor:pointer;font-size:0.9rem;color:#4b5563;">Mapping campi MARCXML → database</summary>
        <ul style="margin-top:0.5rem;font-size:0.88rem;line-height:1.8;">
            <li><code>245$a</code> → <code>biblio.title</code></li>
            <li><code>245$b</code> → <code>biblio.title_remainder</code></li>
            <li><code>100$a</code> (o <code>110$a</code> / <code>111$a</code>) → <code>biblio.author</code></li>
            <li>Fino a 5 × <code>650$a</code> → <code>biblio.topic1..topic5</code></li>
            <li><code>020$a</code> → <code>biblio.isbn</code> e <code>biblio_field</code> (tag 20 $a)</li>
        </ul>
    </details>
</section>
