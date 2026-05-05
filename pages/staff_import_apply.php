<?php
/**
 * Importazione dati – STEP 3 (creazione record nel catalogo)
 *
 * - Riceve i dati dal wizard (STEP 2) per:
 *     * MARC21 (kind = "marc")
 *     * EndNote (kind = "endnote")
 * - Normalizza titolo / responsabilità / soggetti / pubblicazione
 * - Inserisce un nuovo record in:
 *     * biblio
 *     * biblio_field (tag principali: 20, 245, 260/264, 300, 520, 650)
 *
 * PHP 8.3
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Solo staff autenticato
if (empty($_SESSION['staff_user_id'])) {
    $baseUrl = function_exists('base_url') ? base_url() : '';
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=' . urlencode('admin_import'));
    exit;
}

$baseUrl = function_exists('base_url') ? base_url() : '';

// Di norma DB::conn() e h() sono disponibili via include globale, ma per sicurezza:
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/DB.php';

$pdo = DB::conn();

// -----------------------------------------------------------------------------
// Funzioni di utilità per normalizzare i dati del wizard
// -----------------------------------------------------------------------------

/**
 * Estrae titolo principale, resto del titolo e responsabilità da una stringa
 * in stile "Titolo : complemento / responsabilità".
 *
 * Ritorna array:
 *  ['title' => ..., 'remainder' => ..., 'responsibility' => ...]
 */
function split_title_responsibility(string $raw): array
{
    $raw = trim($raw);

    $responsibility = '';
    $titlePart      = $raw;

    // 1) separa su " / "
    if (strpos($raw, ' / ') !== false) {
        [$titlePart, $respPart] = explode(' / ', $raw, 2);
        $responsibility = trim($respPart);
    }

    $titlePart = trim($titlePart);
    $remainder = '';
    $title     = $titlePart;

    // 2) dentro la parte del titolo, separa su " : "
    if (strpos($titlePart, ' : ') !== false) {
        [$tMain, $tRem] = explode(' : ', $titlePart, 2);
        $title     = trim($tMain);
        $remainder = trim($tRem);
    }

    return [
        'title'          => $title,
        'remainder'      => $remainder,
        'responsibility' => $responsibility,
    ];
}

/**
 * Estrae l'autore principale dalla lista autori (prima riga / prima voce).
 */
function extract_main_author(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    // separa su ; o su nuova riga
    $parts = preg_split('/[;\r\n]+/', $raw);
    $first = trim((string)($parts[0] ?? ''));

    return $first;
}

/**
 * Estrae un anno (4 cifre) dal campo pubblicazione.
 */
function extract_year_from_pub(string $pub): string
{
    if (preg_match('/\b(\d{4})\b/', $pub, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Divide la stringa soggetti in massimo 5 voci (topic1..topic5) usando
 * righe e punto e virgola come separatori.
 *
 * @return array{0:string,1:string,2:string,3:string,4:string}
 */
function split_subjects_to_topics(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['', '', '', '', ''];
    }

    $parts = preg_split('/[\r\n;]+/', $raw);
    $topics = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        // pulizia grezza di eventuali $a, $x, ecc.
        $p = preg_replace('/\$\w/', '', $p);
        $p = trim((string)$p);
        if ($p !== '') {
            $topics[] = $p;
        }
        if (count($topics) >= 5) {
            break;
        }
    }

    while (count($topics) < 5) {
        $topics[] = '';
    }

    return [
        $topics[0] ?? '',
        $topics[1] ?? '',
        $topics[2] ?? '',
        $topics[3] ?? '',
        $topics[4] ?? '',
    ];
}

/**
 * Inserisce un campo MARC in biblio_field.
 */
function add_biblio_field(PDO $pdo, int $bibid, int $tag, string $subfield, string $data): void
{
    $data = trim($data);
    if ($data === '') {
        return;
    }

    $sql = '
        INSERT INTO biblio_field (bibid, tag, subfield_cd, field_data)
        VALUES (:bibid, :tag, :subfield, :data)
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':bibid', $bibid, PDO::PARAM_INT);
    $stmt->bindValue(':tag', $tag, PDO::PARAM_INT);
    $stmt->bindValue(':subfield', $subfield, PDO::PARAM_STR);
    $stmt->bindValue(':data', $data, PDO::PARAM_STR);
    $stmt->execute();
}

// -----------------------------------------------------------------------------
// Lettura POST
// -----------------------------------------------------------------------------

$kind      = (string)($_POST['kind'] ?? '');
$fileParam = (string)($_POST['file'] ?? '');

$isbn      = trim((string)($_POST['isbn'] ?? ''));
$authors   = trim((string)($_POST['authors'] ?? ''));
$titleRaw  = trim((string)($_POST['title'] ?? ''));
$pub       = trim((string)($_POST['pub'] ?? ''));
$phys      = trim((string)($_POST['phys'] ?? ''));
$abstract  = trim((string)($_POST['abstract'] ?? ''));
$subjects  = trim((string)($_POST['subjects'] ?? ''));

$errors = [];

// Controllo minimo
if ($titleRaw === '') {
    $errors[] = 'Il campo Titolo è obbligatorio per creare un nuovo record.';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $errors[] = 'Accesso non valido alla pagina di importazione.';
}

// -----------------------------------------------------------------------------
// Se ci sono errori di base, mostra messaggio e fermati
// -----------------------------------------------------------------------------

if ($errors !== []) {
    ?>
    <section class="page-section">
        <h1>Importazione dati – STEP 3</h1>
        <div class="generic-box" style="margin-top:0.75rem;">
            <?php foreach ($errors as $msg): ?>
                <p><?= h((string)$msg) ?></p>
            <?php endforeach; ?>
        </div>
        <p style="margin-top:1rem;">
            <a href="<?= h($baseUrl) ?>/index.php?page=admin_import">
                Torna allo STEP 1 (selezione file)
            </a>
        </p>
    </section>
    <?php
    return;
}

// -----------------------------------------------------------------------------
// Preparazione valori normalizzati
// -----------------------------------------------------------------------------

// Titolo / rimanente / responsabilità
$titleParts = split_title_responsibility($titleRaw);
$titleMain  = $titleParts['title'];
$titleRem   = $titleParts['remainder'];
$respStmt   = $titleParts['responsibility'];

// Autore principale
$authorMain = extract_main_author($authors);

// Anno da stringa pubblicazione
$year = extract_year_from_pub($pub);

// Soggetti → topic1..topic5
[$topic1, $topic2, $topic3, $topic4, $topic5] = split_subjects_to_topics($subjects);

// Utente staff corrente
$staffUserId = (int)($_SESSION['staff_user_id'] ?? 0);

// -----------------------------------------------------------------------------
// Recupero valori di default per material_cd e collection_cd
// -----------------------------------------------------------------------------
// Per non "indovinare" codici, cerchiamo il primo record esistente in biblio
// e riutilizziamo i suoi valori come default.
// Se il catalogo fosse vuoto, usiamo dei fallback neutri.

$defaultMaterialCd   = '';
$defaultCollectionCd = '';

try {
    $sql = '
        SELECT material_cd, collection_cd
        FROM biblio
        ORDER BY bibid ASC
        LIMIT 1
    ';
    $stmt = $pdo->query($sql);
    $row  = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (is_array($row)) {
        $defaultMaterialCd   = (string)($row['material_cd'] ?? '');
        $defaultCollectionCd = (string)($row['collection_cd'] ?? '');
    }
} catch (Throwable $e) {
    // Ignoriamo: se non riusciamo a leggere, useremo i fallback.
}

if ($defaultMaterialCd === '') {
    $defaultMaterialCd = 'b';   // fallback generico "book"
}
if ($defaultCollectionCd === '') {
    $defaultCollectionCd = 'GEN'; // fallback generico "general"
}

// -----------------------------------------------------------------------------
// Inserimento nel DB (biblio + biblio_field)
// -----------------------------------------------------------------------------

$pdo->beginTransaction();

try {
    // 1) Inserimento in biblio
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
            author,
            responsibility_stmt,
            topic1,
            topic2,
            topic3,
            topic4,
            topic5,
            opac_flg
        ) VALUES (
            NOW(),
            NOW(),
            :user,
            :mat_cd,
            :coll_cd,
            :call1,
            :call2,
            :call3,
            :title,
            :title_rem,
            :author,
            :resp,
            :topic1,
            :topic2,
            :topic3,
            :topic4,
            :topic5,
            :opac
        )
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user', $staffUserId, PDO::PARAM_INT);
    $stmt->bindValue(':mat_cd', $defaultMaterialCd, PDO::PARAM_STR);
    $stmt->bindValue(':coll_cd', $defaultCollectionCd, PDO::PARAM_STR);
    $stmt->bindValue(':call1', '', PDO::PARAM_STR);
    $stmt->bindValue(':call2', '', PDO::PARAM_STR);
    $stmt->bindValue(':call3', '', PDO::PARAM_STR);
    $stmt->bindValue(':title', $titleMain, PDO::PARAM_STR);
    $stmt->bindValue(':title_rem', $titleRem, PDO::PARAM_STR);
    $stmt->bindValue(':author', $authorMain, PDO::PARAM_STR);
    $stmt->bindValue(':resp', $respStmt, PDO::PARAM_STR);
    $stmt->bindValue(':topic1', $topic1, PDO::PARAM_STR);
    $stmt->bindValue(':topic2', $topic2, PDO::PARAM_STR);
    $stmt->bindValue(':topic3', $topic3, PDO::PARAM_STR);
    $stmt->bindValue(':topic4', $topic4, PDO::PARAM_STR);
    $stmt->bindValue(':topic5', $topic5, PDO::PARAM_STR);
    $stmt->bindValue(':opac', 'Y', PDO::PARAM_STR);

    $stmt->execute();

    $bibid = (int)$pdo->lastInsertId();

    // 2) Inserimento in biblio_field – campi MARC essenziali

    // ISBN → 020 $a
    if ($isbn !== '') {
        add_biblio_field($pdo, $bibid, 20, 'a', $isbn);
    }

    // Titolo 245
    if ($titleMain !== '') {
        add_biblio_field($pdo, $bibid, 245, 'a', $titleMain);
    }
    if ($titleRem !== '') {
        add_biblio_field($pdo, $bibid, 245, 'b', $titleRem);
    }
    if ($respStmt !== '') {
        add_biblio_field($pdo, $bibid, 245, 'c', $respStmt);
    }

    // Pubblicazione 260/264
    if ($pub !== '') {
        // tentativo grezzo: dividiamo su " : "
        $pParts = preg_split('/\s*:\s*/', $pub);
        $place  = trim((string)($pParts[0] ?? ''));
        $publ   = trim((string)($pParts[1] ?? ''));
        $yr     = $year;

        if ($place !== '') {
            add_biblio_field($pdo, $bibid, 260, 'a', $place);
        }
        if ($publ !== '') {
            add_biblio_field($pdo, $bibid, 260, 'b', $publ);
        }
        if ($yr !== '') {
            add_biblio_field($pdo, $bibid, 260, 'c', $yr);
        }
    }

    // Descrizione fisica 300
    if ($phys !== '') {
        add_biblio_field($pdo, $bibid, 300, 'a', $phys);
    }

    // Riassunto 520
    if ($abstract !== '') {
        add_biblio_field($pdo, $bibid, 520, 'a', $abstract);
    }

    // Soggetti 650
    $allSubjectsLines = preg_split('/[\r\n;]+/', $subjects);
    if (is_array($allSubjectsLines)) {
        foreach ($allSubjectsLines as $s) {
            $s = trim((string)$s);
            if ($s === '') {
                continue;
            }
            // pulizia $x ecc
            $s = preg_replace('/\$\w/', '', $s);
            $s = trim((string)$s);
            if ($s !== '') {
                add_biblio_field($pdo, $bibid, 650, 'a', $s);
            }
        }
    }

    $pdo->commit();

    ?>
    <section class="page-section">
        <h1>Importazione completata</h1>
        <p>
            Il record è stato creato correttamente nel catalogo.
        </p>
        <ul>
            <li><strong>BIBID assegnato:</strong> <?= h((string)$bibid) ?></li>
            <li><strong>Titolo:</strong> <?= h((string)$titleMain) ?></li>
            <?php if ($authorMain !== ''): ?>
                <li><strong>Autore principale:</strong> <?= h((string)$authorMain) ?></li>
            <?php endif; ?>
        </ul>

        <p style="margin-top:1rem;">
            <a class="btn-secondary" href="<?= h($baseUrl) ?>/index.php?page=item&amp;bibid=<?= (int)$bibid ?>">
                Vai alla scheda OPAC
            </a>
            &nbsp;
            <a class="btn-link" href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_edit&amp;bibid=<?= (int)$bibid ?>">
                Modifica il record in Area staff
            </a>
        </p>

        <p style="margin-top:1.5rem;font-size:0.9rem;color:#555;">
            Puoi ora verificare e rifinire i dati (collocazione, altri soggetti, note, ecc.)
            tramite l’Area staff.
        </p>
    </section>
    <?php

} catch (Throwable $e) {
    $pdo->rollBack();

    ?>
    <section class="page-section">
        <h1>Errore durante l’importazione</h1>
        <p>
            Si è verificato un errore durante la creazione del record nel database.
        </p>
        <div class="generic-box" style="margin-top:0.75rem;">
            <p><strong>Messaggio tecnico:</strong></p>
            <pre style="white-space:pre-wrap;font-size:0.85rem;">
<?= h((string)$e->getMessage()) ?>

            </pre>
        </div>

        <p style="margin-top:1rem;">
            <a href="<?= h($baseUrl) ?>/index.php?page=staff_import_wizard&amp;file=<?= h((string)$fileParam) ?>">
                Torna allo STEP 2 (wizard)
            </a>
        </p>
        <p style="margin-top:0.5rem;">
            <a href="<?= h($baseUrl) ?>/index.php?page=admin_import">
                Torna allo STEP 1 (selezione file)
            </a>
        </p>
    </section>
    <?php
}
