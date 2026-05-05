<?php
/**
 * Ricerca catalogo – Area Staff
 *
 * Estende la ricerca pubblica (search.php) con:
 *  - Filtro per collocazione (call_nmbr1 / call_nmbr2 / call_nmbr3, LIKE in OR)
 *  - Filtro per collezione (collection_dm, <select>)
 *  - Info aggiuntive nella scheda risultato: segnatura, collezione, BIBID
 *  - Link diretto "Modifica" → staff_catalog_edit
 *
 * Logica query allineata a search.php:
 *  - DB::conn() per la connessione
 *  - PAGE_SIZE dalla config
 *  - Tutte le parole di "q" in AND, campi in OR per parola
 *  - Soggetti MARC 6xx via EXISTS su biblio_field
 *  - pub_year, abstract_520, isbn_020 via subquery
 *
 * PHP 8.3 · PDO · MariaDB
 *
 * @package BibliotecaResistenza\Pages
 */

declare(strict_types=1);

/** @var \PDO $pdo */
$pdo = DB::conn();

// -----------------------------------------------------------------------------
// Protezione: solo staff autenticato
// -----------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    $baseUrl  = function_exists('base_url') ? base_url() : '';
    $redirect = 'staff_search';
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=' . urlencode($redirect));
    exit;
}

$baseUrl = function_exists('base_url') ? base_url() : '';

// -----------------------------------------------------------------------------
// Helper: tronca abstract (identico a search.php)
// -----------------------------------------------------------------------------
function staff_search_trim_abstract(string $text, int $maxChars = 350): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (strlen($text) <= $maxChars) {
        return $text;
    }
    $snippet = substr($text, 0, $maxChars);
    $lastDot = strrpos($snippet, '.');
    if ($lastDot !== false && $lastDot > (int) floor($maxChars * 0.4)) {
        $snippet = substr($snippet, 0, $lastDot + 1);
    }
    return rtrim($snippet) . '…';
}

// -----------------------------------------------------------------------------
// Helper: normalizza ISBN per Google Books (identico a search.php)
// -----------------------------------------------------------------------------
function staff_search_normalize_isbn(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $clean = preg_replace('/[^0-9Xx]/', '', $raw);
    if (!is_string($clean)) {
        return '';
    }
    return strtoupper($clean);
}

// -----------------------------------------------------------------------------
// Helper: segnatura formattata da tre segmenti
// -----------------------------------------------------------------------------
function staff_search_call_nmbr(string $n1, string $n2, string $n3): string
{
    return implode(' / ', array_filter([$n1, $n2, $n3], fn($v) => $v !== ''));
}

// -----------------------------------------------------------------------------
// Parametri GET
// -----------------------------------------------------------------------------
$qRaw        = trim((string) ($_GET['q']             ?? ''));
$qCallNmbr   = trim((string) ($_GET['call_nmbr']     ?? ''));
$qCollection = (int)          ($_GET['collection_cd'] ?? 0);
$sortRaw     = trim((string) ($_GET['sort']           ?? 'title_asc'));
$page        = max(1, (int)  ($_GET['p']              ?? 1));

$q         = $qRaw;
$hasSearch = ($q !== '' || $qCallNmbr !== '' || $qCollection > 0);

$sortOptions = [
    'title_asc'   => 'Titolo (A → Z)',
    'title_desc'  => 'Titolo (Z → A)',
    'author_asc'  => 'Autore (A → Z)',
    'author_desc' => 'Autore (Z → A)',
    'year_desc'   => 'Anno (dal più recente)',
    'year_asc'    => 'Anno (dal meno recente)',
    'bibid_desc'  => 'BIBID (più recenti)',
];
$sort = array_key_exists($sortRaw, $sortOptions) ? $sortRaw : 'title_asc';

$perPage = defined('PAGE_SIZE') ? (int) PAGE_SIZE : 20;
if ($perPage <= 0) {
    $perPage = 20;
}

// -----------------------------------------------------------------------------
// Carica collezioni per la <select>
// -----------------------------------------------------------------------------
$collections = [];
try {
    $stmt        = $pdo->query('SELECT code, description FROM collection_dm ORDER BY description');
    $collections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    // Non bloccante
}

// -----------------------------------------------------------------------------
// Costruzione ed esecuzione query
// -----------------------------------------------------------------------------
$results = [];
$total   = 0;
$pages   = 1;
$errors  = [];

if ($hasSearch) {

    $whereParts = [];
    $params     = [];

    // 1) Testo libero "q" — AND tra parole, OR tra campi (identico a search.php)
    if ($q !== '') {
        $terms = preg_split('/\s+/', $q);
        if (is_array($terms)) {
            foreach ($terms as $term) {
                $term = trim($term);
                if ($term === '') {
                    continue;
                }
                $pattern  = '%' . $term . '%';
                $subParts = [];

                foreach ([
                    'b.title', 'b.title_remainder', 'b.author',
                    'b.topic1', 'b.topic2', 'b.topic3', 'b.topic4', 'b.topic5',
                ] as $col) {
                    $subParts[] = $col . ' LIKE ?';
                    $params[]   = $pattern;
                }

                // Soggetti MARC 6xx (a,x,y,z) via EXISTS
                $subParts[] = "
                    EXISTS (
                        SELECT 1
                        FROM biblio_field bfq
                        WHERE bfq.bibid = b.bibid
                          AND bfq.tag BETWEEN 600 AND 699
                          AND bfq.subfield_cd IN ('a','x','y','z')
                          AND bfq.field_data LIKE ?
                    )
                ";
                $params[] = $pattern;

                $whereParts[] = '(' . implode(' OR ', $subParts) . ')';
            }
        }
    }

    // 2) Collocazione: call_nmbr1 OR call_nmbr2 OR call_nmbr3
    if ($qCallNmbr !== '') {
        $pattern      = '%' . $qCallNmbr . '%';
        $whereParts[] = '(b.call_nmbr1 LIKE ? OR b.call_nmbr2 LIKE ? OR b.call_nmbr3 LIKE ?)';
        $params[]     = $pattern;
        $params[]     = $pattern;
        $params[]     = $pattern;
    }

    // 3) Collezione: FK esatta
    if ($qCollection > 0) {
        $whereParts[] = 'b.collection_cd = ?';
        $params[]     = $qCollection;
    }

    $whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';
    $fromSql  = 'FROM biblio b LEFT JOIN collection_dm c ON c.code = b.collection_cd';

    $orderBySql = match ($sort) {
        'title_desc'  => 'ORDER BY b.title DESC',
        'author_asc'  => 'ORDER BY b.author ASC, b.title ASC',
        'author_desc' => 'ORDER BY b.author DESC, b.title ASC',
        'year_desc'   => 'ORDER BY (pub_year_num IS NULL) ASC, pub_year_num DESC, b.title ASC',
        'year_asc'    => 'ORDER BY (pub_year_num IS NULL) ASC, pub_year_num ASC, b.title ASC',
        'bibid_desc'  => 'ORDER BY b.bibid DESC',
        default       => 'ORDER BY b.title ASC',
    };

    try {
        // Conteggio totale
        $countStmt = $pdo->prepare("SELECT COUNT(*) $fromSql $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        if ($total > 0) {
            $pages = (int) ceil($total / $perPage);
            if ($page > $pages) {
                $page = $pages;
            }
            $offset = ($page - 1) * $perPage;

            $sql = "
                SELECT
                    b.bibid,
                    b.title,
                    b.title_remainder,
                    b.author,
                    b.call_nmbr1,
                    b.call_nmbr2,
                    b.call_nmbr3,
                    b.collection_cd,
                    b.opac_flg,
                    b.has_cover,
                    c.description AS collection_descr,
                    (
                        SELECT bf.field_data
                        FROM biblio_field bf
                        WHERE bf.bibid = b.bibid
                          AND bf.tag IN (260, 264)
                          AND bf.subfield_cd = 'c'
                        ORDER BY bf.tag, bf.fieldid
                        LIMIT 1
                    ) AS pub_year_raw,
                    (
                        SELECT
                            CASE
                                WHEN bf2.field_data IS NULL OR bf2.field_data = '' THEN NULL
                                WHEN CAST(LEFT(bf2.field_data, 4) AS UNSIGNED) = 0 THEN NULL
                                ELSE CAST(LEFT(bf2.field_data, 4) AS UNSIGNED)
                            END
                        FROM biblio_field bf2
                        WHERE bf2.bibid = b.bibid
                          AND bf2.tag IN (260, 264)
                          AND bf2.subfield_cd = 'c'
                        ORDER BY bf2.tag, bf2.fieldid
                        LIMIT 1
                    ) AS pub_year_num,
                    (
                        SELECT bf.field_data
                        FROM biblio_field bf
                        WHERE bf.bibid = b.bibid
                          AND bf.tag = 520
                          AND bf.subfield_cd = 'a'
                        ORDER BY bf.fieldid
                        LIMIT 1
                    ) AS abstract_520,
                    (
                        SELECT bf.field_data
                        FROM biblio_field bf
                        WHERE bf.bibid = b.bibid
                          AND bf.tag = 20
                          AND bf.subfield_cd = 'a'
                        ORDER BY bf.fieldid
                        LIMIT 1
                    ) AS isbn_020
                $fromSql
                $whereSql
                $orderBySql
                LIMIT ? OFFSET ?
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($params, [(int) $perPage, (int) $offset]));
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (\PDOException $e) {
        $errors[] = 'Errore nella ricerca: ' . $e->getMessage();
    }
}

// Helper URL paginazione: mantiene tutti i parametri GET correnti
function staff_search_page_url(int $p): string
{
    $base = array_filter([
        'page'          => 'staff_search',
        'q'             => $_GET['q']             ?? '',
        'call_nmbr'     => $_GET['call_nmbr']     ?? '',
        'collection_cd' => $_GET['collection_cd'] ?? '',
        'sort'          => $_GET['sort']           ?? '',
    ], fn($v) => $v !== '');
    $base['p'] = $p;
    return 'index.php?' . http_build_query($base);
}

?>
<section class="page-section page-staff page-staff-search">

    <header class="staff-search-header">
        <div class="staff-search-header-top">
            <div>
                <h1>Ricerca catalogo</h1>
                <p class="staff-search-subtitle">
                    Ricerca avanzata riservata allo staff — inclusi filtri per collocazione e collezione.
                </p>
            </div>
            <a class="staff-back-link" href="<?= h($baseUrl) ?>/index.php?page=staff">← Area staff</a>
        </div>
    </header>

    <!-- ------------------------------------------------------------------ -->
    <!-- Form di ricerca                                                      -->
    <!-- ------------------------------------------------------------------ -->
    <form class="staff-search-form" method="get" action="<?= h($baseUrl) ?>/index.php">
        <input type="hidden" name="page" value="staff_search">

        <div class="staff-search-grid">

            <!-- Campo testo principale — uguale a search.php -->
            <div class="staff-search-field staff-search-field--wide">
                <label class="staff-search-label" for="ss-q">
                    Titolo, autore, soggetto, parole chiave
                </label>
                <input
                    class="staff-search-input"
                    type="text"
                    id="ss-q"
                    name="q"
                    value="<?= h($q) ?>"
                    placeholder="Es. Resistenza italiana, Pavone…"
                    autocomplete="off"
                >
            </div>

            <!-- Collocazione — solo staff -->
            <div class="staff-search-field staff-search-field--highlight">
                <label class="staff-search-label" for="ss-call">
                    Collocazione
                    <span class="staff-only-badge">solo staff</span>
                </label>
                <input
                    class="staff-search-input"
                    type="text"
                    id="ss-call"
                    name="call_nmbr"
                    value="<?= h($qCallNmbr) ?>"
                    placeholder="Es. A3, scaffale 2, emeroteca"
                    autocomplete="off"
                >
            </div>

            <!-- Collezione — solo staff -->
            <div class="staff-search-field staff-search-field--highlight">
                <label class="staff-search-label" for="ss-collection">
                    Collezione
                    <span class="staff-only-badge">solo staff</span>
                </label>
                <select class="staff-search-input staff-search-select" id="ss-collection" name="collection_cd">
                    <option value="">— tutte —</option>
                    <?php foreach ($collections as $col): ?>
                        <option
                            value="<?= h((string) $col['code']) ?>"
                            <?= $qCollection === (int) $col['code'] ? 'selected' : '' ?>
                        >
                            <?= h($col['description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

        </div>

        <div class="staff-search-actions">
            <button class="staff-search-btn" type="submit">Cerca</button>
            <?php if ($hasSearch): ?>
                <a class="staff-search-reset" href="<?= h($baseUrl) ?>/index.php?page=staff_search">
                    Cancella filtri
                </a>
            <?php endif; ?>
        </div>

    </form>

    <!-- ------------------------------------------------------------------ -->
    <!-- Errori                                                               -->
    <!-- ------------------------------------------------------------------ -->
    <?php if ($errors !== []): ?>
        <div class="generic-box">
            <?php foreach ($errors as $msg): ?>
                <p><?= h($msg) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ------------------------------------------------------------------ -->
    <!-- Risultati                                                             -->
    <!-- ------------------------------------------------------------------ -->
    <?php if ($hasSearch): ?>

        <header class="search-results-header">
            <div class="search-results-header-main">
                <h2>Risultati</h2>
                <p class="search-results-count">
                    <?php if ($total === 0): ?>
                        Nessun record trovato.
                    <?php else: ?>
                        Trovati <?= (int) $total ?> record
                        <?php if ($pages > 1): ?>
                            (pagina <?= (int) $page ?> di <?= (int) $pages ?>)
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($total > 0): ?>
                <form class="search-results-sort" method="get" action="<?= h($baseUrl) ?>/index.php">
                    <input type="hidden" name="page"          value="staff_search">
                    <input type="hidden" name="q"             value="<?= h($q) ?>">
                    <input type="hidden" name="call_nmbr"     value="<?= h($qCallNmbr) ?>">
                    <input type="hidden" name="collection_cd" value="<?= h((string) $qCollection) ?>">
                    <label for="sort-select" class="search-results-sort-label">Ordina</label>
                    <select id="sort-select" name="sort" class="search-results-sort-select" onchange="this.form.submit()">
                        <?php foreach ($sortOptions as $val => $label): ?>
                            <option value="<?= h($val) ?>"<?= $sort === $val ? ' selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
        </header>

        <?php if ($total > 0): ?>
            <ul class="result-list result-list--cards">
                <?php foreach ($results as $row): ?>
                    <?php
                    $bibid          = (int)    ($row['bibid']               ?? 0);
                    $titleVal       = trim((string) ($row['title']          ?? ''));
                    $titleRemainder = trim((string) ($row['title_remainder'] ?? ''));
                    $authorVal      = trim((string) ($row['author']         ?? ''));
                    $collDescr      = trim((string) ($row['collection_descr'] ?? ''));
                    $opacFlg        = ($row['opac_flg'] ?? 'N') === 'Y';
                    $segnatura      = staff_search_call_nmbr(
                        $row['call_nmbr1'] ?? '',
                        $row['call_nmbr2'] ?? '',
                        $row['call_nmbr3'] ?? ''
                    );

                    // Anno
                    $yearNum = (int) ($row['pub_year_num'] ?? 0);
                    $yearRaw = trim((string) ($row['pub_year_raw'] ?? ''));
                    $yearVal = '';
                    if ($yearNum > 0) {
                        $yearVal = (string) $yearNum;
                    } elseif ($yearRaw !== '' && preg_match('/\d{4}/', $yearRaw, $m)) {
                        $yearVal = $m[0];
                    }

                    // Abstract
                    $abstractVal = staff_search_trim_abstract(
                        trim((string) ($row['abstract_520'] ?? ''))
                    );

                    // ISBN
                    $isbnNorm = staff_search_normalize_isbn(
                        trim((string) ($row['isbn_020'] ?? ''))
                    );

                    // Tag topic1..5
                    $tags = [];
                    foreach (['topic1','topic2','topic3','topic4','topic5'] as $tk) {
                        $tv = trim((string) ($row[$tk] ?? ''));
                        if ($tv !== '') {
                            $tags[] = $tv;
                        }
                    }

                    // Soggetti MARC 6xx — identico a search.php
                    try {
                        $stmtSub = $pdo->prepare(
                            'SELECT tag, subfield_cd, field_data
                               FROM biblio_field
                              WHERE bibid = :bibid
                                AND tag BETWEEN 600 AND 699
                              ORDER BY tag, fieldid'
                        );
                        $stmtSub->execute([':bibid' => $bibid]);
                        $rowsSub      = $stmtSub->fetchAll(PDO::FETCH_ASSOC);
                        $currentTag   = null;
                        $currentParts = [];
                        $marcSubjects = [];

                        foreach ($rowsSub as $subRow) {
                            $tag  = (int)    ($subRow['tag']          ?? 0);
                            $code = (string) ($subRow['subfield_cd']  ?? '');
                            $data = trim((string) ($subRow['field_data'] ?? ''));

                            if (in_array($code, ['a','x','y','z'], true)) {
                                if ($currentTag !== null && $tag !== $currentTag && $currentParts !== []) {
                                    $marcSubjects[] = implode(' -- ', $currentParts);
                                    $currentParts   = [];
                                }
                                $currentTag = $tag;
                                if ($data !== '') {
                                    $currentParts[] = $data;
                                }
                                continue;
                            }
                            if ($currentParts !== []) {
                                $marcSubjects[] = implode(' -- ', $currentParts);
                                $currentParts   = [];
                                $currentTag     = null;
                            }
                        }
                        if ($currentParts !== []) {
                            $marcSubjects[] = implode(' -- ', $currentParts);
                        }
                        foreach ($marcSubjects as $subj) {
                            $subj = trim($subj);
                            if ($subj !== '' && !in_array($subj, $tags, true)) {
                                $tags[] = $subj;
                            }
                        }
                    } catch (\PDOException $e) {
                        // ignora silenziosamente
                    }

                    $detailHref     = $baseUrl . '/index.php?page=item&bibid=' . $bibid;
                    $editHref       = $baseUrl . '/index.php?page=staff_catalog_edit&bibid=' . $bibid;
                    $altTitle       = $titleVal !== '' ? $titleVal : '[Senza titolo]';
                    $placeholderSrc = $titleVal !== ''
                        ? 'cover_placeholder.php?title=' . rawurlencode($titleVal)
                        : 'assets/placeholder_nocover.png';
                    ?>
                    <li class="result-item result-card">

                        <div class="result-card-cover">
                            <a href="<?= h($detailHref) ?>">
                                <img
                                    src="<?= h($placeholderSrc) ?>"
                                    alt="Copertina di <?= h($altTitle) ?>"
                                    <?php if ($isbnNorm !== ''): ?>
                                        data-isbn="<?= h($isbnNorm) ?>"
                                    <?php endif; ?>
                                >
                            </a>
                        </div>

                        <div class="result-card-body">
                            <h3 class="result-card-title">
                                <a href="<?= h($detailHref) ?>">
                                    <?= h($titleVal !== '' ? $titleVal : '[Senza titolo]') ?>
                                </a>
                            </h3>

                            <?php if ($titleRemainder !== ''): ?>
                                <div class="result-card-subtitle"><?= h($titleRemainder) ?></div>
                            <?php endif; ?>

                            <div class="result-card-meta">
                                <?php if ($authorVal !== ''): ?>
                                    <span class="result-card-author"><?= h($authorVal) ?></span>
                                <?php endif; ?>
                                <?php if ($yearVal !== ''): ?>
                                    <span class="result-card-year">
                                        <?= $authorVal !== '' ? ' · ' : '' ?><?= h($yearVal) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!$opacFlg): ?>
                                    <span class="staff-status-badge staff-status-badge--off" style="margin-left:.55rem;">
                                        nascosto OPAC
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Metadati aggiuntivi — solo staff -->
                            <div class="staff-result-meta">
                                <?php if ($collDescr !== ''): ?>
                                    <span class="staff-result-meta-item">
                                        <span class="staff-result-meta-label">Collezione</span>
                                        <?= h($collDescr) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($segnatura !== ''): ?>
                                    <span class="staff-result-meta-item">
                                        <span class="staff-result-meta-label">Collocazione</span>
                                        <code><?= h($segnatura) ?></code>
                                    </span>
                                <?php endif; ?>
                                <span class="staff-result-meta-item">
                                    <span class="staff-result-meta-label">BIBID</span>
                                    <?= $bibid ?>
                                </span>
                            </div>

                            <?php if ($abstractVal !== ''): ?>
                                <p class="result-card-abstract"><?= h($abstractVal) ?></p>
                            <?php endif; ?>

                            <?php if ($tags !== []): ?>
                                <div class="result-card-tags">
                                    <?php foreach ($tags as $tag): ?>
                                        <span class="result-tag tag"><?= h($tag) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="result-card-actions">
                            <a href="<?= h($detailHref) ?>" class="btn-secondary result-card-btn">Scheda</a>
                            <a href="<?= h($editHref) ?>"   class="btn-primary   result-card-btn">Modifica</a>
                        </div>

                    </li>
                <?php endforeach; ?>
            </ul>

            <!-- Paginazione identica a search.php: finestra ±2 con Prima/Ultima -->
            <?php if ($pages > 1):
                $window = 2;
                $pStart = max(1, $page - $window);
                $pEnd   = min($pages, $page + $window);
            ?>
                <nav class="pagination search-pagination" aria-label="Paginazione risultati">

                    <?php if ($page > 1): ?>
                        <a class="page-link page-link--control" href="<?= h(staff_search_page_url(1)) ?>">&laquo; Prima</a>
                        <a class="page-link page-link--control" href="<?= h(staff_search_page_url($page - 1)) ?>">‹ Precedente</a>
                    <?php endif; ?>

                    <?php if ($pStart > 1): ?>
                        <span class="page-ellipsis">…</span>
                    <?php endif; ?>

                    <?php for ($pg = $pStart; $pg <= $pEnd; $pg++): ?>
                        <a
                            class="page-link<?= $pg === $page ? ' is-current' : '' ?>"
                            href="<?= h(staff_search_page_url($pg)) ?>"
                        ><?= $pg ?></a>
                    <?php endfor; ?>

                    <?php if ($pEnd < $pages): ?>
                        <span class="page-ellipsis">…</span>
                    <?php endif; ?>

                    <?php if ($page < $pages): ?>
                        <a class="page-link page-link--control" href="<?= h(staff_search_page_url($page + 1)) ?>">Successiva ›</a>
                        <a class="page-link page-link--control" href="<?= h(staff_search_page_url($pages)) ?>">Ultima &raquo;</a>
                    <?php endif; ?>

                </nav>
            <?php endif; ?>

        <?php endif; ?>
    <?php endif; ?>

</section>

<script>
(function () {
    const imgs = document.querySelectorAll('.result-card-cover img[data-isbn]');
    if (!imgs.length) return;

    imgs.forEach((img) => {
        const isbn = img.getAttribute('data-isbn');
        if (!isbn) return;

        const url =
            'https://www.googleapis.com/books/v1/volumes?q=isbn:' +
            encodeURIComponent(isbn) +
            '&maxResults=1&fields=items(volumeInfo/imageLinks/smallThumbnail,volumeInfo/imageLinks/thumbnail)';

        fetch(url)
            .then((r) => r.ok ? r.json() : null)
            .then((data) => {
                if (!data?.items?.length) return;
                const links = data.items[0].volumeInfo?.imageLinks || {};
                const src   = links.thumbnail || links.smallThumbnail;
                if (src) img.src = src;
            })
            .catch(() => {});
    });
})();
</script>
