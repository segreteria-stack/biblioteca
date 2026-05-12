<?php
declare(strict_types=1);

/**
 * Scheda dettaglio titolo OPAC.
 *
 * PHP 8.3 – dipende da:
 * - DB::conn() in lib/DB.php
 * - h() in lib/helpers.php
 * - marc_helpers.php per l'uso complementare di biblio_field (MARC)
 */

require_once __DIR__ . '/../lib/marc_helpers.php';

$pdo = DB::conn();

// -----------------------------------------------------------------------------
// Patron hold (prenotazione)
// -----------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../lib/PatronAuth.php';

$patron = PatronAuth::user();
$holdOk = '';
$holdErr = '';

$hasCsrf = function_exists('csrf_check') && function_exists('csrf_token');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'hold_request') {
    if (!$patron) {
        $holdErr = 'Devi accedere per prenotare un titolo.';
    } elseif ($hasCsrf && !csrf_check($_POST['csrf'] ?? '')) {
        $holdErr = 'Token CSRF non valido.';
    } else {
        $bibidPost = (int)($_POST['bibid'] ?? 0);
        if ($bibidPost <= 0) {
            $holdErr = 'Prenotazione non valida.';
        } else {
            try {
                $chk = $pdo->prepare("
                    SELECT holdid FROM biblio_hold
                    WHERE mbrid = :mbrid AND bibid = :bibid LIMIT 1
                ");
                $chk->execute([':mbrid' => (int)$patron['mbrid'], ':bibid' => $bibidPost]);
                $exists = $chk->fetch(PDO::FETCH_ASSOC);

                if ($exists) {
                    $holdOk = 'Hai già una prenotazione attiva per questo titolo.';
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO biblio_hold (bibid, copyid, mbrid, hold_begin_dt)
                        VALUES (:bibid, :copyid, :mbrid, NOW())
                    ");
                    try {
                        $ins->execute([':bibid' => $bibidPost, ':copyid' => null, ':mbrid' => (int)$patron['mbrid']]);
                        $holdOk = 'Prenotazione registrata.';
                    } catch (PDOException $e) {
                        $ins->execute([':bibid' => $bibidPost, ':copyid' => 0, ':mbrid' => (int)$patron['mbrid']]);
                        $holdOk = 'Prenotazione registrata.';
                    }
                }
            } catch (Throwable $e) {
                $holdErr = 'Errore durante la prenotazione.';
            }
        }
    }
}

function fetchBiblioRecord(PDO $pdo, int $bibid): ?array
{
    $sql = '
        SELECT
            b.bibid, b.title, b.title_remainder, b.author, b.responsibility_stmt,
            b.call_nmbr1, b.call_nmbr2, b.call_nmbr3,
            b.topic1, b.topic2, b.topic3, b.topic4, b.topic5,
            b.material_cd, b.collection_cd,
            mt.description AS material_descr,
            cd.description AS collection_descr
        FROM biblio b
        LEFT JOIN material_type_dm mt ON mt.code = b.material_cd
        LEFT JOIN collection_dm     cd ON cd.code = b.collection_cd
        WHERE b.bibid = :bibid AND b.opac_flg = \'Y\' LIMIT 1
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':bibid', $bibid, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

function buildCollocation(array $row): string
{
    $parts = [];
    foreach (['call_nmbr1', 'call_nmbr2', 'call_nmbr3'] as $key) {
        $val = trim((string)($row[$key] ?? ''));
        if ($val !== '') $parts[] = $val;
    }
    return $parts === [] ? '' : implode(' ', $parts);
}

function normalizePubYear(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    if (preg_match('/(\d{4})/', $raw, $m)) return $m[1];
    return $raw;
}

function normalizeIsbnForSearch(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    $clean = preg_replace('/[^0-9Xx]/', '', $raw);
    return is_string($clean) ? strtoupper($clean) : '';
}

// -----------------------------------------------------------------------------
// Disponibilità con conteggio preciso
// -----------------------------------------------------------------------------
function fetchAvailabilityDetail(PDO $pdo, int $bibid): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT c.status_cd, COUNT(*) AS cnt,
                   COALESCE(s.description, c.status_cd) AS status_desc
            FROM biblio_copy c
            LEFT JOIN biblio_status_dm s ON s.code = c.status_cd
            WHERE c.bibid = :bibid AND c.status_cd NOT IN ('dis', 'lst')
            GROUP BY c.status_cd, s.description
        ");
        $stmt->execute([':bibid' => $bibid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['state' => 'unknown', 'label' => '—', 'available' => 0, 'total' => 0, 'out' => 0];
    }

    $total = 0;
    $available = 0;
    $out = 0;
    $statusCounts = []; // code => [cnt, desc]
    foreach ($rows as $r) {
        $cnt  = (int)($r['cnt'] ?? 0);
        $code = (string)($r['status_cd'] ?? '');
        $desc = (string)($r['status_desc'] ?? $code);
        $total += $cnt;
        $statusCounts[$code] = ['cnt' => $cnt, 'desc' => $desc];
        if ($code === 'in') $available += $cnt;
        if (in_array($code, ['out', 'ln'], true)) $out += $cnt;
    }

    if ($total === 0) {
        return ['state' => 'unknown', 'label' => 'Nessuna copia', 'available' => 0, 'total' => 0, 'out' => 0];
    }
    if ($available > 0) {
        $label = $available . ' cop' . ($available > 1 ? 'ie' : 'ia') . ' disponibil' . ($available > 1 ? 'i' : 'e');
        return ['state' => 'available', 'label' => $label, 'available' => $available, 'total' => $total, 'out' => $out];
    }

    // Nessuna copia disponibile: mostra il dettaglio degli stati presenti
    $prio = ['out' => 1, 'ln' => 1, 'hld' => 2, 'mnd' => 3, 'ord' => 4, 'crt' => 5];
    uasort($statusCounts, static fn($a, $b) => ($prio[array_search($a, $statusCounts, true)] ?? 9) <=> ($prio[array_search($b, $statusCounts, true)] ?? 9));
    $parts = [];
    foreach ($statusCounts as $code => $info) {
        $parts[] = $info['cnt'] . ' ' . strtolower($info['desc']);
    }
    $state = isset($statusCounts['hld']) && count($statusCounts) === 1 ? 'reserved' : 'unavailable';
    $label = implode(' · ', $parts);
    return ['state' => $state, 'label' => $label, 'available' => 0, 'total' => $total, 'out' => $out];
}

// -----------------------------------------------------------------------------
// Dettaglio singole copie
// -----------------------------------------------------------------------------
function fetchCopiesDetail(PDO $pdo, int $bibid): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT c.copyid, c.barcode_nmbr, c.status_cd, c.due_back_dt,
                   m.first_name, m.last_name,
                   COALESCE(s.description, c.status_cd) AS status_desc
            FROM biblio_copy c
            LEFT JOIN member m ON c.mbrid = m.mbrid
            LEFT JOIN biblio_status_dm s ON s.code = c.status_cd
            WHERE c.bibid = :bibid AND c.status_cd NOT IN ('dis', 'lst')
            ORDER BY c.copyid
        ");
        $stmt->execute([':bibid' => $bibid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function copyStatusLabel(string $code, string $dbDescription = ''): string
{
    if ($dbDescription !== '') return $dbDescription;
    return match(strtolower($code)) {
        'in'  => 'Disponibile',
        'out' => 'In prestito',
        'ln'  => 'In prestito',
        'hld' => 'Prenotato',
        'mnd' => 'In manutenzione',
        'dis' => 'Scartato',
        'lst' => 'Perduto',
        'ord' => 'Ordinato',
        'crt' => 'Da reintegrare',
        default => $code
    };
}

// -----------------------------------------------------------------------------
// Link diretti a servizi esterni
// -----------------------------------------------------------------------------
function buildExternalLinks(?string $isbnRaw, string $title, string $author): array
{
    $links = [];
    $isbn = normalizeIsbnForSearch($isbnRaw);

    if ($isbn !== '') {
        $links[] = ['label' => 'WorldCat',    'url' => 'https://search.worldcat.org/search?q=ISBN:' . urlencode($isbn), 'icon' => '🌐'];
        $links[] = ['label' => 'Google Books','url' => 'https://books.google.com/books?vid=ISBN' . urlencode($isbn),    'icon' => '📚'];
        $links[] = ['label' => 'OpenLibrary', 'url' => 'https://openlibrary.org/isbn/' . urlencode($isbn),              'icon' => '📖'];
    } else {
        $q = trim($title . ' ' . $author);
        if ($q !== '') {
            $links[] = ['label' => 'WorldCat', 'url' => 'https://search.worldcat.org/search?q=' . urlencode($q), 'icon' => '🌐'];
        }
    }
    return $links;
}

function fetchOtherTitlesByAuthor(PDO $pdo, string $author, int $excludeBibid, int $limit = 5): array
{
    $author = trim($author);
    if ($author === '') return [];
    $limit = max(1, (int)$limit);
    try {
        $sql = '
            SELECT b.bibid, b.title, b.title_remainder, idx.pub_year
            FROM biblio b
            LEFT JOIN biblio_index_ext idx ON idx.bibid = b.bibid
            WHERE b.author = :author AND b.bibid <> :bibid
            ORDER BY idx.pub_year DESC, b.title ASC
            LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':author', $author, PDO::PARAM_STR);
        $stmt->bindValue(':bibid', $excludeBibid, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (PDOException $e) {
        return [];
    }
}

// -----------------------------------------------------------------------------
// Recupero campi MARC estesi da SBN
// FIX: tag 300 $a → 'descrizione_fisica' (non 'note')
//      tag 651 $a aggiunto (soggetti geografici)
//      ogni 650/651 $a è un soggetto autonomo
// -----------------------------------------------------------------------------
function fetchMarcFields(PDO $pdo, int $bibid): array
{
    $fields = [
        'soggetti'           => [],
        'dewey'              => null,
        'lingua'             => null,
        'paese'              => null,
        'collezione'         => null,
        'titolo_uniforme'    => null,
        'note'               => null,
        'abstract'           => null,
        'indice'             => null,
        'bibliografia'       => null,
        'bid_sbn'            => null,
        'luogo'              => null,
        'descrizione_fisica' => null, // FIX: separato da 'note'
    ];

    try {
        $st = $pdo->prepare("
            SELECT tag, subfield_cd, field_data 
            FROM biblio_field 
            WHERE bibid = ? AND tag IN (
                82, 240, 260, 41, 44, 90,
                300, 490, 500, 504, 505, 520,
                650, 651, 901
            )
            ORDER BY tag, subfield_cd
        ");
        $st->execute([$bibid]);

        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $tag = (int)$row['tag'];
            $sub = $row['subfield_cd'];
            $val = trim($row['field_data']);
            if ($val === '') continue;

            switch ($tag) {
                case 82:
                    if ($sub === 'a') $fields['dewey'] = $val;
                    break;
                case 41:
                    if ($sub === 'a') $fields['lingua'] = $val;
                    break;
                case 44:
                    if ($sub === 'a') $fields['paese'] = $val;
                    break;
                case 90:
                case 901:
                    if ($sub === 'a' && str_starts_with($val, 'IT\\ICCU')) {
                        $fields['bid_sbn'] = $val;
                    }
                    break;
                case 240:
                    if ($sub === 'a') $fields['titolo_uniforme'] = $val;
                    break;
                case 260:
                    if ($sub === 'a') $fields['luogo'] = $val;
                    break;
                case 300:
                    // FIX: descrizione fisica ("527 p. ; 21 cm") — NON è una nota testuale
                    if ($sub === 'a') $fields['descrizione_fisica'] = $val;
                    break;
                case 490:
                    if ($sub === 'a') $fields['collezione'] = $val;
                    break;
                case 500:
                    if ($sub === 'a') $fields['note'] = $val;
                    break;
                case 504:
                    if ($sub === 'a') $fields['bibliografia'] = $val;
                    break;
                case 505:
                    if ($sub === 'a') $fields['indice'] = $val;
                    break;
                case 520:
                    if ($sub === 'a') $fields['abstract'] = $val;
                    break;
                case 650: // soggetti topici
                case 651: // FIX: soggetti geografici, prima ignorati
                    if ($sub === 'a') $fields['soggetti'][] = $val;
                    break;
            }
        }
    } catch (PDOException $e) {
        // non bloccante
    }

    return $fields;
}

// -----------------------------------------------------------------------------
// Lettura parametro bibid
// -----------------------------------------------------------------------------
$bibid = isset($_GET['bibid']) ? (int)$_GET['bibid'] : 0;

if ($bibid <= 0) {
    http_response_code(400);
    ?>
    <section class="page-section">
        <h1>Record non valido</h1>
        <p>Il record richiesto non è valido o manca il parametro <code>bibid</code>.</p>
        <p><a class="btn-secondary" href="index.php?page=search">Torna alla ricerca</a></p>
    </section>
    <?php
    return;
}

// -----------------------------------------------------------------------------
// Recupero dati da DB
// -----------------------------------------------------------------------------
try {
    $record = fetchBiblioRecord($pdo, $bibid);
} catch (PDOException $e) {
    http_response_code(500);
    ?>
    <section class="page-section">
        <h1>Errore nella scheda titolo</h1>
        <p>Si è verificato un errore nel recupero dei dati dal catalogo.</p>
    </section>
    <?php
    return;
}

if ($record === null) {
    http_response_code(404);
    ?>
    <section class="page-section">
        <h1>Record non trovato</h1>
        <p>Il titolo richiesto non è presente nel catalogo.</p>
        <p><a class="btn-secondary" href="index.php?page=search">Torna alla ricerca</a></p>
    </section>
    <?php
    return;
}

// -----------------------------------------------------------------------------
// Dati MARC complementari
// -----------------------------------------------------------------------------
$marcRows  = marc_load_fields($bibid);
$marcIndex = marc_build_index($marcRows);
$logical   = marc_extract_logical_fields($marcIndex, $record);
$marcExtra = fetchMarcFields($pdo, $bibid);

// -----------------------------------------------------------------------------
// Preparazione dati per la vista
// -----------------------------------------------------------------------------
$title          = trim((string)($record['title'] ?? ''));
$titleRemainder = trim((string)($record['title_remainder'] ?? ''));
$fullTitle      = trim($title . ' ' . $titleRemainder);
if ($fullTitle === '') $fullTitle = '[Senza titolo]';

$displayTitle    = $title !== '' ? $title : $fullTitle;
$displaySubtitle = $titleRemainder;
$author          = trim((string)($record['author'] ?? ''));
$responsibility  = trim((string)($record['responsibility_stmt'] ?? ''));
$materialDescr   = trim((string)($record['material_descr'] ?? ''));
$collectionDescr = trim((string)($record['collection_descr'] ?? ''));
$collocation     = buildCollocation($record);

// Soggetti centralizzati — marc_get_subjects() gestisce topic1..5 + 650/651 $a,
// normalizzazione maiuscole, dedup case-insensitive e scarto valori non validi.
$subjects = marc_get_subjects($pdo, $bibid, $record);

$publisher = trim((string)($logical['publisher'] ?? ''));
$pubYear   = trim((string)($logical['pub_year'] ?? ''));
$pages     = trim((string)($logical['pages'] ?? ''));
if ($pubYear !== '') $pubYear = normalizePubYear($pubYear);

// FIX: usa solo 520 $a (summary) come riassunto — le note 500 $a non sono abstract
$summaryTxt = '';
if (!empty($logical['summary'])) $summaryTxt = trim((string)$logical['summary']);

// FIX: usa descrizione_fisica (tag 300 $a) come fallback se $pages è vuoto
$pagesDisplay = $pages !== '' ? $pages : ($marcExtra['descrizione_fisica'] ?? '');

$isbnRaw     = trim((string)($logical['isbn'] ?? ''));
$isbnDisplay = $isbnRaw;
$isbnSearch  = normalizeIsbnForSearch($isbnRaw);
$oclcRaw     = trim((string)(marc_first($marcIndex, 35, 'a') ?? ''));
$oclcDisplay = $oclcRaw;

// -----------------------------------------------------------------------------
// Dati disponibilità e copie
// -----------------------------------------------------------------------------
$availability      = fetchAvailabilityDetail($pdo, $bibid);
$availabilityLabel = $availability['label'] ?? '';
$availabilityState = $availability['state'] ?? 'unknown';
$availabilityCount = (int)($availability['available'] ?? 0);
$allCopies         = fetchCopiesDetail($pdo, $bibid);
$externalLinks     = buildExternalLinks($isbnRaw, $fullTitle, $author);

$alreadyHeld = false;
try {
    if ($patron) {
        $stHeld = $pdo->prepare("SELECT holdid FROM biblio_hold WHERE mbrid=:mbrid AND bibid=:bibid LIMIT 1");
        $stHeld->execute([':mbrid' => (int)$patron['mbrid'], ':bibid' => $bibid]);
        $alreadyHeld = (bool)$stHeld->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) { $alreadyHeld = false; }

$hasTechData = ($isbnDisplay !== '' || $oclcDisplay !== '' || !empty($marcExtra));

$otherByAuthor   = [];
$authorSearchUrl = '';
if ($author !== '') {
    $otherByAuthor   = fetchOtherTitlesByAuthor($pdo, $author, $bibid, 5);
    $authorSearchUrl = 'index.php?' . http_build_query(['page' => 'search', 'q' => $author]);
}

// -----------------------------------------------------------------------------
// Copertina
// -----------------------------------------------------------------------------
$isbnForJs      = CoverService::getIsbnForJs($isbnRaw);
$isbn13ForJs    = CoverService::toIsbn13($isbnRaw);
$placeholderUrl = CoverService::placeholderUrl($fullTitle);
$gbApiKey       = $GLOBALS['cfg']['google_books']['api_key'] ?? '';
$initialCoverUrl = CoverService::getCoverUrl($isbnRaw, $fullTitle, $author);
$needsCoverJs   = ($isbnForJs !== '' && $gbApiKey !== '' && !CoverService::hasLocalCover($isbn13ForJs));

?>
<section class="page-section page-item">
    <p>
        <a class="btn-secondary" href="javascript:history.back()">
            ← Torna ai risultati
        </a>
    </p>

    <div class="item-layout">
        <aside class="item-sidebar">
            <div class="item-cover-wrapper">
                <img
                    id="item-cover-img"
                    src="<?= h($initialCoverUrl) ?>"
                    alt="Copertina di <?= h($fullTitle) ?>"
                    class="item-cover-img"
                    loading="eager"
                    onerror="this.src='<?= h($placeholderUrl) ?>'; this.onerror=null;"
                    data-isbn="<?= h($isbnForJs) ?>"
                    data-isbn13="<?= h($isbn13ForJs) ?>"
                    data-title="<?= h($fullTitle) ?>"
                    data-author="<?= h($author) ?>"
                    data-placeholder="<?= h($placeholderUrl) ?>"
                >
            </div>

            <?php if ($holdOk !== '' || $holdErr !== ''): ?>
                <div class="item-sidebar-block">
                    <span class="item-sidebar-label">Prenotazione</span>
                    <?php if ($holdOk !== ''): ?>
                        <p style="margin:6px 0 0 0;color:#0a7"><?= h($holdOk) ?></p>
                    <?php else: ?>
                        <p style="margin:6px 0 0 0;color:#b00020"><?= h($holdErr) ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($availabilityLabel !== ''): ?>
                <div class="item-sidebar-block">
                    <span class="item-sidebar-label">Disponibilità</span>
                    <span class="availability-badge availability-<?= h($availabilityState) ?>">
                        <?= h($availabilityLabel) ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($patron): ?>
                <div class="item-sidebar-block">
                    <span class="item-sidebar-label">Area Patron</span>
                    <?php if ($alreadyHeld): ?>
                        <p style="margin:6px 0 0 0;color:#64748b">
                            Hai già una prenotazione attiva per questo titolo.
                            <br>
                            <a href="index.php?page=patron_area&amp;tab=holds">Vedi prenotazioni</a>
                        </p>
                    <?php elseif ($availabilityCount > 0): ?>
                        <p style="margin:6px 0 0 0;color:#166534;font-size:0.9rem;">
                            <?= $availabilityCount ?> copia<?= $availabilityCount > 1 ? ' disponibili' : ' disponibile' ?>.
                            Vieni in biblioteca a ritirarla.
                        </p>
                    <?php else: ?>
                        <form method="post" style="margin:8px 0 0 0">
                            <?php if ($hasCsrf): ?>
                                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <?php endif; ?>
                            <input type="hidden" name="action" value="hold_request">
                            <input type="hidden" name="bibid" value="<?= (int)$bibid ?>">
                            <button type="submit" class="btn-primary" style="width:100%;">
                                Prenota questo titolo
                            </button>
                        </form>
                        <p style="margin:8px 0 0 0;color:#64748b;font-size:13px">
                            Tutte le copie sono in prestito. Ti avviseremo quando sarà disponibile.
                        </p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="item-sidebar-block">
                    <span class="item-sidebar-label">Area Patron</span>
                    <p style="margin:6px 0 0 0;color:#64748b">
                        Per prenotare un titolo devi <a href="index.php?page=patron_login">accedere</a>.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($collocation !== ''): ?>
                <div class="item-sidebar-block item-collocation-block">
                    <span class="item-sidebar-label">Collocazione</span>
                    <span class="item-collocation-value"><?= h($collocation) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($allCopies !== []): ?>
                <div class="item-sidebar-block item-copies-block">
                    <span class="item-sidebar-label">Copie in biblioteca</span>
                    <ul class="item-copy-list">
                        <?php foreach ($allCopies as $copy):
                            $copyStatus  = copyStatusLabel($copy['status_cd'], (string)($copy['status_desc'] ?? ''));
                            $isAvailable = in_array(strtolower($copy['status_cd']), ['in', 'crt'], true);
                            $statusClass = $isAvailable ? 'copy-status-ok' : 'copy-status-busy';
                        ?>
                            <li class="item-copy-item">
                                <div class="item-copy-barcode"><?= h($copy['barcode_nmbr']) ?></div>
                                <div class="item-copy-status <?= $statusClass ?>"><?= h($copyStatus) ?></div>
                                <?php if (!empty($copy['due_back_dt']) && $copy['due_back_dt'] !== '0000-00-00' && !$isAvailable): ?>
                                    <div class="item-copy-due">
                                        fino al <?= h(date('d/m/Y', strtotime($copy['due_back_dt']))) ?>
                                        <?php if (!empty($copy['last_name'])): ?>
                                            — <?= h(($copy['first_name'] ?? '') . ' ' . $copy['last_name']) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($externalLinks !== []): ?>
                <div class="item-sidebar-block item-external-block">
                    <span class="item-sidebar-label">Altre risorse</span>
                    <ul class="item-external-list">
                        <?php foreach ($externalLinks as $link): ?>
                            <li>
                                <a href="<?= h($link['url']) ?>" target="_blank" rel="noopener noreferrer">
                                    <span class="item-external-icon"><?= $link['icon'] ?></span>
                                    <?= h($link['label']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </aside>

        <div class="item-main">
            <header class="item-header">
                <h1 class="item-title"><?= h($displayTitle) ?></h1>

                <?php if ($displaySubtitle !== ''): ?>
                    <p class="item-subtitle"><?= h($displaySubtitle) ?></p>
                <?php endif; ?>

                <dl class="item-header-meta">
                    <?php if ($author !== ''): ?>
                        <div class="item-header-row">
                            <dt>Autore</dt>
                            <dd>
                                <?php if ($authorSearchUrl !== ''): ?>
                                    <a href="<?= h($authorSearchUrl) ?>"><?= h($author) ?></a>
                                <?php else: ?>
                                    <?= h($author) ?>
                                <?php endif; ?>
                            </dd>
                        </div>
                    <?php endif; ?>

                    <?php if ($responsibility !== ''): ?>
                        <div class="item-header-row">
                            <dt>Responsabilità</dt>
                            <dd><?= h($responsibility) ?></dd>
                        </div>
                    <?php endif; ?>

                    <?php if ($publisher !== ''): ?>
                        <div class="item-header-row">
                            <dt>Editore</dt>
                            <dd><?= h($publisher) ?></dd>
                        </div>
                    <?php endif; ?>

                    <?php if ($pubYear !== ''): ?>
                        <div class="item-header-row">
                            <dt>Anno</dt>
                            <dd><?= h($pubYear) ?></dd>
                        </div>
                    <?php endif; ?>

                    <?php if ($pagesDisplay !== ''): ?>
                        <div class="item-header-row">
                            <dt>Pagine</dt>
                            <dd><?= h($pagesDisplay) ?></dd>
                        </div>
                    <?php endif; ?>

                    <?php if ($materialDescr !== ''): ?>
                        <div class="item-header-row">
                            <dt>Tipo di materiale</dt>
                            <dd><?= h($materialDescr) ?></dd>
                        </div>
                    <?php endif; ?>

                    <?php if ($collectionDescr !== ''): ?>
                        <div class="item-header-row">
                            <dt>Sezione</dt>
                            <dd><?= h($collectionDescr) ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </header>

            <?php
            // FIX: riassunto solo da 520 $a, mai da note 500 $a
            $displayedSummary = '';
            $summarySource    = '';
            if ($summaryTxt !== '') {
                $displayedSummary = $summaryTxt;
                $summarySource    = 'local';
            } elseif (!empty($marcExtra['abstract'])) {
                $displayedSummary = $marcExtra['abstract'];
                $summarySource    = 'sbn';
            }
            ?>

            <?php if ($displayedSummary !== ''): ?>
                <section class="item-summary">
                    <h2><?= $summarySource === 'sbn' ? 'Riassunto' : 'Riassunto e descrizione del contenuto' ?></h2>
                    <p><?= nl2br(h($displayedSummary)) ?></p>
                </section>
            <?php endif; ?>

            <?php if (!empty($marcExtra['indice'])): ?>
                <section class="item-summary">
                    <h2>Indice</h2>
                    <p><?= nl2br(h($marcExtra['indice'])) ?></p>
                </section>
            <?php endif; ?>

            <?php if (!empty($marcExtra['bibliografia'])): ?>
                <section class="item-summary">
                    <h2>Bibliografia</h2>
                    <p><?= nl2br(h($marcExtra['bibliografia'])) ?></p>
                </section>
            <?php endif; ?>

            <?php
            // Mostra note solo se diverse dal riassunto già visualizzato
            $noteContent    = !empty($marcExtra['note']) ? trim($marcExtra['note']) : '';
            $summaryContent = trim($displayedSummary);
            $showNote       = $noteContent !== '' && $noteContent !== $summaryContent;
            ?>
            <?php if ($showNote): ?>
                <section class="item-summary">
                    <h2>Note</h2>
                    <p><?= nl2br(h($marcExtra['note'])) ?></p>
                </section>
            <?php endif; ?>

            <?php if ($hasTechData): ?>
                <section class="item-tech">
                    <h2>Dati tecnici</h2>
                    <dl class="item-meta">
                        <?php if ($isbnDisplay !== ''): ?>
                            <div class="item-meta-row">
                                <dt>ISBN</dt>
                                <dd><?= h($isbnDisplay) ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if ($oclcDisplay !== ''): ?>
                            <div class="item-meta-row">
                                <dt>OCLC</dt>
                                <dd><?= h($oclcDisplay) ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($marcExtra['bid_sbn'])): ?>
                            <div class="item-meta-row">
                                <dt>BID SBN</dt>
                                <dd>
                                    <a href="http://id.sbn.it/bid/<?= h(str_replace(['IT\\ICCU\\', '\\'], '', $marcExtra['bid_sbn'])) ?>" target="_blank">
                                        <?= h($marcExtra['bid_sbn']) ?> → OPAC
                                    </a>
                                </dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($marcExtra['dewey'])): ?>
                            <div class="item-meta-row">
                                <dt>Classificazione Dewey</dt>
                                <dd><?= h($marcExtra['dewey']) ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($marcExtra['lingua'])): ?>
                            <div class="item-meta-row">
                                <dt>Lingua</dt>
                                <dd><?= h($marcExtra['lingua']) ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($marcExtra['paese'])): ?>
                            <div class="item-meta-row">
                                <dt>Paese</dt>
                                <dd><?= h($marcExtra['paese']) ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($marcExtra['luogo'])): ?>
                            <div class="item-meta-row">
                                <dt>Luogo di pubblicazione</dt>
                                <dd><?= h($marcExtra['luogo']) ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($marcExtra['collezione'])): ?>
                            <div class="item-meta-row">
                                <dt>Collana</dt>
                                <dd><?= h($marcExtra['collezione']) ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($marcExtra['titolo_uniforme'])): ?>
                            <div class="item-meta-row">
                                <dt>Titolo uniforme</dt>
                                <dd><?= h($marcExtra['titolo_uniforme']) ?></dd>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($marcExtra['descrizione_fisica'])): ?>
                            <div class="item-meta-row">
                                <dt>Descrizione fisica</dt>
                                <dd><?= h($marcExtra['descrizione_fisica']) ?></dd>
                            </div>
                        <?php endif; ?>
                    </dl>
                </section>
            <?php endif; ?>

            <?php if ($author !== '' && $otherByAuthor !== []): ?>
                <section class="item-other-titles">
                    <h2>Altri titoli di <?= h($author) ?></h2>
                    <ul class="item-other-list result-list">
                        <?php foreach ($otherByAuthor as $rowOther):
                            $otherBibid  = (int)($rowOther['bibid'] ?? 0);
                            $otTitleVal  = trim((string)($rowOther['title'] ?? ''));
                            $otRemainder = trim((string)($rowOther['title_remainder'] ?? ''));
                            $otFullTitle = trim($otTitleVal . ' ' . $otRemainder);
                            if ($otFullTitle === '') $otFullTitle = '[Senza titolo]';
                            $otYear = normalizePubYear(trim((string)($rowOther['pub_year'] ?? '')));
                        ?>
                            <li class="result-item">
                                <h3 class="result-title">
                                    <a href="index.php?page=item&amp;bibid=<?= $otherBibid ?>">
                                        <?= h($otFullTitle) ?>
                                    </a>
                                    <?php if ($otYear !== ''): ?>
                                        <span class="item-other-year">(<?= h($otYear) ?>)</span>
                                    <?php endif; ?>
                                </h3>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($authorSearchUrl !== ''): ?>
                        <p class="item-other-see-all">
                            <a href="<?= h($authorSearchUrl) ?>" class="btn-link">
                                Vedi tutti i titoli di <?= h($author) ?>
                            </a>
                        </p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div><!-- /.item-main -->
    </div><!-- /.item-layout -->

    <?php if ($subjects !== []): ?>
        <div class="item-subjects">
            <h2>Soggetti</h2>
            <div class="result-tags">
                <?php foreach ($subjects as $subject): ?>
                    <a class="tag" href="index.php?page=search&amp;subject=<?= urlencode($subject) ?>">
                        <?= h($subject) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</section>

<?php if ($needsCoverJs): ?>
<script>
(function () {
    const img         = document.getElementById('item-cover-img');
    if (!img) return;

    const isbn        = <?= json_encode($isbnForJs) ?>;
    const isbn13      = <?= json_encode($isbn13ForJs) ?>;
    const title       = <?= json_encode($fullTitle) ?>;
    const author      = <?= json_encode($author) ?>;
    const apiKey      = <?= json_encode($gbApiKey) ?>;
    const placeholder = <?= json_encode($placeholderUrl) ?>;

    function saveCoverOnServer(imageUrl) {
        if (!isbn13) return;
        fetch('cover_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ isbn: isbn13, url: imageUrl })
        }).catch(() => {});
    }

    function fetchGoogleBooks(q) {
        const url = 'https://www.googleapis.com/books/v1/volumes'
            + '?q=' + encodeURIComponent(q)
            + '&maxResults=1'
            + '&fields=items(volumeInfo/imageLinks)'
            + '&key=' + encodeURIComponent(apiKey);

        return fetch(url)
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                const links = data?.items?.[0]?.volumeInfo?.imageLinks;
                if (!links) return null;
                const src = links.thumbnail || links.smallThumbnail
                    || links.medium || links.large || links.extraLarge;
                return src ? src.replace(/^http:\/\//, 'https://') : null;
            })
            .catch(() => null);
    }

    function tryOpenLibrary() {
        if (!isbn13) return Promise.resolve(null);
        return new Promise((resolve) => {
            const testImg = new Image();
            const olUrl = 'https://covers.openlibrary.org/b/isbn/'
                + encodeURIComponent(isbn13) + '-M.jpg?default=false';
            testImg.onload  = () => resolve(olUrl);
            testImg.onerror = () => resolve(null);
            testImg.src = olUrl;
        });
    }

    async function loadCover() {
        let src = null;

        if (isbn) src = await fetchGoogleBooks('isbn:' + isbn);
        if (!src && isbn13 && isbn13 !== isbn) src = await fetchGoogleBooks('isbn:' + isbn13);
        if (!src && title) {
            let q = 'intitle:' + title;
            if (author) q += ' inauthor:' + author;
            src = await fetchGoogleBooks(q);
        }
        if (!src) src = await tryOpenLibrary();

        if (src) {
            img.src = src;
            saveCoverOnServer(src);
        }
    }

    loadCover();
})();
</script>
<?php endif; ?>