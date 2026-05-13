<?php
/**
 * Pagina di ricerca avanzata dell'OPAC con righe dinamiche.
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
require_once __DIR__ . '/../lib/marc_helpers.php';

$patron  = PatronAuth::user();
$hasCsrf = function_exists('csrf_check') && function_exists('csrf_token');

function search_trim_abstract(string $text, int $maxChars = 350): string
{
    $text = trim($text);
    if ($text === '') return '';
    if (strlen($text) <= $maxChars) return $text;
    $snippet = substr($text, 0, $maxChars);
    $lastDot = strrpos($snippet, '.');
    if ($lastDot !== false && $lastDot > (int)floor($maxChars * 0.4)) {
        $snippet = substr($snippet, 0, $lastDot + 1);
    }
    return rtrim($snippet) . '…';
}

function search_fetch_availability_map(PDO $pdo, array $bibids): array
{
    static $defaultLabels = [
        'in'  => 'Disponibile',
        'ln'  => 'In prestito',
        'out' => 'Non disponibile',
        'hld' => 'In attesa',
        'mnd' => 'In fase di restauro',
        'ord' => 'Riservato',
        'crt' => 'Da reintegrare',
        'lst' => 'Perso',
        '8'   => 'Escluso dal prestito',
    ];

    $out    = [];
    $bibids = array_values(array_unique(array_filter(array_map('intval', $bibids), static fn($v) => $v > 0)));
    if ($bibids === []) return $out;
    foreach ($bibids as $id) $out[$id] = ['state' => 'unknown', 'label' => ''];
    $ph = implode(',', array_fill(0, count($bibids), '?'));

    $dbLabels = [];
    try {
        $s = $pdo->prepare("SELECT code, description FROM biblio_status_dm");
        $s->execute();
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dbLabels[strtolower(trim((string)$row['code']))] = (string)$row['description'];
        }
    } catch (\PDOException $e) { /* tabella assente: useremo $defaultLabels */ }
    $labels = $dbLabels !== [] ? $dbLabels : $defaultLabels;

    try {
        $stmt = $pdo->prepare("SELECT bibid, status_cd FROM biblio_copy WHERE bibid IN ($ph)");
        $stmt->execute($bibids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) { return $out; }

    $byBib = [];
    foreach ($rows as $r) {
        $id   = (int)($r['bibid'] ?? 0);
        $code = strtolower(trim((string)($r['status_cd'] ?? '')));
        if ($id > 0) $byBib[$id][] = $code;
    }

    $inactive = ['dis', 'lst'];
    $prio = ['in' => 1, 'out' => 2, 'ln' => 2, 'hld' => 3, 'mnd' => 4, 'ord' => 5, 'crt' => 6, '8' => 4];

    foreach ($bibids as $id) {
        if (!isset($byBib[$id]) || $byBib[$id] === []) continue;
        $codes = array_unique($byBib[$id]);

        if (in_array('in', $codes, true) || in_array('', $codes, true)) {
            $out[$id] = ['state' => 'available', 'label' => 'Disponibile'];
            continue;
        }

        $active = array_values(array_filter($codes, static fn($c) => !in_array($c, $inactive, true)));
        if ($active === []) continue;

        $bestCode = $active[0];
        $bestPrio = $prio[$bestCode] ?? 99;
        foreach ($active as $code) {
            $p = $prio[$code] ?? 99;
            if ($p < $bestPrio) { $bestPrio = $p; $bestCode = $code; }
        }
        $label = $labels[$bestCode] ?? ($defaultLabels[$bestCode] ?? $bestCode);
        $state = match($bestCode) {
            'hld', 'ord'  => 'reserved',
            'mnd', 'crt', '8' => 'other',
            default       => 'unavailable',
        };
        $out[$id] = ['state' => $state, 'label' => $label];
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

function buildPattern(string $value, string $op): array
{
    switch (strtolower($op)) {
        case 'equals': return ['pattern' => $value,             'use_like' => false];
        case 'starts': return ['pattern' => $value . '%',       'use_like' => true];
        case 'ends':   return ['pattern' => '%' . $value,       'use_like' => true];
        default:       return ['pattern' => '%' . $value . '%', 'use_like' => true];
    }
}

function normalizeBool(string $bool): string
{
    $bool = strtoupper(trim($bool));
    return ($bool === 'OR' || $bool === 'NOT') ? $bool : 'AND';
}

function buildTitleCondition(string $value, string $op, array &$params): ?string
{
    $value = trim($value);
    if ($value === '') return null;
    if (strtolower($op) === 'contains') {
        $tokens = search_tokenize($value);
        if ($tokens === []) return null;
        $parts = [];
        foreach ($tokens as $tok) {
            $pattern  = '%' . $tok['value'] . '%';
            $parts[]  = '(title LIKE ? OR title_remainder LIKE ?)';
            $params[] = $pattern;
            $params[] = $pattern;
        }
        return '(' . implode(' AND ', $parts) . ')';
    }
    $info     = buildPattern($value, $op);
    $params[] = $info['pattern'];
    $params[] = $info['pattern'];
    return $info['use_like'] ? '(title LIKE ? OR title_remainder LIKE ?)' : '(title = ? OR title_remainder = ?)';
}

function buildAuthorCondition(string $value, string $op, array &$params): ?string
{
    if ($value === '') return null;
    if (strtolower($op) === 'contains') {
        $tokens = search_tokenize($value);
        if ($tokens === []) return null;
        $parts = [];
        foreach ($tokens as $tok) {
            $pattern  = '%' . $tok['value'] . '%';
            $parts[]  = 'author LIKE ?';
            $params[] = $pattern;
        }
        return '(' . implode(' AND ', $parts) . ')';
    }
    $info = buildPattern($value, $op);
    $params[] = $info['pattern'];
    return $info['use_like'] ? 'author LIKE ?' : 'author = ?';
}

function buildSubjectCondition(string $value, string $op, array &$params): ?string
{
    if ($value === '') return null;
    if (strtolower($op) === 'contains') {
        $tokens = search_tokenize($value);
        if ($tokens === []) return null;
        $parts = [];
        foreach ($tokens as $tok) {
            $pattern = '%' . $tok['value'] . '%';
            $parts[] = "(topic1 LIKE ? OR topic2 LIKE ? OR topic3 LIKE ? OR topic4 LIKE ? OR topic5 LIKE ?"
                     . " OR EXISTS (SELECT 1 FROM biblio_field bfs WHERE bfs.bibid = biblio.bibid"
                     . "  AND bfs.tag IN (650,651) AND bfs.subfield_cd = 'a' AND bfs.field_data LIKE ?))";
            for ($i = 0; $i < 5; $i++) $params[] = $pattern;
            $params[] = $pattern;
        }
        return '(' . implode(' AND ', $parts) . ')';
    }
    $info = buildPattern($value, $op);
    $op_  = $info['use_like'] ? 'LIKE' : '=';
    $sql  = "(topic1 $op_ ? OR topic2 $op_ ? OR topic3 $op_ ? OR topic4 $op_ ? OR topic5 $op_ ?"
          . " OR EXISTS (SELECT 1 FROM biblio_field bfs WHERE bfs.bibid = biblio.bibid"
          . "  AND bfs.tag IN (650,651) AND bfs.subfield_cd = 'a' AND bfs.field_data $op_ ?))";
    for ($i = 0; $i < 5; $i++) $params[] = $info['pattern'];
    $params[] = $info['pattern'];
    return $sql;
}

function buildAbstractCondition(string $value, string $op, array &$params): ?string
{
    if ($value === '') return null;
    if (strtolower($op) === 'contains') {
        $tokens = search_tokenize($value);
        if ($tokens === []) return null;
        $parts = [];
        foreach ($tokens as $tok) {
            $pattern = '%' . $tok['value'] . '%';
            $parts[] = "EXISTS (SELECT 1 FROM biblio_field bfa WHERE bfa.bibid = biblio.bibid"
                     . " AND bfa.tag IN (520,500) AND bfa.subfield_cd = 'a' AND bfa.field_data LIKE ?)";
            $params[] = $pattern;
        }
        return '(' . implode(' AND ', $parts) . ')';
    }
    $info = buildPattern($value, $op);
    $op_  = $info['use_like'] ? 'LIKE' : '=';
    $params[] = $info['pattern'];
    return "EXISTS (SELECT 1 FROM biblio_field bfa WHERE bfa.bibid = biblio.bibid"
         . " AND bfa.tag IN (520,500) AND bfa.subfield_cd = 'a' AND bfa.field_data $op_ ?)";
}

function buildSeriesCondition(string $value, string $op, array &$params): ?string
{
    if ($value === '') return null;
    if (strtolower($op) === 'contains') {
        $tokens = search_tokenize($value);
        if ($tokens === []) return null;
        $parts = [];
        foreach ($tokens as $tok) {
            $pattern = '%' . $tok['value'] . '%';
            $parts[] = "EXISTS (SELECT 1 FROM biblio_field bfr WHERE bfr.bibid = biblio.bibid"
                     . " AND bfr.tag IN (490,440,830) AND bfr.subfield_cd = 'a' AND bfr.field_data LIKE ?)";
            $params[] = $pattern;
        }
        return '(' . implode(' AND ', $parts) . ')';
    }
    $info = buildPattern($value, $op);
    $op_  = $info['use_like'] ? 'LIKE' : '=';
    $params[] = $info['pattern'];
    return "EXISTS (SELECT 1 FROM biblio_field bfr WHERE bfr.bibid = biblio.bibid"
         . " AND bfr.tag IN (490,440,830) AND bfr.subfield_cd = 'a' AND bfr.field_data $op_ ?)";
}

function buildPlaceCondition(string $value, string $op, array &$params): ?string
{
    if ($value === '') return null;
    $info = buildPattern($value, $op);
    $params[] = $info['pattern'];
    return $info['use_like'] ? 'idx.pub_place LIKE ?' : 'idx.pub_place = ?';
}

function buildRespCondition(string $value, string $op, array &$params): ?string
{
    if ($value === '') return null;
    if (strtolower($op) === 'contains') {
        $tokens = search_tokenize($value);
        if ($tokens === []) return null;
        $parts = [];
        foreach ($tokens as $tok) {
            $pattern  = '%' . $tok['value'] . '%';
            $parts[]  = 'responsibility_stmt LIKE ?';
            $params[] = $pattern;
        }
        return '(' . implode(' AND ', $parts) . ')';
    }
    $info = buildPattern($value, $op);
    $params[] = $info['pattern'];
    return $info['use_like'] ? 'responsibility_stmt LIKE ?' : 'responsibility_stmt = ?';
}

function buildPublisherCondition(string $value, string $op, array &$params): ?string
{
    if ($value === '') return null;
    $info = buildPattern($value, $op);
    $params[] = $info['pattern'];
    return $info['use_like'] ? 'idx.publisher LIKE ?' : 'idx.publisher = ?';
}

function buildIsbnCondition(string $value, string $op, array &$params): ?string
{
    if ($value === '') return null;
    $info = buildPattern($value, $op);
    $params[] = $info['pattern'];
    return $info['use_like'] ? 'idx.isbn LIKE ?' : 'idx.isbn = ?';
}

function buildFieldCondition(string $field, string $value, string $op, array &$params): ?string
{
    switch ($field) {
        case 'title':     return buildTitleCondition($value, $op, $params);
        case 'author':    return buildAuthorCondition($value, $op, $params);
        case 'subject':   return buildSubjectCondition($value, $op, $params);
        case 'publisher': return buildPublisherCondition($value, $op, $params);
        case 'isbn':      return buildIsbnCondition($value, $op, $params);
        case 'abstract':  return buildAbstractCondition($value, $op, $params);
        case 'series':    return buildSeriesCondition($value, $op, $params);
        case 'place':     return buildPlaceCondition($value, $op, $params);
        case 'resp':      return buildRespCondition($value, $op, $params);
        default:          return null;
    }
}

function mapSort(string $sort): string
{
    switch ($sort) {
        case 'title_desc':  return 'title DESC';
        case 'author_asc':  return 'author ASC';
        case 'author_desc': return 'author DESC';
        default:            return 'title ASC';
    }
}

// -----------------------------------------------------------------------------
// Caricamento domini
// -----------------------------------------------------------------------------

$materials   = [];
$collections = [];
try {
    $materials   = $pdo->query('SELECT code, description FROM material_type_dm ORDER BY description')->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {}
try {
    $collections = $pdo->query('SELECT code, description FROM collection_dm ORDER BY description')->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {}

// -----------------------------------------------------------------------------
// Lettura parametri
// -----------------------------------------------------------------------------

$fieldInput = (array)($_GET['field'] ?? []);
$opInput    = (array)($_GET['op'] ?? []);
$boolInput  = (array)($_GET['bool'] ?? []);
$valInput   = (array)($_GET['value'] ?? []);

$maxRows = max(count($fieldInput), count($opInput), count($boolInput), count($valInput));
$rows    = [];

for ($i = 0; $i < $maxRows; $i++) {
    $rows[] = [
        'bool'  => (string)($boolInput[$i] ?? 'AND'),
        'field' => (string)($fieldInput[$i] ?? 'title'),
        'op'    => (string)($opInput[$i] ?? 'contains'),
        'value' => trim((string)($valInput[$i] ?? '')),
    ];
}
if ($rows === []) {
    $rows[] = ['bool' => 'AND', 'field' => 'title', 'op' => 'contains', 'value' => ''];
}

$materialCd   = trim((string)($_GET['material_cd'] ?? ''));
$collectionCd = trim((string)($_GET['collection_cd'] ?? ''));
$yearFromRaw  = trim((string)($_GET['year_from'] ?? ''));
$yearToRaw    = trim((string)($_GET['year_to'] ?? ''));
$yearFrom     = ctype_digit($yearFromRaw) ? (int)$yearFromRaw : 0;
$yearTo       = ctype_digit($yearToRaw)   ? (int)$yearToRaw   : 0;

$page       = max(1, (int)($_GET['p'] ?? 1));
$perPageRaw = (int)($_GET['per_page'] ?? 10);
$perPage    = in_array($perPageRaw, [10, 20, 50], true) ? $perPageRaw : 10;
$sortRaw    = (string)($_GET['sort'] ?? 'title_asc');
$orderBy    = mapSort($sortRaw);

// -----------------------------------------------------------------------------
// Costruzione WHERE
// -----------------------------------------------------------------------------

$clauses  = [];
$params   = [];

foreach ($rows as $index => $row) {
    if ($row['value'] === '') continue;
    $cond = buildFieldCondition($row['field'], $row['value'], $row['op'], $params);
    if ($cond === null) continue;
    $bool      = $index === 0 ? null : normalizeBool($row['bool']);
    $clauses[] = ['sql' => $cond, 'bool' => $bool];
}

if ($materialCd !== '') {
    $clauses[] = ['sql' => 'material_cd = ?', 'bool' => 'AND'];
    $params[]  = (int)$materialCd;
}
if ($collectionCd !== '') {
    $clauses[] = ['sql' => 'collection_cd = ?', 'bool' => 'AND'];
    $params[]  = (int)$collectionCd;
}
if ($yearFrom > 0) {
    $clauses[] = ['sql' => 'idx.pub_year >= ?', 'bool' => 'AND'];
    $params[]  = $yearFrom;
}
if ($yearTo > 0) {
    $clauses[] = ['sql' => 'idx.pub_year <= ?', 'bool' => 'AND'];
    $params[]  = $yearTo;
}

$whereSql = '';
if ($clauses !== []) {
    $parts   = [];
    $isFirst = true;
    foreach ($clauses as $clause) {
        if ($isFirst) {
            $parts[]  = $clause['sql'];
            $isFirst  = false;
        } else {
            $bool      = $clause['bool'] ?? 'AND';
            $connector = $bool === 'NOT' ? 'AND NOT' : $bool;
            $parts[]   = $connector . ' ' . $clause['sql'];
        }
    }
    $whereSql = "WHERE biblio.opac_flg = 'Y' AND (" . implode(' ', $parts) . ')';
}

// -----------------------------------------------------------------------------
// Esecuzione query
// -----------------------------------------------------------------------------

$results         = [];
$total           = 0;
$pages           = 1;
$availabilityMap = [];
$subjectsMap     = [];
$alreadyHeldMap  = [];
$gbApiKey        = $GLOBALS['cfg']['google_books']['api_key'] ?? '';

if ($whereSql !== '') {
    $sqlCount = 'SELECT COUNT(*) FROM biblio LEFT JOIN biblio_index_ext idx ON idx.bibid = biblio.bibid ' . $whereSql;
    $stmt     = $pdo->prepare($sqlCount);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    if ($total > 0) {
        $pages  = (int)ceil($total / $perPage);
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT
                biblio.bibid, biblio.title, biblio.title_remainder, biblio.author,
                biblio.topic1, biblio.topic2, biblio.topic3, biblio.topic4, biblio.topic5,
                (SELECT bf.field_data FROM biblio_field bf
                 WHERE bf.bibid = biblio.bibid AND bf.tag = 260 AND bf.subfield_cd = 'c'
                 ORDER BY bf.fieldid LIMIT 1) AS pub_year_260,
                (SELECT bf.field_data FROM biblio_field bf
                 WHERE bf.bibid = biblio.bibid AND bf.tag = 264 AND bf.subfield_cd = 'c'
                 ORDER BY bf.fieldid LIMIT 1) AS pub_year_264,
                (SELECT bf.field_data FROM biblio_field bf
                 WHERE bf.bibid = biblio.bibid AND bf.tag = 520 AND bf.subfield_cd = 'a'
                 ORDER BY bf.fieldid LIMIT 1) AS abstract_520,
                (SELECT bf.field_data FROM biblio_field bf
                 WHERE bf.bibid = biblio.bibid AND bf.tag = 20 AND bf.subfield_cd = 'a'
                 ORDER BY bf.fieldid LIMIT 1) AS isbn_020
            FROM biblio
            LEFT JOIN biblio_index_ext idx ON idx.bibid = biblio.bibid
            $whereSql
            ORDER BY $orderBy
            LIMIT ? OFFSET ?
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, [(int)$perPage, (int)$offset]));
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($results !== []) {
            $bibids          = array_map(fn($r) => (int)($r['bibid'] ?? 0), $results);
            $availabilityMap = search_fetch_availability_map($pdo, $bibids);
            $recordsById     = array_column($results, null, 'bibid');
            $subjectsMap     = search_fetch_subjects_map($pdo, $bibids, $recordsById);
            if ($patron && isset($patron['mbrid'])) {
                $alreadyHeldMap = search_fetch_already_held_map($pdo, (int)$patron['mbrid'], $bibids);
            }
        }
    }
}

?>
<section class="page-section page-advanced-search page-section--results">
    <h1>Ricerca avanzata</h1>

    <form method="get" action="index.php" class="search-form search-form-advanced">
        <input type="hidden" name="page" value="search_advanced">

        <div class="adv-layout">
            <!-- BLOCCO PRINCIPALE: righe di ricerca -->
            <div class="adv-main">
                <div class="adv-main-box">
                    <div class="adv-rows" id="adv-rows" data-max-rows="7">
                        <?php foreach ($rows as $index => $row):
                            $rowBool  = strtoupper($row['bool'] ?? 'AND');
                            $rowField = $row['field'] ?: 'title';
                            $rowOp    = $row['op']    ?: 'contains';
                            $rowValue = $row['value'] ?? '';
                        ?>
                            <div class="adv-row">
                                <div class="adv-cell adv-cell-bool">
                                    <select name="bool[]" class="adv-bool" aria-label="Operatore logico riga <?= $index + 1 ?>">
                                        <option value="AND"<?= $rowBool === 'AND' ? ' selected' : '' ?>>AND</option>
                                        <option value="OR"<?=  $rowBool === 'OR'  ? ' selected' : '' ?>>OR</option>
                                        <option value="NOT"<?= $rowBool === 'NOT' ? ' selected' : '' ?>>NOT</option>
                                    </select>
                                </div>
                                <div class="adv-cell adv-cell-field">
                                    <select name="field[]" class="adv-field" aria-label="Campo di ricerca riga <?= $index + 1 ?>">
                                        <option value="title"<?=     $rowField === 'title'     ? ' selected' : '' ?>>Titolo</option>
                                        <option value="author"<?=    $rowField === 'author'    ? ' selected' : '' ?>>Autore</option>
                                        <option value="subject"<?=   $rowField === 'subject'   ? ' selected' : '' ?>>Soggetto</option>
                                        <option value="publisher"<?= $rowField === 'publisher' ? ' selected' : '' ?>>Editore</option>
                                        <option value="isbn"<?=      $rowField === 'isbn'      ? ' selected' : '' ?>>ISBN / Codice</option>
                                        <option value="abstract"<?=  $rowField === 'abstract'  ? ' selected' : '' ?>>Abstract / Note</option>
                                        <option value="series"<?=    $rowField === 'series'    ? ' selected' : '' ?>>Collana / Serie</option>
                                        <option value="place"<?=     $rowField === 'place'     ? ' selected' : '' ?>>Luogo di pubbl.</option>
                                        <option value="resp"<?=      $rowField === 'resp'      ? ' selected' : '' ?>>Responsabilità</option>
                                    </select>
                                </div>
                                <div class="adv-cell adv-cell-op">
                                    <select name="op[]" class="adv-op" aria-label="Operatore di confronto riga <?= $index + 1 ?>">
                                        <option value="contains"<?= $rowOp === 'contains' ? ' selected' : '' ?>>contiene</option>
                                        <option value="equals"<?=   $rowOp === 'equals'   ? ' selected' : '' ?>>è esattamente</option>
                                        <option value="starts"<?=   $rowOp === 'starts'   ? ' selected' : '' ?>>inizia con</option>
                                        <option value="ends"<?=     $rowOp === 'ends'     ? ' selected' : '' ?>>finisce con</option>
                                    </select>
                                </div>
                                <div class="adv-cell adv-cell-value">
                                    <input type="text" name="value[]" value="<?= h($rowValue) ?>" placeholder="Inserisci il termine di ricerca" aria-label="Termine di ricerca riga <?= $index + 1 ?>" data-autocomplete="1" autocomplete="off">
                                </div>
                                <div class="adv-cell adv-cell-remove">
                                    <button type="button" class="adv-remove" aria-label="Rimuovi riga">×</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="adv-add-row">
                        <button type="button" id="adv-add-row" class="btn-add-row">+ Aggiungi riga di ricerca</button>
                    </div>

                    <p class="search-tip">Virgolette per frase esatta: <code>"guerra partigiana"</code></p>

                    <div class="search-actions">
                        <button type="submit" class="btn-primary btn-primary-cta">Cerca</button>
                        <?php if ($whereSql !== ''): ?>
                            <a class="btn-secondary" href="index.php?page=search_advanced">Cancella campi</a>
                        <?php endif; ?>
                        <a class="btn-link" href="index.php?page=search">Torna alla ricerca semplice</a>
                    </div>

                    <p class="search-help">
                        Puoi aggiungere fino a 7 righe di ricerca, scegliendo campo, operatore
                        e combinazione logica (AND / OR / NOT). Puoi inoltre restringere per
                        tipo di materiale, collocazione e periodo di pubblicazione.
                    </p>
                </div>
            </div>

            <!-- BLOCCO FILTRI AGGIUNTIVI -->
            <aside class="adv-filters" aria-label="Filtri aggiuntivi">
                <h2 class="adv-filters-title">Filtri aggiuntivi</h2>

                <div class="search-row">
                    <label for="material_cd">Tipo di materiale</label>
                    <select id="material_cd" name="material_cd">
                        <option value="">Tutti</option>
                        <?php foreach ($materials as $m):
                            $code = (string)($m['code'] ?? '');
                        ?>
                            <option value="<?= h($code) ?>"<?= ($materialCd !== '' && (int)$materialCd === (int)$code) ? ' selected' : '' ?>>
                                <?= h($m['description'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="search-row">
                    <label for="collection_cd">Collocazione</label>
                    <select id="collection_cd" name="collection_cd">
                        <option value="">Tutte</option>
                        <?php foreach ($collections as $c):
                            $ccode = (string)($c['code'] ?? '');
                        ?>
                            <option value="<?= h($ccode) ?>"<?= ($collectionCd !== '' && (int)$collectionCd === (int)$ccode) ? ' selected' : '' ?>>
                                <?= h($c['description'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="search-row search-row-inline">
                    <label for="year_from">Anno di pubblicazione</label>
                    <div class="filter-year">
                        <input type="text" id="year_from" name="year_from" value="<?= h($yearFromRaw) ?>" placeholder="Da (es. 1943)" size="6">
                        <span class="between-label">—</span>
                        <input type="text" id="year_to" name="year_to" value="<?= h($yearToRaw) ?>" placeholder="A (es. 1945)" size="6">
                    </div>
                </div>

                <div class="filter-divider"></div>

                <div class="search-row">
                    <label for="sort">Ordina per</label>
                    <select id="sort" name="sort">
                        <option value="title_asc"<?=  $sortRaw === 'title_asc'  ? ' selected' : '' ?>>Titolo (A → Z)</option>
                        <option value="title_desc"<?= $sortRaw === 'title_desc' ? ' selected' : '' ?>>Titolo (Z → A)</option>
                        <option value="author_asc"<?= $sortRaw === 'author_asc' ? ' selected' : '' ?>>Autore (A → Z)</option>
                        <option value="author_desc"<?= $sortRaw === 'author_desc' ? ' selected' : '' ?>>Autore (Z → A)</option>
                    </select>
                </div>

                <div class="search-row">
                    <label for="per_page">Per pagina</label>
                    <select id="per_page" name="per_page">
                        <option value="10"<?= $perPage === 10 ? ' selected' : '' ?>>10</option>
                        <option value="20"<?= $perPage === 20 ? ' selected' : '' ?>>20</option>
                        <option value="50"<?= $perPage === 50 ? ' selected' : '' ?>>50</option>
                    </select>
                </div>
            </aside>
        </div>
    </form>

    <?php if ($whereSql === ''): ?>
        <p>Compila una o più righe sopra e avvia la ricerca.</p>
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
        </header>

        <?php if ($total === 0): ?>
            <p>Nessun record trovato.</p>
        <?php else: ?>
            <ul class="result-list result-list--cards">
                <?php foreach ($results as $row):
                    $bibid          = (int)($row['bibid'] ?? 0);
                    $titleVal       = trim((string)($row['title'] ?? ''));
                    $titleRemainder = trim((string)($row['title_remainder'] ?? ''));
                    $authorVal      = trim((string)($row['author'] ?? ''));

                    $availability      = $availabilityMap[$bibid] ?? ['state' => 'unknown', 'label' => ''];
                    $availabilityState = (string)($availability['state'] ?? 'unknown');
                    $availabilityLabel = (string)($availability['label'] ?? '');
                    $alreadyHeld       = ($patron && isset($alreadyHeldMap[$bibid]));

                    $year260 = trim((string)($row['pub_year_260'] ?? ''));
                    $year264 = trim((string)($row['pub_year_264'] ?? ''));
                    $yearRaw = $year260 !== '' ? $year260 : $year264;
                    $yearVal = '';
                    if ($yearRaw !== '') {
                        if (preg_match('/\d{4}/', $yearRaw, $m)) $yearVal = $m[0];
                        else $yearVal = $yearRaw;
                    }

                    $abstractVal = search_trim_abstract(trim((string)($row['abstract_520'] ?? '')));

                    // Cover via CoverService
                    $isbnRaw  = trim((string)($row['isbn_020'] ?? ''));
                    $isbnOrig = CoverService::getIsbnForJs($isbnRaw);
                    $isbn13   = CoverService::toIsbn13($isbnRaw);
                    $hasLocal = ($isbn13 !== '' && CoverService::hasLocalCover($isbn13));
                    $coverUrl    = CoverService::getCoverUrl($isbnRaw, $titleVal, $authorVal);
                    $placeholder = CoverService::placeholderUrl($titleVal);

                    $tags = $subjectsMap[$bibid] ?? [];

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
                            <?php if ($authorVal !== '' || $yearVal !== '' || $availabilityLabel !== ''): ?>
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
                            <?php endif; ?>
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

            <?php if ($pages > 1):
                $queryBase = [
                    'page'          => 'search_advanced',
                    'field'         => array_column($rows, 'field'),
                    'op'            => array_column($rows, 'op'),
                    'bool'          => array_column($rows, 'bool'),
                    'value'         => array_column($rows, 'value'),
                    'material_cd'   => $materialCd,
                    'collection_cd' => $collectionCd,
                    'year_from'     => $yearFromRaw,
                    'year_to'       => $yearToRaw,
                    'sort'          => $sortRaw,
                    'per_page'      => $perPage,
                ];
                $window    = 2;
                $startPage = max(1, $page - $window);
                $endPage   = min($pages, $page + $window);
            ?>
                <nav class="pagination search-pagination" aria-label="Paginazione risultati">
                    <?php if ($page > 1): ?>
                        <a class="page-link page-link--control" href="index.php?<?= http_build_query($queryBase + ['p' => 1]) ?>">« Prima</a>
                        <a class="page-link page-link--control" href="index.php?<?= http_build_query($queryBase + ['p' => $page - 1]) ?>">‹ Indietro</a>
                    <?php endif; ?>
                    <?php if ($startPage > 1): ?>
                        <a class="page-link<?= $page === 1 ? ' is-current' : '' ?>" href="index.php?<?= http_build_query($queryBase + ['p' => 1]) ?>">1</a>
                        <?php if ($startPage > 2): ?><span class="page-ellipsis">…</span><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                        <a class="page-link<?= $p === $page ? ' is-current' : '' ?>" href="index.php?<?= http_build_query($queryBase + ['p' => $p]) ?>"><?= $p ?></a>
                    <?php endfor; ?>
                    <?php if ($endPage < $pages): ?>
                        <?php if ($endPage < $pages - 1): ?><span class="page-ellipsis">…</span><?php endif; ?>
                        <a class="page-link<?= $page === $pages ? ' is-current' : '' ?>" href="index.php?<?= http_build_query($queryBase + ['p' => $pages]) ?>"><?= $pages ?></a>
                    <?php endif; ?>
                    <?php if ($page < $pages): ?>
                        <a class="page-link page-link--control" href="index.php?<?= http_build_query($queryBase + ['p' => $page + 1]) ?>">Avanti ›</a>
                        <a class="page-link page-link--control" href="index.php?<?= http_build_query($queryBase + ['p' => $pages]) ?>">Ultima »</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<script>
(function () {
    const container = document.getElementById('adv-rows');
    const addBtn    = document.getElementById('adv-add-row');
    if (!container || !addBtn) return;

    const maxRows = parseInt(container.getAttribute('data-max-rows') || '7', 10);

    function updateRowStates() {
        container.querySelectorAll('.adv-row').forEach((row, index) => {
            const boolSelect = row.querySelector('.adv-bool');
            const removeBtn  = row.querySelector('.adv-remove');
            if (!boolSelect || !removeBtn) return;
            boolSelect.disabled = index === 0;
            boolSelect.classList.toggle('adv-bool-disabled', index === 0);
            removeBtn.disabled  = index === 0;
            removeBtn.classList.toggle('adv-remove-disabled', index === 0);
        });
    }

    addBtn.addEventListener('click', function () {
        const rows = container.querySelectorAll('.adv-row');
        if (rows.length >= maxRows) return;
        const clone = rows[rows.length - 1].cloneNode(true);
        // Rimuovi eventuali dropdown clonati e resetta il valore
        clone.querySelectorAll('.ac-dropdown').forEach(el => el.remove());
        const input = clone.querySelector('input[name="value[]"]');
        if (input) input.value = '';
        const boolSelect = clone.querySelector('.adv-bool');
        if (boolSelect) boolSelect.value = 'AND';
        container.appendChild(clone);
        updateRowStates();
        if (input && window._initAcInput) window._initAcInput(input);
    });

    container.addEventListener('click', function (e) {
        const btn = e.target.closest('.adv-remove');
        if (!btn) return;
        const rows = container.querySelectorAll('.adv-row');
        if (rows.length <= 1) return;
        btn.closest('.adv-row')?.remove();
        updateRowStates();
    });

    updateRowStates();
})();

document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('.search-form-advanced');
    if (!form) return;

    function hookChange(el) {
        if (!el) return;
        el.addEventListener('change', () => form.submit());
    }

    hookChange(document.getElementById('sort'));
    hookChange(document.getElementById('per_page'));
    hookChange(document.getElementById('material_cd'));
    hookChange(document.getElementById('collection_cd'));

    function hookYear(input) {
        if (!input) return;
        let tid = null;
        input.addEventListener('input', () => {
            if (tid) clearTimeout(tid);
            tid = setTimeout(() => form.submit(), 600);
        });
        input.addEventListener('change', () => form.submit());
    }

    hookYear(document.getElementById('year_from'));
    hookYear(document.getElementById('year_to'));
});
</script>

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
        const isbn   = img.getAttribute('data-isbn');
        const isbn13 = img.getAttribute('data-isbn13');
        const title  = img.getAttribute('data-title');
        const author = img.getAttribute('data-author');

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