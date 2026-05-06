<?php
/**
 * Area Staff - Report e Statistiche
 *
 * Routing: index.php?page=admin_reports
 *
 * PHP 8.3+
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    $baseUrl  = function_exists('base_url') ? (string) base_url() : '';
    $redirect = 'admin_reports';
    header('Location: ' . rtrim($baseUrl, '/') . '/index.php?page=login&redirect=' . urlencode($redirect));
    exit;
}

$baseUrl  = function_exists('base_url') ? rtrim((string) base_url(), '/') : '';
$indexUrl = ($baseUrl !== '') ? $baseUrl . '/index.php' : '/index.php';
$staffName = $_SESSION['staff_fullname'] ?? ($_SESSION['staff_username'] ?? 'Operatore');

if (!isset($db)) {
    $db = $GLOBALS['db'] ?? null;
}
if (!$db instanceof PDO) {
    http_response_code(500);
    echo '<h1>Errore: connessione DB non disponibile</h1>';
    exit;
}

const COPY_STATUS_OUT = 'out';

function int_or_default(mixed $v, int $default): int
{
    if (is_int($v)) return $v;
    if (is_string($v) && ctype_digit($v)) return (int)$v;
    return $default;
}

function clamp_int(int $v, int $min, int $max): int
{
    return max($min, min($max, $v));
}

function hstr(mixed $v): string
{
    return h((string)$v);
}

function build_callno(array $r): string
{
    $parts = [];
    foreach (['call_nmbr1', 'call_nmbr2', 'call_nmbr3'] as $k) {
        $val = isset($r[$k]) ? trim((string)$r[$k]) : '';
        if ($val !== '') $parts[] = $val;
    }
    return implode(' ', $parts);
}

function build_patron_label(array $r): string
{
    $ln   = trim((string)($r['last_name'] ?? ''));
    $fn   = trim((string)($r['first_name'] ?? ''));
    $name = trim($ln . ' ' . $fn);
    $tess = trim((string)($r['patron_barcode'] ?? ''));
    if ($name === '' && $tess === '') return '—';
    if ($name === '') return 'Tessera: ' . $tess;
    if ($tess === '') return $name;
    return $name . ' — Tessera: ' . $tess;
}

function fmt_date_it(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        $dt = DateTime::createFromFormat('Y-m-d', $s);
        return $dt ? $dt->format('d-m-Y') : $s;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $s)) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', str_replace('T', ' ', $s));
        return $dt ? $dt->format('d-m-Y H:i') : $s;
    }
    return $s;
}

function qs(array $overrides): string
{
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return '?' . http_build_query($q);
}

function is_valid_ymd(string $s): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
    [$y, $m, $d] = array_map('intval', explode('-', $s));
    return checkdate($m, $d, $y);
}

function csv_out(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'wb');
    if ($out === false) { echo "Errore apertura output CSV"; exit; }
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header, ';');
    foreach ($rows as $r) fputcsv($out, $r, ';');
    fclose($out);
    exit;
}

// -----------------------------------------------------------------------------
// Input
// -----------------------------------------------------------------------------
$tab = $_GET['tab'] ?? 'checkouts';
$tab = is_string($tab) ? $tab : 'checkouts';

$allowedTabs = [
    'checkouts'       => 'Prestiti attivi',
    'overdue'         => 'Prestiti scaduti',
    'popular_titles'  => 'Titoli più prestati',
    'popular_authors' => 'Autori più prestati',
    'acquisitions'    => 'Acquisizioni',
    'orphan_titles'   => 'Senza collocazione',
    'duplicates'      => 'Duplicati potenziali',
];
if (!array_key_exists($tab, $allowedTabs)) $tab = 'checkouts';

$print  = (string)($_GET['print'] ?? '') === '1';
$export = (string)($_GET['export'] ?? '') === 'csv';
$q      = trim((string)($_GET['q'] ?? ''));

$page    = int_or_default($_GET['p'] ?? 1, 1);
$perPage = clamp_int(int_or_default($_GET['pp'] ?? 25, 25), 10, 200);
$page    = max(1, $page);
$offset  = ($page - 1) * $perPage;

$sort = (string)($_GET['sort'] ?? '');
$dir  = strtolower((string)($_GET['dir'] ?? 'asc'));
$dir  = ($dir === 'desc') ? 'DESC' : 'ASC';

$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo   = trim((string)($_GET['to'] ?? ''));
if ($dateFrom !== '' && !is_valid_ymd($dateFrom)) $dateFrom = '';
if ($dateTo   !== '' && !is_valid_ymd($dateTo))   $dateTo   = '';

if (($tab === 'popular_titles' || $tab === 'popular_authors') && $dateFrom === '' && $dateTo === '') {
    $dateTo   = date('Y-m-d');
    $dateFrom = date('Y-m-d', strtotime('-365 days'));
}

// -----------------------------------------------------------------------------
// KPI
// -----------------------------------------------------------------------------
$kpi = ['titles' => 0, 'copies' => 0, 'active' => 0, 'overdue' => 0];
$kpiError = '';

try {
    $st = $db->query('SELECT COUNT(*) AS cnt FROM biblio');
    $kpi['titles'] = (int)($st->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
    $st = $db->query('SELECT COUNT(*) AS cnt FROM biblio_copy');
    $kpi['copies'] = (int)($st->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
    $st = $db->prepare('SELECT COUNT(*) AS cnt FROM biblio_copy WHERE status_cd = :st');
    $st->execute([':st' => COPY_STATUS_OUT]);
    $kpi['active'] = (int)($st->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
    $st = $db->prepare('SELECT COUNT(*) AS cnt FROM biblio_copy WHERE status_cd = :st AND due_back_dt IS NOT NULL AND due_back_dt < CURRENT_DATE');
    $st->execute([':st' => COPY_STATUS_OUT]);
    $kpi['overdue'] = (int)($st->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
} catch (Throwable $e) {
    $kpiError = $e->getMessage();
}

// -----------------------------------------------------------------------------
// ORDER BY
// -----------------------------------------------------------------------------
function order_by_for(string $tab, string $sort, string $dir): string
{
    $dir = ($dir === 'DESC') ? 'DESC' : 'ASC';
    $maps = [
        'checkouts'       => ['due' => 'c.due_back_dt', 'call' => 'b.call_nmbr1', 'title' => 'b.title', 'patron' => 'm.last_name'],
        'overdue'         => ['due' => 'c.due_back_dt', 'call' => 'b.call_nmbr1', 'title' => 'b.title', 'patron' => 'm.last_name'],
        'popular_titles'  => ['count' => 'cnt', 'title' => 'b.title'],
        'popular_authors' => ['count' => 'cnt', 'author' => 'author'],
        'acquisitions'    => ['date' => 'b.create_dt', 'call' => 'b.call_nmbr1', 'title' => 'b.title'],
        'orphan_titles'   => ['title' => 'b.title', 'date' => 'b.create_dt', 'copies' => 'copy_count'],
        'duplicates'      => [],
    ];
    $m = $maps[$tab] ?? [];
    if ($sort === '' || !isset($m[$sort])) {
        return match ($tab) {
            'checkouts', 'overdue' => 'ORDER BY c.due_back_dt ' . $dir . ', b.title ASC',
            'popular_titles'       => 'ORDER BY cnt DESC, b.title ASC',
            'popular_authors'      => 'ORDER BY cnt DESC, author ASC',
            'acquisitions'         => 'ORDER BY b.create_dt DESC, b.title ASC',
            'orphan_titles'        => 'ORDER BY b.create_dt DESC, b.title ASC',
            default                => 'ORDER BY 1',
        };
    }
    return 'ORDER BY ' . $m[$sort] . ' ' . $dir;
}

// -----------------------------------------------------------------------------
// Query
// -----------------------------------------------------------------------------
$title     = $allowedTabs[$tab];
$error     = '';
$totalRows = 0;
$dataRows  = [];
$dupIsbn        = [];
$dupTitleAuthor = [];
$csvHeader = [];
$csvRows   = [];

$limitNormal = $perPage;
$limitExport = 5000;
$limitPrint  = 2000;

$printRowsPerPage = match ($tab) {
    'checkouts', 'overdue' => 30,
    'acquisitions'         => 34,
    'popular_titles',
    'popular_authors'      => 44,
    default                => 32,
};

$printHref = $indexUrl . qs(['page' => 'admin_reports', 'print' => '1', 'export' => null, 'p' => null]);
$csvHref   = $indexUrl . qs(['page' => 'admin_reports', 'export' => 'csv', 'print' => null, 'p' => null]);

try {
    if ($tab === 'checkouts' || $tab === 'overdue') {
        $where  = 'c.status_cd = :st';
        $params = [':st' => COPY_STATUS_OUT];
        if ($tab === 'overdue') $where .= ' AND c.due_back_dt IS NOT NULL AND c.due_back_dt < CURRENT_DATE';
        if ($q !== '') {
            $where .= ' AND (b.title LIKE :q OR b.author LIKE :q OR c.barcode_nmbr LIKE :q OR m.last_name LIKE :q OR m.first_name LIKE :q OR m.barcode_nmbr LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $st = $db->prepare("SELECT COUNT(*) AS cnt FROM biblio_copy c INNER JOIN biblio b ON b.bibid = c.bibid LEFT JOIN member m ON m.mbrid = c.mbrid WHERE $where");
        $st->execute($params);
        $totalRows = (int)($st->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

        $orderBy = order_by_for($tab, $sort, strtoupper($dir));
        $sqlData = "SELECT c.bibid, c.copyid, c.barcode_nmbr AS copy_barcode, c.due_back_dt, b.title, b.author, b.call_nmbr1, b.call_nmbr2, b.call_nmbr3, m.mbrid, m.last_name, m.first_name, m.barcode_nmbr AS patron_barcode FROM biblio_copy c INNER JOIN biblio b ON b.bibid = c.bibid LEFT JOIN member m ON m.mbrid = c.mbrid WHERE $where $orderBy";
        $limit = $print ? $limitPrint : ($export ? $limitExport : $limitNormal);
        if (!$print && !$export) $sqlData .= " LIMIT :lim OFFSET :off";
        else $sqlData .= " LIMIT " . (int)$limit;

        $st = $db->prepare($sqlData);
        foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
        if (!$print && !$export) { $st->bindValue(':lim', $limit, PDO::PARAM_INT); $st->bindValue(':off', $offset, PDO::PARAM_INT); }
        $st->execute();
        $dataRows = $st->fetchAll(PDO::FETCH_ASSOC);

        if ($export) {
            $csvHeader = ['Collocazione', 'Barcode copia', 'Titolo', 'Autore', 'Lettore', 'Tessera', 'Scadenza', 'BIBID', 'CopyID', 'MBRID'];
            foreach ($dataRows as $r) {
                $csvRows[] = [build_callno($r), (string)($r['copy_barcode'] ?? ''), (string)($r['title'] ?? ''), (string)($r['author'] ?? ''), trim((string)($r['last_name'] ?? '') . ' ' . (string)($r['first_name'] ?? '')), (string)($r['patron_barcode'] ?? ''), (string)($r['due_back_dt'] ?? ''), (string)($r['bibid'] ?? ''), (string)($r['copyid'] ?? ''), (string)($r['mbrid'] ?? '')];
            }
            csv_out(($tab === 'overdue' ? 'prestiti_scaduti' : 'prestiti_attivi') . '_' . date('Ymd_His') . '.csv', $csvHeader, $csvRows);
        }

    } elseif ($tab === 'popular_titles') {
        $where  = "h.status_cd = :st";
        $params = [':st' => COPY_STATUS_OUT];
        if ($dateFrom !== '') { $where .= " AND h.status_begin_dt >= :from_dt"; $params[':from_dt'] = $dateFrom . ' 00:00:00'; }
        if ($dateTo   !== '') { $where .= " AND h.status_begin_dt <= :to_dt";   $params[':to_dt']   = $dateTo   . ' 23:59:59'; }

        $st = $db->prepare("SELECT COUNT(*) AS cnt FROM (SELECT h.bibid FROM biblio_status_hist h WHERE $where GROUP BY h.bibid) t");
        $st->execute($params);
        $totalRows = (int)($st->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

        $orderBy = order_by_for($tab, $sort, strtoupper($dir));
        $sqlData = "SELECT b.bibid, b.title, b.author, b.call_nmbr1, b.call_nmbr2, b.call_nmbr3, COUNT(*) AS cnt FROM biblio_status_hist h INNER JOIN biblio b ON b.bibid = h.bibid WHERE $where GROUP BY b.bibid, b.title, b.author, b.call_nmbr1, b.call_nmbr2, b.call_nmbr3 $orderBy LIMIT " . (int)($print ? 300 : ($export ? $limitExport : 100));
        $st = $db->prepare($sqlData);
        foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
        $st->execute();
        $dataRows = $st->fetchAll(PDO::FETCH_ASSOC);

        if ($export) {
            $csvHeader = ['Prestiti', 'Collocazione', 'Titolo', 'Autore', 'BIBID'];
            foreach ($dataRows as $r) $csvRows[] = [(string)($r['cnt'] ?? '0'), build_callno($r), (string)($r['title'] ?? ''), (string)($r['author'] ?? ''), (string)($r['bibid'] ?? '')];
            csv_out('titoli_piu_prestati_' . date('Ymd_His') . '.csv', $csvHeader, $csvRows);
        }

    } elseif ($tab === 'popular_authors') {
        $where  = "h.status_cd = :st";
        $params = [':st' => COPY_STATUS_OUT];
        if ($dateFrom !== '') { $where .= " AND h.status_begin_dt >= :from_dt"; $params[':from_dt'] = $dateFrom . ' 00:00:00'; }
        if ($dateTo   !== '') { $where .= " AND h.status_begin_dt <= :to_dt";   $params[':to_dt']   = $dateTo   . ' 23:59:59'; }

        $st = $db->prepare("SELECT COUNT(*) AS cnt FROM (SELECT b.author FROM biblio_status_hist h INNER JOIN biblio b ON b.bibid = h.bibid WHERE $where AND b.author IS NOT NULL AND b.author <> '' GROUP BY b.author) t");
        $st->execute($params);
        $totalRows = (int)($st->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

        $orderBy = order_by_for($tab, $sort, strtoupper($dir));
        $sqlData = "SELECT b.author AS author, COUNT(*) AS cnt FROM biblio_status_hist h INNER JOIN biblio b ON b.bibid = h.bibid WHERE $where AND b.author IS NOT NULL AND b.author <> '' GROUP BY b.author $orderBy LIMIT " . (int)($print ? 300 : ($export ? $limitExport : 100));
        $st = $db->prepare($sqlData);
        foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
        $st->execute();
        $dataRows = $st->fetchAll(PDO::FETCH_ASSOC);

        if ($export) {
            $csvHeader = ['Prestiti', 'Autore'];
            foreach ($dataRows as $r) $csvRows[] = [(string)($r['cnt'] ?? '0'), (string)($r['author'] ?? '')];
            csv_out('autori_piu_prestati_' . date('Ymd_His') . '.csv', $csvHeader, $csvRows);
        }

    } elseif ($tab === 'acquisitions') {
        $where  = "b.opac_flg='Y'";
        $params = [];
        if ($dateFrom === '' && $dateTo === '') { $dateTo = date('Y-m-d'); $dateFrom = date('Y-m-d', strtotime('-365 days')); }
        if ($dateFrom !== '') { $where .= " AND b.create_dt >= :from_dt"; $params[':from_dt'] = $dateFrom . ' 00:00:00'; }
        if ($dateTo   !== '') { $where .= " AND b.create_dt <= :to_dt";   $params[':to_dt']   = $dateTo   . ' 23:59:59'; }
        if ($q !== '') { $where .= " AND (b.title LIKE :q OR b.author LIKE :q OR b.call_nmbr1 LIKE :q)"; $params[':q'] = '%' . $q . '%'; }

        $st = $db->prepare("SELECT COUNT(*) AS cnt FROM biblio b WHERE $where");
        $st->execute($params);
        $totalRows = (int)($st->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

        $orderBy = order_by_for($tab, $sort, strtoupper($dir));
        $sqlData = "SELECT b.bibid, b.create_dt, b.title, b.author, b.call_nmbr1, b.call_nmbr2, b.call_nmbr3 FROM biblio b WHERE $where $orderBy";
        $limit = $print ? $limitPrint : ($export ? $limitExport : $limitNormal);
        if (!$print && !$export) $sqlData .= " LIMIT :lim OFFSET :off";
        else $sqlData .= " LIMIT " . (int)$limit;

        $st = $db->prepare($sqlData);
        foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
        if (!$print && !$export) { $st->bindValue(':lim', $limit, PDO::PARAM_INT); $st->bindValue(':off', $offset, PDO::PARAM_INT); }
        $st->execute();
        $dataRows = $st->fetchAll(PDO::FETCH_ASSOC);

        if ($export) {
            $csvHeader = ['Data creazione', 'Collocazione', 'Titolo', 'Autore', 'BIBID'];
            foreach ($dataRows as $r) $csvRows[] = [(string)($r['create_dt'] ?? ''), build_callno($r), (string)($r['title'] ?? ''), (string)($r['author'] ?? ''), (string)($r['bibid'] ?? '')];
            csv_out('acquisizioni_' . date('Ymd_His') . '.csv', $csvHeader, $csvRows);
        }

    } elseif ($tab === 'orphan_titles') {
        $where  = "(b.call_nmbr1 IS NULL OR TRIM(b.call_nmbr1) = '')";
        $params = [];
        if ($q !== '') { $where .= " AND (b.title LIKE :q OR b.author LIKE :q)"; $params[':q'] = '%' . $q . '%'; }

        $st = $db->prepare("SELECT COUNT(*) AS cnt FROM biblio b WHERE $where");
        $st->execute($params);
        $totalRows = (int)($st->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

        $orderBy = order_by_for($tab, $sort, strtoupper($dir));
        $sqlData = "
            SELECT b.bibid, b.title, b.author, b.create_dt, b.opac_flg,
                   COUNT(bc.copyid) AS copy_count
            FROM biblio b
            LEFT JOIN biblio_copy bc ON bc.bibid = b.bibid
            WHERE $where
            GROUP BY b.bibid, b.title, b.author, b.create_dt, b.opac_flg
            $orderBy";
        $limit = $print ? $limitPrint : ($export ? $limitExport : $limitNormal);
        if (!$print && !$export) $sqlData .= " LIMIT :lim OFFSET :off";
        else $sqlData .= " LIMIT " . (int)$limit;

        $st = $db->prepare($sqlData);
        foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
        if (!$print && !$export) { $st->bindValue(':lim', $limit, PDO::PARAM_INT); $st->bindValue(':off', $offset, PDO::PARAM_INT); }
        $st->execute();
        $dataRows = $st->fetchAll(PDO::FETCH_ASSOC);

        if ($export) {
            $csvHeader = ['BIBID', 'Titolo', 'Autore', 'Copie', 'OPAC', 'Data creazione'];
            foreach ($dataRows as $r) $csvRows[] = [
                (string)($r['bibid'] ?? ''), (string)($r['title'] ?? ''), (string)($r['author'] ?? ''),
                (string)($r['copy_count'] ?? '0'), (string)($r['opac_flg'] ?? ''), (string)($r['create_dt'] ?? ''),
            ];
            csv_out('titoli_senza_collocazione_' . date('Ymd_His') . '.csv', $csvHeader, $csvRows);
        }

    } elseif ($tab === 'duplicates') {
        // ISBN duplicates: multiple biblio records sharing the same 020$a value
        $stDupIsbn = $db->query("
            SELECT bf.field_data AS isbn,
                   COUNT(DISTINCT bf.bibid) AS cnt,
                   GROUP_CONCAT(DISTINCT bf.bibid ORDER BY bf.bibid SEPARATOR ',') AS bibids
            FROM biblio_field bf
            JOIN biblio b ON b.bibid = bf.bibid
            WHERE bf.tag = 20 AND bf.subfield_cd = 'a'
              AND bf.field_data IS NOT NULL AND TRIM(bf.field_data) != ''
            GROUP BY bf.field_data
            HAVING cnt > 1
            ORDER BY cnt DESC, bf.field_data
            LIMIT 200
        ");
        $dupIsbn = $stDupIsbn->fetchAll(PDO::FETCH_ASSOC);

        // Title+author duplicates
        $stDupTA = $db->query("
            SELECT MIN(b.title) AS title, MIN(b.author) AS author,
                   COUNT(*) AS cnt,
                   GROUP_CONCAT(b.bibid ORDER BY b.bibid SEPARATOR ',') AS bibids
            FROM biblio b
            WHERE b.title IS NOT NULL AND TRIM(b.title) != ''
            GROUP BY LOWER(TRIM(b.title)), LOWER(TRIM(COALESCE(b.author, '')))
            HAVING cnt > 1
            ORDER BY cnt DESC, title
            LIMIT 200
        ");
        $dupTitleAuthor = $stDupTA->fetchAll(PDO::FETCH_ASSOC);

        $totalRows = count($dupIsbn) + count($dupTitleAuthor);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$totalPages  = ($perPage > 0) ? (int)ceil($totalRows / $perPage) : 1;
if ($totalPages < 1) $totalPages = 1;

$periodLabel = '';
if ($dateFrom !== '' || $dateTo !== '') {
    $periodLabel = fmt_date_it($dateFrom !== '' ? $dateFrom : '…') . ' → ' . fmt_date_it($dateTo !== '' ? $dateTo : '…');
}

?>
<style>
.reports-topbar {
  display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; justify-content:space-between;
  margin-bottom: 10px;
}
.reports-actions { display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }

.reports-kpis {
  display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 10px; margin-top: 6px;
}
.reports-kpi { background:#fff; border:1px solid rgba(0,0,0,.12); border-radius:8px; padding:8px 10px; }
.reports-kpi .label { font-size:.80rem; color:#666; }
.reports-kpi .value { font-size:1.10rem; font-weight:600; margin-top:2px; }

.reports-tabs {
  display:flex; gap:.45rem; flex-wrap:wrap; align-items:center;
  margin: 10px 0 8px; padding: 6px;
  background: rgba(0,0,0,.02); border: 1px solid rgba(0,0,0,.10); border-radius: 10px;
}
.reports-tab {
  display:inline-block; padding: 7px 10px; border-radius: 999px;
  border: 1px solid rgba(0,0,0,.14); background: #fff;
  text-decoration:none; font-size: .88rem; font-weight: 500; line-height: 1; color: inherit;
}
.reports-tab:hover { border-color: rgba(0,0,0,.22); }
.reports-tab--active { border-color: rgba(0,0,0,.28); font-weight: 600; box-shadow: 0 1px 0 rgba(0,0,0,.06); }

.reports-panel {
  display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-end; margin: 10px 0 8px;
}
.reports-panel .input { min-height: 34px; font-size: .90rem; }
.reports-panel .field { display:flex; flex-direction:column; gap:4px; }
.reports-panel .field label { font-size:.80rem; color:#666; }

.reports-meta { font-size:.86rem; color:#666; margin: 6px 0; }

.reports-table-wrap {
  overflow: visible;
  border:1px solid rgba(0,0,0,.14); border-radius:8px; background:#fff;
}
.reports-table {
  width:100%; border-collapse:collapse; table-layout: fixed; min-width: 0;
}
.reports-table th, .reports-table td {
  padding:8px 9px; border-bottom:1px solid rgba(0,0,0,.12);
  vertical-align:top; font-size: .88rem; overflow-wrap: anywhere; word-break: break-word;
}
.reports-table th {
  background: rgba(0,0,0,.03); text-align:left; font-weight:600;
  font-size:.86rem; border-bottom:1px solid rgba(0,0,0,.18);
}
.reports-table .muted { color:#666; font-size:.80rem; margin-top:3px; }

.reports-table--loans th:nth-child(1), .reports-table--loans td:nth-child(1) { width: 16%; }
.reports-table--loans th:nth-child(2), .reports-table--loans td:nth-child(2) { width: 10%; }
.reports-table--loans th:nth-child(3), .reports-table--loans td:nth-child(3) { width: 40%; }
.reports-table--loans th:nth-child(4), .reports-table--loans td:nth-child(4) { width: 22%; }
.reports-table--loans th:nth-child(5), .reports-table--loans td:nth-child(5) { width: 12%; }

.reports-table--acq th:nth-child(1), .reports-table--acq td:nth-child(1) { width: 14%; }
.reports-table--acq th:nth-child(2), .reports-table--acq td:nth-child(2) { width: 18%; }
.reports-table--acq th:nth-child(3), .reports-table--acq td:nth-child(3) { width: 54%; }
.reports-table--acq th:nth-child(4), .reports-table--acq td:nth-child(4) { width: 14%; }

.reports-badge {
  display:inline-block; padding:2px 8px; border-radius:999px; font-size:.80rem;
  border:1px solid rgba(0,0,0,.18); font-weight:500; white-space: nowrap;
}
.reports-badge--overdue { border-color:#b91c22; color:#b91c22; font-weight:600; }

.reports-footerbar {
  display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;
  margin-top: 10px;
}

.print-report-head { margin-bottom: 8px; }
.print-report-head h2 { margin: 0 0 2px 0; font-size: 1.05rem; font-weight: 600; }
.print-report-head .meta { font-size: .86rem; color: #333; }
.print-page-break { display:none; }

/* ============================================================
   PRINT — tabella con bordi completi e font ridotto
   ============================================================ */
@media print {
  header, nav, footer, .site-header, .site-footer, .topbar, .utility-bar { display:none !important; }
  .reports-no-print { display:none !important; }
  .page-section { padding:0 !important; margin:0 !important; border:none !important; }
  .staff-card { border:none !important; box-shadow:none !important; }

  /* font ridotto globale in stampa */
  body, .reports-table th, .reports-table td { font-size: 8pt !important; }
  .reports-table .muted { font-size: 7pt !important; }
  .reports-badge { font-size: 7pt !important; padding: 1px 4px !important; }
  .reports-meta { font-size: 8pt !important; }

  /* tabella: bordi completi su tutti i lati */
  .reports-table-wrap {
    border: 1.5pt solid #000 !important;
    border-radius: 0 !important;
    overflow: visible !important;
  }
  .reports-table {
    border-collapse: collapse !important;
  }
  .reports-table th, .reports-table td {
    border: 0.75pt solid #000 !important;
    padding: 4px 5px !important;
    vertical-align: top !important;
  }
  .reports-table th {
    background: #e8e8e8 !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
    font-size: 8pt !important;
    font-weight: 700 !important;
  }
  .reports-badge--overdue { color: #000 !important; font-weight: 700 !important; }

  thead { display: table-header-group; }
  tr, td, th { page-break-inside: avoid; }

  .print-page-break { display:block; page-break-before: always; }
  a { color:#000 !important; text-decoration:none !important; }
}
</style>

<section class="page-section page-staff page-staff-dashboard">
  <div class="staff-header reports-no-print">
    <div class="staff-header-top">
      <div class="staff-header-main">
        <h1>Report e statistiche</h1>
        <p class="staff-header-subtitle">Report operativi per prestiti e statistiche di circolazione. Export CSV e stampa/PDF.</p>
      </div>
      <div class="staff-current-user">
        <span class="staff-current-user-label">Collegato come:</span>
        <strong class="staff-current-user-name"><?= hstr($staffName) ?></strong>
        <a class="staff-logout-link" href="<?= hstr($indexUrl . '?page=staff_logout') ?>">Esci</a>
      </div>
    </div>
  </div>

  <div class="staff-dashboard">
    <section class="staff-card">
      <header class="staff-card-header reports-no-print">
        <div class="staff-card-icon-wrap"><span class="staff-card-icon">📊</span></div>
        <div>
          <h2 class="staff-card-title">Pannello report</h2>
          <p class="staff-card-subtitle">Seleziona il report e applica filtri/azioni.</p>
        </div>
      </header>

      <div class="reports-topbar reports-no-print">
        <div class="reports-actions">
          <a class="button" href="<?= hstr($indexUrl . '?page=staff') ?>">⬅️ Dashboard staff</a>
          <a class="button" href="<?= hstr($indexUrl . '?page=admin_loans') ?>">📘 Prestiti</a>
        </div>
        <div class="reports-actions">
          <a class="button" href="<?= hstr($printHref) ?>">🖨️ Stampa / PDF</a>
          <a class="button" href="<?= hstr($csvHref) ?>">⬇️ Export CSV</a>
        </div>
      </div>

      <?php if ($print): ?>
        <div class="print-report-head">
          <h2><?= hstr($title) ?></h2>
          <div class="meta">
            Stampato il <?= h(date('d-m-Y H:i')) ?> — Operatore: <?= hstr($staffName) ?>
            <?php if ($q !== ''): ?> — Filtro: "<?= hstr($q) ?>"<?php endif; ?>
            <?php if ($periodLabel !== ''): ?> — Periodo: <?= hstr($periodLabel) ?><?php endif; ?>
          </div>
        </div>
        <script>window.addEventListener('load', function () { window.print(); });</script>
      <?php endif; ?>

      <?php if ($kpiError !== ''): ?>
        <p class="staff-card-note" style="color:#b91c22;">Errore KPI: <?= hstr($kpiError) ?></p>
      <?php else: ?>
        <div class="reports-kpis reports-no-print">
          <div class="reports-kpi"><div class="label">Titoli</div><div class="value"><?= (int)$kpi['titles'] ?></div></div>
          <div class="reports-kpi"><div class="label">Copie</div><div class="value"><?= (int)$kpi['copies'] ?></div></div>
          <div class="reports-kpi"><div class="label">Prestiti attivi</div><div class="value"><?= (int)$kpi['active'] ?></div></div>
          <div class="reports-kpi"><div class="label">Scaduti</div><div class="value"><?= (int)$kpi['overdue'] ?></div></div>
        </div>
      <?php endif; ?>

      <div class="reports-tabs reports-no-print" role="navigation" aria-label="Report">
        <?php foreach ($allowedTabs as $k => $label): ?>
          <a class="reports-tab <?= ($k === $tab) ? 'reports-tab--active' : '' ?>"
             href="<?= hstr($indexUrl . qs(['page'=>'admin_reports','tab'=>$k,'p'=>1,'print'=>null,'export'=>null])) ?>">
            <?= hstr($label) ?>
          </a>
        <?php endforeach; ?>
      </div>

      <?php if ($tab === 'duplicates'): ?>
        <p class="reports-meta" style="margin:10px 0;">
          Mostra titoli con stesso ISBN (campo 020$a) o stessa combinazione Titolo+Autore. Limite 200 gruppi per sezione.
        </p>
      <?php endif; ?>

      <form method="get" action="<?= hstr($indexUrl) ?>" class="reports-no-print" <?= $tab === 'duplicates' ? 'style="display:none"' : '' ?>>
        <input type="hidden" name="page" value="admin_reports">
        <input type="hidden" name="tab" value="<?= hstr($tab) ?>">
        <div class="reports-panel">
          <div class="field" style="min-width:260px;flex:1;">
            <label>Filtro testo</label>
            <input class="input" type="text" name="q" value="<?= hstr($q) ?>" placeholder="Cerca...">
          </div>

          <?php if (in_array($tab, ['popular_titles','popular_authors','acquisitions'], true)): ?>
            <div class="field"><label>Dal</label><input class="input" type="date" name="from" value="<?= hstr($dateFrom) ?>"></div>
            <div class="field"><label>Al</label><input class="input" type="date" name="to" value="<?= hstr($dateTo) ?>"></div>
          <?php else: ?>
            <input type="hidden" name="from" value="">
            <input type="hidden" name="to" value="">
          <?php endif; ?>
          <?php if ($tab === 'duplicates'): ?>
            <input type="hidden" name="q" value="">
          <?php endif; ?>

          <?php
            $sortOptions = match ($tab) {
              'checkouts','overdue'  => ['due'=>'Scadenza','call'=>'Collocazione','patron'=>'Lettore','title'=>'Titolo'],
              'popular_titles'       => ['count'=>'Prestiti','title'=>'Titolo'],
              'popular_authors'      => ['count'=>'Prestiti','author'=>'Autore'],
              'acquisitions'         => ['date'=>'Data creazione','call'=>'Collocazione','title'=>'Titolo'],
              'orphan_titles'        => ['title'=>'Titolo','date'=>'Data creazione','copies'=>'Copie'],
              default                => []
            };
          ?>

          <?php if (!empty($sortOptions)): ?>
            <div class="field">
              <label>Ordina per</label>
              <select class="input" name="sort">
                <?php foreach ($sortOptions as $v => $lab): ?>
                  <option value="<?= hstr($v) ?>" <?= ($sort===$v ? 'selected' : '') ?>><?= hstr($lab) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Direzione</label>
              <select class="input" name="dir">
                <option value="asc" <?= (strtolower($dir)==='asc' ? 'selected' : '') ?>>ASC</option>
                <option value="desc" <?= (strtolower($dir)==='desc' ? 'selected' : '') ?>>DESC</option>
              </select>
            </div>
          <?php endif; ?>

          <?php if (!$print && !$export && in_array($tab, ['checkouts','overdue','acquisitions','orphan_titles'], true)): ?>
            <div class="field">
              <label>Per pagina</label>
              <select class="input" name="pp">
                <?php foreach ([25,50,100,200] as $n): ?>
                  <option value="<?= $n ?>" <?= ($perPage===(int)$n ? 'selected' : '') ?>><?= (int)$n ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php else: ?>
            <input type="hidden" name="pp" value="<?= (int)$perPage ?>">
          <?php endif; ?>

          <div class="field"><label>&nbsp;</label><button class="button" type="submit">Applica</button></div>
          <div class="field">
            <label>&nbsp;</label>
            <a class="button" href="<?= hstr($indexUrl . qs(['page'=>'admin_reports','tab'=>$tab,'q'=>null,'from'=>null,'to'=>null,'sort'=>null,'dir'=>null,'p'=>1,'print'=>null,'export'=>null])) ?>">Reset</a>
          </div>
        </div>
      </form>

      <?php if ($error !== ''): ?>
        <p class="staff-card-note" style="color:#b91c22;margin-top:10px;">Errore report: <?= hstr($error) ?></p>
      <?php endif; ?>

      <?php
        $thead = [];
        $tableClass = '';
        if ($tab === 'checkouts' || $tab === 'overdue') {
            $thead = ['Collocazione','Copia','Titolo / Autore','Lettore','Scadenza'];
            $tableClass = 'reports-table--loans';
        } elseif ($tab === 'popular_titles') {
            $thead = ['Prestiti','Collocazione','Titolo / Autore','BIBID'];
        } elseif ($tab === 'popular_authors') {
            $thead = ['Prestiti','Autore'];
        } elseif ($tab === 'acquisitions') {
            $thead = ['Data','Collocazione','Titolo / Autore','BIBID'];
            $tableClass = 'reports-table--acq';
        } elseif ($tab === 'orphan_titles') {
            $thead = ['BIBID','Titolo / Autore','Copie','OPAC','Inserito'];
        } elseif ($tab === 'duplicates') {
            $thead = [];
        }
      ?>

      <div class="reports-meta">
        <strong style="font-weight:600;"><?= hstr($title) ?></strong>
        — Risultati: <strong style="font-weight:600;"><?= (int)$totalRows ?></strong>
        <?php if (!$print && !$export && in_array($tab, ['checkouts','overdue','acquisitions'], true)): ?>
          — pagina <?= (int)$page ?> di <?= (int)$totalPages ?>
        <?php endif; ?>
        <?php if ($periodLabel !== ''): ?> — periodo: <?= hstr($periodLabel) ?><?php endif; ?>
      </div>

      <div class="reports-table-wrap" <?= $tab === 'duplicates' ? 'style="display:none"' : '' ?>>
        <table class="reports-table <?= hstr($tableClass) ?>">
          <thead>
            <tr><?php foreach ($thead as $th): ?><th><?= hstr($th) ?></th><?php endforeach; ?></tr>
          </thead>
          <tbody>
          <?php if (empty($dataRows)): ?>
            <tr><td colspan="<?= count($thead) ?>" style="padding:12px;color:#666;">Nessun risultato.</td></tr>
          <?php else: ?>
            <?php
              $rowInPage = 0;
              $openNewPrintPage = function () use ($print, &$rowInPage, $thead, $tableClass) {
                  if (!$print) return;
                  echo '</tbody></table></div>';
                  echo '<div class="print-page-break"></div>';
                  echo '<div class="reports-table-wrap"><table class="reports-table ' . h($tableClass) . '"><thead><tr>';
                  foreach ($thead as $th) echo '<th>' . h($th) . '</th>';
                  echo '</tr></thead><tbody>';
                  $rowInPage = 0;
              };
            ?>

            <?php if ($tab === 'checkouts' || $tab === 'overdue'): ?>
              <?php foreach ($dataRows as $r): ?>
                <?php if ($print && $rowInPage > 0 && $rowInPage % $printRowsPerPage === 0) $openNewPrintPage(); ?>
                <?php
                  $call = build_callno($r);
                  $copy = trim((string)($r['copy_barcode'] ?? ''));
                  $titleTxt  = trim((string)($r['title'] ?? ''));
                  $authorTxt = trim((string)($r['author'] ?? ''));
                  $patron    = build_patron_label($r);
                  $dueIso    = trim((string)($r['due_back_dt'] ?? ''));
                  $dueIt     = fmt_date_it($dueIso);
                  $isOver    = ($dueIso !== '' && $dueIso < date('Y-m-d'));
                ?>
                <tr>
                  <td>
                    <div style="font-weight:600;"><?= hstr($call !== '' ? $call : '—') ?></div>
                    <div class="muted">BIBID <?= hstr($r['bibid'] ?? '') ?> / Copia <?= hstr($r['copyid'] ?? '') ?></div>
                  </td>
                  <td>
                    <div style="font-weight:600;"><?= hstr($copy !== '' ? $copy : '—') ?></div>
                    <div class="muted">Barcode copia</div>
                  </td>
                  <td>
                    <div style="font-weight:600;"><?= hstr($titleTxt) ?></div>
                    <div class="muted"><?= hstr($authorTxt) ?></div>
                  </td>
                  <td>
                    <div style="font-weight:600;"><?= hstr($patron) ?></div>
                    <div class="muted">MBRID <?= hstr($r['mbrid'] ?? '') ?></div>
                  </td>
                  <td>
                    <?php if ($dueIt === ''): ?>
                      <span class="reports-badge">—</span>
                    <?php else: ?>
                      <span class="reports-badge <?= $isOver ? 'reports-badge--overdue' : '' ?>"><?= hstr($dueIt) ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php $rowInPage++; ?>
              <?php endforeach; ?>

            <?php elseif ($tab === 'popular_titles'): ?>
              <?php foreach ($dataRows as $r): ?>
                <?php if ($print && $rowInPage > 0 && $rowInPage % $printRowsPerPage === 0) $openNewPrintPage(); ?>
                <tr>
                  <td><span class="reports-badge" style="font-weight:600;"><?= hstr($r['cnt'] ?? '0') ?></span></td>
                  <td><div style="font-weight:600;"><?= hstr(build_callno($r) ?: '—') ?></div></td>
                  <td>
                    <div style="font-weight:600;"><?= hstr($r['title'] ?? '') ?></div>
                    <div class="muted"><?= hstr($r['author'] ?? '') ?></div>
                  </td>
                  <td><?= hstr($r['bibid'] ?? '') ?></td>
                </tr>
                <?php $rowInPage++; ?>
              <?php endforeach; ?>

            <?php elseif ($tab === 'popular_authors'): ?>
              <?php foreach ($dataRows as $r): ?>
                <?php if ($print && $rowInPage > 0 && $rowInPage % $printRowsPerPage === 0) $openNewPrintPage(); ?>
                <tr>
                  <td><span class="reports-badge" style="font-weight:600;"><?= hstr($r['cnt'] ?? '0') ?></span></td>
                  <td><div style="font-weight:600;"><?= hstr($r['author'] ?? '') ?></div></td>
                </tr>
                <?php $rowInPage++; ?>
              <?php endforeach; ?>

            <?php elseif ($tab === 'acquisitions'): ?>
              <?php foreach ($dataRows as $r): ?>
                <?php
                  if ($print && $rowInPage > 0 && $rowInPage % $printRowsPerPage === 0) $openNewPrintPage();
                  $createIt = fmt_date_it((string)($r['create_dt'] ?? ''));
                ?>
                <tr>
                  <td><?= hstr($createIt) ?></td>
                  <td><div style="font-weight:600;"><?= hstr(build_callno($r) ?: '—') ?></div></td>
                  <td>
                    <div style="font-weight:600;"><?= hstr($r['title'] ?? '') ?></div>
                    <div class="muted"><?= hstr($r['author'] ?? '') ?></div>
                  </td>
                  <td><?= hstr($r['bibid'] ?? '') ?></td>
                </tr>
                <?php $rowInPage++; ?>
              <?php endforeach; ?>
            <?php elseif ($tab === 'orphan_titles'): ?>
              <?php foreach ($dataRows as $r): ?>
                <?php if ($print && $rowInPage > 0 && $rowInPage % $printRowsPerPage === 0) $openNewPrintPage(); ?>
                <?php
                  $isPublic = ($r['opac_flg'] ?? '') === 'Y';
                  $editUrl  = $indexUrl . '?page=staff_catalog_edit&bibid=' . (int)($r['bibid'] ?? 0);
                ?>
                <tr>
                  <td>
                    <a href="<?= hstr($editUrl) ?>" style="font-weight:600;"><?= hstr($r['bibid'] ?? '') ?></a>
                  </td>
                  <td>
                    <div style="font-weight:600;"><?= hstr($r['title'] ?? '') ?></div>
                    <div class="muted"><?= hstr($r['author'] ?? '') ?></div>
                  </td>
                  <td><?= (int)($r['copy_count'] ?? 0) === 0 ? '<span class="reports-badge reports-badge--overdue">0</span>' : hstr($r['copy_count']) ?></td>
                  <td><?= $isPublic ? 'Sì' : '<span class="reports-badge reports-badge--overdue">No</span>' ?></td>
                  <td><?= hstr(fmt_date_it((string)($r['create_dt'] ?? ''))) ?></td>
                </tr>
                <?php $rowInPage++; ?>
              <?php endforeach; ?>

            <?php elseif ($tab === 'duplicates'): ?>
            <?php endif; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($tab === 'duplicates' && !$export): ?>
        <?php
          function render_dup_table(array $rows, string $title, string $keyLabel, string $keyField, string $editUrl): void {
              if (empty($rows)) {
                  echo '<p class="staff-card-note" style="margin-top:12px;">Nessun duplicato trovato per: <strong>' . h($title) . '</strong>.</p>';
                  return;
              }
              echo '<h3 style="margin:16px 0 6px;font-size:.95rem;">' . h($title) . ' — ' . count($rows) . ' gruppi</h3>';
              echo '<div class="reports-table-wrap" style="margin-bottom:16px;"><table class="reports-table"><thead><tr>';
              echo '<th style="width:10%;">Copie</th><th style="width:35%;">' . h($keyLabel) . '</th><th>Titolo</th><th>BIBID nel gruppo</th>';
              echo '</tr></thead><tbody>';
              foreach ($rows as $r) {
                  $cnt    = (int)($r['cnt'] ?? 0);
                  $key    = trim((string)($r[$keyField] ?? ''));
                  $title2 = trim((string)($r['title'] ?? $key));
                  $author = trim((string)($r['author'] ?? ''));
                  $bibids = array_map('intval', explode(',', (string)($r['bibids'] ?? '')));
                  echo '<tr>';
                  echo '<td><span class="reports-badge reports-badge--overdue">' . $cnt . '</span></td>';
                  echo '<td style="font-weight:600;">' . h($key) . '</td>';
                  echo '<td><div style="font-weight:600;">' . h($title2) . '</div>';
                  if ($author !== '') echo '<div class="muted">' . h($author) . '</div>';
                  echo '</td>';
                  echo '<td>';
                  foreach ($bibids as $bid) {
                      echo '<a href="' . h($editUrl . $bid) . '" style="margin-right:6px;font-weight:600;">' . (int)$bid . '</a>';
                  }
                  echo '</td></tr>';
              }
              echo '</tbody></table></div>';
          }
          $editBase = $indexUrl . '?page=staff_catalog_edit&bibid=';
          render_dup_table($dupIsbn,        'Duplicati per ISBN',         'ISBN (020$a)', 'isbn',  $editBase);
          render_dup_table($dupTitleAuthor, 'Duplicati per Titolo+Autore','Autore',       'author',$editBase);
        ?>
      <?php endif; ?>

      <?php if (!$print && !$export && $totalPages > 1 && in_array($tab, ['checkouts','overdue','acquisitions','orphan_titles'], true)): ?>
        <div class="reports-footerbar reports-no-print">
          <div class="reports-meta">Pagina <?= (int)$page ?> di <?= (int)$totalPages ?></div>
          <div class="reports-actions">
            <?php if ($page > 1): ?><a class="button" href="<?= hstr($indexUrl . qs(['p' => $page - 1])) ?>">← Precedente</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a class="button" href="<?= hstr($indexUrl . qs(['p' => $page + 1])) ?>">Successiva →</a><?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <p class="staff-card-note reports-no-print" style="margin-top:10px;">
        PDF: usa "Stampa / PDF" e seleziona "Salva come PDF".
        CSV: usa "Export CSV" (disponibile per Prestiti, Acquisizioni, Senza collocazione).
        Prestiti attivi: stato copia <code><?= h(COPY_STATUS_OUT) ?></code>.
      </p>
    </section>
  </div>
</section>