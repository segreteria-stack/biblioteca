<?php
/**
 * Pagina di ricerca semplice dell'OPAC.
 *
 * PHP version 8.3
 */

declare(strict_types=1);

/** @var \PDO $pdo */
$pdo = DB::conn();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../lib/PatronAuth.php';

$patron  = PatronAuth::user();
$hasCsrf = function_exists('csrf_check') && function_exists('csrf_token');

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function search_trim_abstract(string $text, int $maxChars = 350): string
{
    $text = trim($text);
    if ($text === '' || strlen($text) <= $maxChars) return $text;
    $snippet = substr($text, 0, $maxChars);
    $lastDot = strrpos($snippet, '.');
    if ($lastDot !== false && $lastDot > (int)floor($maxChars * 0.4)) {
        $snippet = substr($snippet, 0, $lastDot + 1);
    }
    return rtrim($snippet) . '…';
}

function search_fetch_availability_map(PDO $pdo, array $bibids): array
{
    $out    = [];
    $bibids = array_values(array_unique(array_filter(array_map('intval', $bibids), static fn($v) => $v > 0)));
    if ($bibids === []) return $out;
    foreach ($bibids as $id) $out[$id] = ['state' => 'unknown', 'label' => ''];
    $ph = implode(',', array_fill(0, count($bibids), '?'));
    try {
        $stmt = $pdo->prepare("SELECT bibid, status_cd FROM biblio_copy WHERE bibid IN ($ph)");
        $stmt->execute($bibids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) { return $out; }
    $byBib = [];
    foreach ($rows as $r) {
        $id = (int)($r['bibid'] ?? 0);
        if ($id > 0) $byBib[$id][] = (string)($r['status_cd'] ?? '');
    }
    foreach ($bibids as $id) {
        if (!isset($byBib[$id]) || $byBib[$id] === []) continue;
        foreach ($byBib[$id] as $code) {
            $code = strtolower(trim((string)$code));
            if ($code === '' || $code === 'in') {
                $out[$id] = ['state' => 'available', 'label' => '🟢 Disponibile'];
                continue 2;
            }
        }
        $out[$id] = ['state' => 'unavailable', 'label' => '🔴 In prestito'];
    }
    return $out;
}

function search_fetch_already_held_map(PDO $pdo, int $mbrid, array $bibids): array
{
    $map    = [];
    $bibids = array_values(array_unique(array_filter(array_map('intval', $bibids), static fn($v) => $v > 0)));
    if ($mbrid <= 0 || $bibids === []) return $map;
    $ph = implode(',', array_fill(0, count($bibids), '?'));
    try {
        $stmt = $pdo->prepare("SELECT bibid FROM biblio_hold WHERE mbrid = ? AND bibid IN ($ph)");
        $stmt->execute(array_merge([$mbrid], $bibids));
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) { return $map; }
    foreach ((array)$rows as $b) {
        $id = (int)$b;
        if ($id > 0) $map[$id] = true;
    }
    return $map;
}

// -----------------------------------------------------------------------------
// Lettura parametri GET
// -----------------------------------------------------------------------------

$qRaw       = trim((string)($_GET['q'] ?? ''));
$subjectRaw = trim((string)($_GET['subject'] ?? ''));
$q          = $qRaw;
$subject    = $subjectRaw;

$sortRaw     = trim((string)($_GET['sort'] ?? 'title_asc'));
$sortOptions = [
    'title_asc'   => 'Titolo (A → Z)',
    'title_desc'  => 'Titolo (Z → A)',
    'author_asc'  => 'Autore (A → Z)',
    'author_desc' => 'Autore (Z → A)',
    'year_desc'   => 'Anno (dal più recente)',
    'year_asc'    => 'Anno (dal meno recente)',
];
$sort = array_key_exists($sortRaw, $sortOptions) ? $sortRaw : 'title_asc';

$perPageRaw = (int)($_GET['per_page'] ?? 0);
$perPage    = in_array($perPageRaw, [10, 20, 50], true) ? $perPageRaw
            : (defined('PAGE_SIZE') ? max(1, (int)PAGE_SIZE) : 20);

$page      = max(1, (int)($_GET['p'] ?? 1));
$hasSearch = ($q !== '' || $subject !== '');

// -----------------------------------------------------------------------------
// Costruzione WHERE
// -----------------------------------------------------------------------------

$whereParts = [];
$params     = [];

if ($q !== '') {
    $tokens = search_tokenize($q);
    foreach ($tokens as $tok) {
        $pattern  = '%' . $tok['value'] . '%';
        $subParts = [];
        foreach (['b.title', 'b.title_remainder', 'b.author', 'b.topic1', 'b.topic2', 'b.topic3', 'b.topic4', 'b.topic5'] as $col) {
            $subParts[] = "$col LIKE ?";
            $params[]   = $pattern;
        }
        $subParts[]   = "EXISTS (SELECT 1 FROM biblio_field bfq WHERE bfq.bibid = b.bibid AND bfq.tag BETWEEN 600 AND 699 AND bfq.subfield_cd IN ('a','x','y','z') AND bfq.field_data LIKE ?)";
        $params[]     = $pattern;
        $whereParts[] = '(' . implode(' OR ', $subParts) . ')';
    }
}

if ($subject !== '') {
    $segments = array_values(array_filter(preg_split('/\s+--\s+/', $subject) ?: [], fn($s) => trim($s) !== ''));
    foreach ($segments as $seg) {
        $seg      = trim($seg);
        $pattern  = '%' . $seg . '%';
        $subParts = [];
        foreach (['b.topic1', 'b.topic2', 'b.topic3', 'b.topic4', 'b.topic5'] as $col) {
            $subParts[] = "$col LIKE ?";
            $params[]   = $pattern;
        }
        $subParts[]   = "EXISTS (SELECT 1 FROM biblio_field bfs WHERE bfs.bibid = b.bibid AND bfs.tag BETWEEN 600 AND 699 AND bfs.subfield_cd IN ('a','x','y','z') AND bfs.field_data LIKE ?)";
        $params[]     = $pattern;
        $whereParts[] = '(' . implode(' OR ', $subParts) . ')';
    }
}

$whereSql = 'WHERE b.opac_flg = \'Y\'' . ($whereParts !== [] ? ' AND ' . implode(' AND ', $whereParts) : '');

$orderBySql = match($sort) {
    'title_desc'  => 'ORDER BY b.title DESC',
    'author_asc'  => 'ORDER BY b.author ASC, b.title ASC',
    'author_desc' => 'ORDER BY b.author DESC, b.title ASC',
    'year_desc'   => 'ORDER BY (pub_year_num IS NULL) ASC, pub_year_num DESC, b.title ASC',
    'year_asc'    => 'ORDER BY (pub_year_num IS NULL) ASC, pub_year_num ASC, b.title ASC',
    default       => 'ORDER BY b.title ASC',
};

// -----------------------------------------------------------------------------
// Esecuzione query
// -----------------------------------------------------------------------------

$results         = [];
$total           = 0;
$pages           = 1;
$errors          = [];
$shelf           = [];
$availabilityMap = [];
$alreadyHeldMap  = [];
$gbApiKey        = $GLOBALS['cfg']['google_books']['api_key'] ?? '';

if ($hasSearch) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM biblio b $whereSql");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        if ($total > 0) {
            $pages  = (int)ceil($total / $perPage);
            $page   = min($page, $pages);
            $offset = ($page - 1) * $perPage;

            $sql = "
                SELECT
                    b.bibid, b.title, b.title_remainder, b.author,
                    b.topic1, b.topic2, b.topic3, b.topic4, b.topic5,
                    (SELECT bf.field_data FROM biblio_field bf
                     WHERE bf.bibid = b.bibid AND bf.tag IN (260,264) AND bf.subfield_cd = 'c'
                     ORDER BY bf.tag, bf.fieldid LIMIT 1) AS pub_year_raw,
                    (SELECT CASE
                        WHEN bf2.field_data IS NULL OR bf2.field_data = '' THEN NULL
                        WHEN CAST(LEFT(bf2.field_data,4) AS UNSIGNED) = 0 THEN NULL
                        ELSE CAST(LEFT(bf2.field_data,4) AS UNSIGNED) END
                     FROM biblio_field bf2
                     WHERE bf2.bibid = b.bibid AND bf2.tag IN (260,264) AND bf2.subfield_cd = 'c'
                     ORDER BY bf2.tag, bf2.fieldid LIMIT 1) AS pub_year_num,
                    (SELECT bf.field_data FROM biblio_field bf
                     WHERE bf.bibid = b.bibid AND bf.tag = 520 AND bf.subfield_cd = 'a'
                     ORDER BY bf.fieldid LIMIT 1) AS abstract_520,
                    (SELECT bf.field_data FROM biblio_field bf
                     WHERE bf.bibid = b.bibid AND bf.tag = 20 AND bf.subfield_cd = 'a'
                     ORDER BY bf.fieldid LIMIT 1) AS isbn_020
                FROM biblio b
                $whereSql
                $orderBySql
                LIMIT ? OFFSET ?
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($params, [(int)$perPage, (int)$offset]));
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($results !== []) {
                $bibids          = array_map(fn($r) => (int)($r['bibid'] ?? 0), $results);
                $availabilityMap = search_fetch_availability_map($pdo, $bibids);
                if ($patron && isset($patron['mbrid'])) {
                    $alreadyHeldMap = search_fetch_already_held_map($pdo, (int)$patron['mbrid'], $bibids);
                }
            }
        }
    } catch (\PDOException $e) {
        $errors[] = 'Errore nella ricerca: ' . $e->getMessage();
    }
} elseif (!$hasSearch) {
    try {
        $shelf = $pdo->query('SELECT bibid, title, title_remainder, author FROM biblio ORDER BY RAND() LIMIT 6')->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {}
}

$queryBase = [
    'page'     => 'search',
    'q'        => $q,
    'subject'  => $subject,
    'sort'     => $sort,
    'per_page' => $perPage,
];

?>
<section class="page-section page-section--results">
    <h1>Ricerca semplice</h1>

    <form method="get" action="index.php" class="search-form-simple">
        <input type="hidden" name="page" value="search">

        <?php if ($subject !== ''): ?>
            <input type="hidden" name="subject" value="<?= h($subject) ?>">
        <?php endif; ?>

        <div class="search-form-simple-main">
            <label for="q">Cerca nel catalogo</label>
            <input
                type="text"
                id="q"
                name="q"
                value="<?= h($q !== '' ? $q : $subject) ?>"
                placeholder="Titolo, autore, soggetto, parole chiave…"
                autocomplete="off"
                data-autocomplete="1"
            >
            <button type="submit" class="btn-primary">Cerca</button>
        </div>

        <div class="search-form-simple-links">
            <a href="index.php?page=search_advanced">Ricerca avanzata →</a>
            <span class="search-tip">Virgolette per frase esatta: <code>"guerra partigiana"</code></span>
        </div>
    </form>

    <?php if ($subject !== '' && $q === ''): ?>
        <p class="search-help">
            Stai cercando per argomento: <strong><?= h($subject) ?></strong>.
            Puoi aggiungere altri termini nel campo sopra.
        </p>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="generic-box">
            <?php foreach ($errors as $msg): ?><p><?= h($msg) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$hasSearch): ?>
        <p>
            Inserisci uno o più termini e premi <strong>Cerca</strong>.
            Per filtri più specifici usa la
            <a href="index.php?page=search_advanced">ricerca avanzata</a>.
        </p>

        <?php if ($shelf !== []): ?>
            <h2>Ti potrebbe interessare leggere…</h2>
            <ul class="result-list">
                <?php foreach ($shelf as $row): ?>
                    <?php
                    $bibid     = (int)($row['bibid'] ?? 0);
                    $titleVal  = trim((string)($row['title'] ?? ''));
                    $rem       = trim((string)($row['title_remainder'] ?? ''));
                    $fullTitle = trim($titleVal . ' ' . $rem) ?: '[Senza titolo]';
                    $authorVal = trim((string)($row['author'] ?? ''));
                    ?>
                    <li class="result-item">
                        <h3 class="result-title">
                            <a href="index.php?page=item&amp;bibid=<?= $bibid ?>"><?= h($fullTitle) ?></a>
                        </h3>
                        <?php if ($authorVal !== ''): ?>
                            <div class="result-author">Autore: <?= h($authorVal) ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

    <?php else: ?>

        <header class="search-results-header">
            <div class="search-results-header-main">
                <h2>Risultati</h2>
                <?php if ($total === 0): ?>
                    <p class="search-results-count">Nessun record trovato.</p>
                <?php else: ?>
                    <p class="search-results-count">
                        Trovati <?= (int)$total ?> record
                        <?php if ($pages > 1): ?>
                            (pagina <?= (int)$page ?> di <?= (int)$pages ?>)
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php if ($total > 0): ?>
                <form class="search-results-controls" method="get" action="index.php">
                    <input type="hidden" name="page" value="search">
                    <input type="hidden" name="q" value="<?= h($q) ?>">
                    <input type="hidden" name="subject" value="<?= h($subject) ?>">

                    <label for="sort" class="search-results-sort-label">Ordina</label>
                    <select id="sort" name="sort" class="search-results-sort-select" onchange="this.form.submit()">
                        <?php foreach ($sortOptions as $val => $label): ?>
                            <option value="<?= h($val) ?>"<?= $sort === $val ? ' selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="per_page" class="search-results-sort-label" style="margin-left:1rem">Per pagina</label>
                    <select id="per_page" name="per_page" class="search-results-sort-select" onchange="this.form.submit()">
                        <option value="10"<?= $perPage === 10 ? ' selected' : '' ?>>10</option>
                        <option value="20"<?= $perPage === 20 ? ' selected' : '' ?>>20</option>
                        <option value="50"<?= $perPage === 50 ? ' selected' : '' ?>>50</option>
                    </select>
                </form>
            <?php endif; ?>

            <a href="index.php?page=search" class="btn-secondary search-results-new-search">Nuova ricerca</a>
        </header>

        <?php if ($total === 0): ?>
            <p>Nessun record corrisponde ai criteri inseriti. Prova con termini più generici
               o usa la <a href="index.php?page=search_advanced">ricerca avanzata</a>.</p>
        <?php else: ?>
            <ul class="result-list result-list--cards">
                <?php foreach ($results as $row): ?>
                    <?php
                    $bibid          = (int)($row['bibid'] ?? 0);
                    $titleVal       = trim((string)($row['title'] ?? ''));
                    $titleRemainder = trim((string)($row['title_remainder'] ?? ''));
                    $authorVal      = trim((string)($row['author'] ?? ''));

                    $availability      = $availabilityMap[$bibid] ?? ['state' => 'unknown', 'label' => ''];
                    $availabilityState = (string)($availability['state'] ?? 'unknown');
                    $availabilityLabel = (string)($availability['label'] ?? '');
                    $alreadyHeld       = ($patron && isset($alreadyHeldMap[$bibid]));

                    $yearNum = (int)($row['pub_year_num'] ?? 0);
                    $yearRaw = trim((string)($row['pub_year_raw'] ?? ''));
                    $yearVal = $yearNum > 0 ? (string)$yearNum : (preg_match('/\d{4}/', $yearRaw, $m) ? $m[0] : '');

                    $abstractVal = search_trim_abstract(trim((string)($row['abstract_520'] ?? '')));

                    $isbnRaw  = trim((string)($row['isbn_020'] ?? ''));
                    $isbnOrig = CoverService::getIsbnForJs($isbnRaw);
                    $isbn13   = CoverService::toIsbn13($isbnRaw);
                    $hasLocal = ($isbn13 !== '' && CoverService::hasLocalCover($isbn13));
                    $coverUrl    = CoverService::getCoverUrl($isbnRaw, $titleVal, $authorVal);
                    $placeholder = CoverService::placeholderUrl($titleVal);

                    $tags = [];
                    foreach (['topic1','topic2','topic3','topic4','topic5'] as $tk) {
                        $tv = trim((string)($row[$tk] ?? ''));
                        if ($tv !== '') $tags[] = $tv;
                    }
                    try {
                        $stmtSub = $pdo->prepare('SELECT fieldid, tag, subfield_cd, field_data FROM biblio_field WHERE bibid = :bibid AND tag BETWEEN 600 AND 699 ORDER BY tag, fieldid');
                        $stmtSub->execute([':bibid' => $bibid]);
                        $rowsSub         = $stmtSub->fetchAll(PDO::FETCH_ASSOC);
                        $currentFieldid  = null;
                        $currentParts    = [];
                        $marcSubjects    = [];
                        foreach ($rowsSub as $subRow) {
                            $fieldid = (int)($subRow['fieldid'] ?? 0);
                            $code    = (string)($subRow['subfield_cd'] ?? '');
                            $data    = trim((string)($subRow['field_data'] ?? ''));
                            if (in_array($code, ['a','x','y','z'], true)) {
                                if ($currentFieldid !== null && $fieldid !== $currentFieldid && $currentParts !== []) {
                                    $marcSubjects[] = implode(' -- ', $currentParts);
                                    $currentParts   = [];
                                }
                                $currentFieldid = $fieldid;
                                if ($data !== '') $currentParts[] = $data;
                                continue;
                            }
                            if ($currentParts !== []) {
                                $marcSubjects[] = implode(' -- ', $currentParts);
                                $currentParts   = [];
                                $currentFieldid = null;
                            }
                        }
                        if ($currentParts !== []) $marcSubjects[] = implode(' -- ', $currentParts);
                        foreach ($marcSubjects as $subj) {
                            $subj = trim($subj);
                            if ($subj !== '' && !in_array($subj, $tags, true)) $tags[] = $subj;
                        }
                    } catch (\PDOException $e) {}

                    $detailHref     = 'index.php?page=item&bibid=' . $bibid;
                    $holdPostAction = 'index.php?page=item&bibid=' . $bibid;
                    $holdsUrl       = 'index.php?page=patron_area&tab=holds';
                    ?>
                    <li class="result-item result-card">
                        <div class="result-card-cover">
                            <a href="<?= h($detailHref) ?>">
                                <img
                                    src="<?= h($coverUrl) ?>"
                                    alt="Copertina di <?= h($titleVal ?: '[Senza titolo]') ?>"
                                    onerror="this.onerror=null;this.src='<?= h($placeholder) ?>';"
                                    <?php if (!$hasLocal && $isbnOrig !== ''): ?>
                                        data-isbn="<?= h($isbnOrig) ?>"
                                        data-isbn13="<?= h($isbn13) ?>"
                                        data-title="<?= h($titleVal) ?>"
                                        data-author="<?= h($authorVal) ?>"
                                        data-placeholder="<?= h($placeholder) ?>"
                                    <?php endif; ?>
                                >
                            </a>
                        </div>

                        <div class="result-card-body">
                            <h3 class="result-card-title">
                                <a href="<?= h($detailHref) ?>"><?= h($titleVal ?: '[Senza titolo]') ?></a>
                            </h3>
                            <?php if ($titleRemainder !== ''): ?>
                                <div class="result-card-subtitle"><?= h($titleRemainder) ?></div>
                            <?php endif; ?>
                            <div class="result-card-meta">
                                <?php if ($authorVal !== ''): ?>
                                    <span class="result-card-author"><?= h($authorVal) ?></span>
                                <?php endif; ?>
                                <?php if ($yearVal !== ''): ?>
                                    <span class="result-card-year"><?= $authorVal !== '' ? ' · ' : '' ?><?= h($yearVal) ?></span>
                                <?php endif; ?>
                                <?php if ($availabilityLabel !== ''): ?>
                                    <span class="availability-badge availability-<?= h($availabilityState) ?>" style="margin-left:0.55rem;"><?= h($availabilityLabel) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($abstractVal !== ''): ?>
                                <p class="result-card-abstract"><?= h($abstractVal) ?></p>
                            <?php endif; ?>
                            <?php if ($tags !== []): ?>
                                <div class="result-card-tags">
                                    <?php foreach ($tags as $tag): ?>
                                        <a class="result-tag tag" href="index.php?page=search&amp;subject=<?= urlencode($tag) ?>"><?= h($tag) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="result-card-actions">
                            <a href="<?= h($detailHref) ?>" class="btn-primary result-card-btn">Dettagli</a>
                            <?php if ($patron): ?>
                                <?php if ($alreadyHeld): ?>
                                    <a href="<?= h($holdsUrl) ?>" class="btn-secondary" style="margin-left:0.5rem;">Già prenotato</a>
                                <?php else: ?>
                                    <form method="post" action="<?= h($holdPostAction) ?>" style="display:inline;margin-left:0.5rem;">
                                        <?php if ($hasCsrf): ?>
                                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                        <?php endif; ?>
                                        <input type="hidden" name="action" value="hold_request">
                                        <input type="hidden" name="bibid" value="<?= (int)$bibid ?>">
                                        <button type="submit" class="btn-secondary">Prenota</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($pages > 1): ?>
                <?php
                $window = 2;
                $start  = max(1, $page - $window);
                $end    = min($pages, $page + $window);
                ?>
                <nav class="pagination search-pagination" aria-label="Paginazione risultati">
                    <?php if ($page > 1): ?>
                        <a class="page-link page-link--control" href="index.php?<?= http_build_query($queryBase + ['p' => 1]) ?>">&laquo; Prima</a>
                        <a class="page-link page-link--control" href="index.php?<?= http_build_query($queryBase + ['p' => $page - 1]) ?>">‹ Precedente</a>
                    <?php endif; ?>
                    <?php if ($start > 1): ?><span class="page-ellipsis">…</span><?php endif; ?>
                    <?php for ($p = $start; $p <= $end; $p++): ?>
                        <a class="page-link<?= $p === $page ? ' is-current' : '' ?>" href="index.php?<?= http_build_query($queryBase + ['p' => $p]) ?>"><?= $p ?></a>
                    <?php endfor; ?>
                    <?php if ($end < $pages): ?><span class="page-ellipsis">…</span><?php endif; ?>
                    <?php if ($page < $pages): ?>
                        <a class="page-link page-link--control" href="index.php?<?= http_build_query($queryBase + ['p' => $page + 1]) ?>">Successiva ›</a>
                        <a class="page-link page-link--control" href="index.php?<?= http_build_query($queryBase + ['p' => $pages]) ?>">Ultima &raquo;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if (!empty($gbApiKey)): ?>
<script>
(function () {
    const apiKey = <?= json_encode($gbApiKey) ?>;
    const imgs   = document.querySelectorAll('.result-card-cover img[data-isbn]');
    if (!imgs.length) return;

    function saveCoverOnServer(isbn13, url) {
        if (!isbn13) return;
        fetch('cover_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ isbn: isbn13, url })
        }).catch(() => {});
    }

    function fetchGoogleBooks(q) {
        const url = 'https://www.googleapis.com/books/v1/volumes'
            + '?q=' + encodeURIComponent(q)
            + '&maxResults=1&fields=items(volumeInfo/imageLinks)'
            + '&key=' + encodeURIComponent(apiKey);
        return fetch(url)
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                const links = data?.items?.[0]?.volumeInfo?.imageLinks;
                if (!links) return null;
                const src = links.thumbnail || links.smallThumbnail || links.medium || links.large || links.extraLarge;
                return src ? src.replace(/^http:\/\//, 'https://') : null;
            })
            .catch(() => null);
    }

    function tryOpenLibrary(isbn13) {
        if (!isbn13) return Promise.resolve(null);
        return new Promise(resolve => {
            const t = new Image();
            const u = 'https://covers.openlibrary.org/b/isbn/' + encodeURIComponent(isbn13) + '-M.jpg?default=false';
            t.onload  = () => resolve(u);
            t.onerror = () => resolve(null);
            t.src = u;
        });
    }

    async function loadCover(img) {
        const isbn        = img.getAttribute('data-isbn');
        const isbn13      = img.getAttribute('data-isbn13');
        const title       = img.getAttribute('data-title');
        const author      = img.getAttribute('data-author');

        let src = null;
        if (isbn)                              src = await fetchGoogleBooks('isbn:' + isbn);
        if (!src && isbn13 && isbn13 !== isbn) src = await fetchGoogleBooks('isbn:' + isbn13);
        if (!src && title) {
            let q = 'intitle:' + title;
            if (author) q += ' inauthor:' + author;
            src = await fetchGoogleBooks(q);
        }
        if (!src) src = await tryOpenLibrary(isbn13);

        if (src) {
            img.onerror = null;
            img.src = src;
            saveCoverOnServer(isbn13, src);
        }
    }

    imgs.forEach(img => loadCover(img));
})();
</script>
<?php endif; ?>