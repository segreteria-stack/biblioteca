<?php
/**
 * Area Staff - Stampa massiva barcode
 * Elenca tutte le copie attive con barcode e titolo; permette la stampa
 * di etichette via browser usando JsBarcode (CDN) e CSS @media print.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    /** @var array<string,mixed> $cfg */
    $baseUrl = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/');
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=staff_barcodes');
    exit;
}

/** @var PDO $pdo */
/** @var array<string,mixed> $cfg */
$baseUrl = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/');

// -----------------------------------------------------------------------------
// Filtri GET
// -----------------------------------------------------------------------------
$filterStatus  = trim((string)($_GET['status'] ?? ''));
$filterBibid   = (int)($_GET['bibid'] ?? 0);
$filterCollect = (int)($_GET['collection_cd'] ?? 0);
$printMode     = isset($_GET['print']);

$allowedStatuses = ['in', 'out', 'ln', 'hld', 'crt', 'mnd', 'ord', 'lst', 'dis'];

// -----------------------------------------------------------------------------
// Query copie
// -----------------------------------------------------------------------------
$where  = ["c.status_cd NOT IN ('lst','dis')"];
$params = [];

if ($filterStatus !== '' && in_array($filterStatus, $allowedStatuses, true)) {
    $where[]  = 'c.status_cd = ?';
    $params[] = $filterStatus;
}
if ($filterBibid > 0) {
    $where[]  = 'c.bibid = ?';
    $params[] = $filterBibid;
}
if ($filterCollect > 0) {
    $where[]  = 'b.collection_cd = ?';
    $params[] = $filterCollect;
}

$whereSql = implode(' AND ', $where);

try {
    $stmt = $pdo->prepare("
        SELECT c.bibid, c.copyid, c.barcode_nmbr, c.status_cd, c.copy_desc,
               b.title, b.author, b.call_nmbr1,
               cd.description AS collection_name
        FROM biblio_copy c
        JOIN biblio b ON b.bibid = c.bibid
        LEFT JOIN collection_dm cd ON cd.code = b.collection_cd
        WHERE $whereSql
        ORDER BY b.title ASC, c.copyid ASC
    ");
    $stmt->execute($params);
    $copies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $copies = [];
}

// Collezioni per filtro
try {
    $collections = $pdo->query("SELECT code, description FROM collection_dm ORDER BY description")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $collections = [];
}
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Stampa barcode — Staff</title>
    <style>
    /* ---- SCHERMO ---- */
    @media screen {
        body { font-family: system-ui, sans-serif; margin: 0; background: #f5f5f5; }
        .bc-topbar { background: #1a1a2e; color: #fff; padding: .75rem 1.5rem; display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .bc-topbar a { color: #aac; font-size: .9rem; }
        .bc-topbar h1 { margin: 0; font-size: 1.1rem; flex: 1; }
        .bc-filters { background: #fff; border-bottom: 1px solid #e5e7eb; padding: .75rem 1.5rem; display: flex; gap: .75rem; flex-wrap: wrap; align-items: flex-end; }
        .bc-filters label { font-size: .85rem; display: flex; flex-direction: column; gap: .25rem; }
        .bc-filters select, .bc-filters input { padding: .35rem .5rem; border: 1px solid #d1d5db; border-radius: 4px; }
        .bc-filters .btn-apply { padding: .4rem 1rem; background: #1a1a2e; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        .bc-filters .btn-print { padding: .4rem 1rem; background: #b00; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; }
        .bc-info { padding: .5rem 1.5rem; font-size: .85rem; color: #6b7280; }
        .bc-grid { display: flex; flex-wrap: wrap; gap: 10px; padding: 1rem 1.5rem; }
        .bc-label {
            background: #fff;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 8px 10px;
            width: 180px;
            text-align: center;
            font-size: .7rem;
            color: #374151;
            page-break-inside: avoid;
        }
        .bc-label svg { max-width: 160px; height: 60px; }
        .bc-label .bc-title { font-size: .68rem; line-height: 1.2; margin-top: 4px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .bc-label .bc-meta  { font-size: .65rem; color: #6b7280; margin-top: 2px; }
    }

    /* ---- STAMPA ---- */
    @media print {
        @page { size: A4; margin: 10mm; }
        body { margin: 0; background: #fff; }
        .bc-topbar, .bc-filters, .bc-info { display: none !important; }
        .bc-grid { display: flex; flex-wrap: wrap; gap: 6px; padding: 0; }
        .bc-label {
            border: 1px solid #999;
            border-radius: 4px;
            padding: 5px 6px;
            width: 55mm;
            text-align: center;
            font-size: 7pt;
            color: #000;
            page-break-inside: avoid;
        }
        .bc-label svg { max-width: 50mm; height: 18mm; }
        .bc-label .bc-title { font-size: 6.5pt; line-height: 1.2; margin-top: 3px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .bc-label .bc-meta  { font-size: 6pt; color: #555; margin-top: 1px; }
    }
    </style>
</head>
<body>

<div class="bc-topbar">
    <h1>Stampa barcode</h1>
    <a href="<?= h($baseUrl) ?>/index.php?page=staff">← Dashboard</a>
</div>

<form class="bc-filters" method="get" action="<?= h($baseUrl) ?>/index.php">
    <input type="hidden" name="page" value="staff_barcodes">

    <label>Collezione
        <select name="collection_cd">
            <option value="">Tutte</option>
            <?php foreach ($collections as $c): ?>
                <option value="<?= (int)$c['code'] ?>" <?= $filterCollect === (int)$c['code'] ? 'selected' : '' ?>><?= h((string)$c['description']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>Stato copia
        <select name="status">
            <option value="">Tutti (escluso perso/scartato)</option>
            <option value="in"  <?= $filterStatus === 'in'  ? 'selected' : '' ?>>Disponibile (in)</option>
            <option value="out" <?= $filterStatus === 'out' ? 'selected' : '' ?>>In prestito (out)</option>
            <option value="ln"  <?= $filterStatus === 'ln'  ? 'selected' : '' ?>>In lettura (ln)</option>
        </select>
    </label>

    <label>BibID specifico
        <input type="number" name="bibid" value="<?= $filterBibid ?: '' ?>" placeholder="es. 42" min="1">
    </label>

    <button type="submit" class="btn-apply">Filtra</button>
    <button type="button" class="btn-print" onclick="window.print()">🖨 Stampa</button>
</form>

<div class="bc-info">
    <?= count($copies) ?> etichett<?= count($copies) === 1 ? 'a' : 'e' ?> trovat<?= count($copies) === 1 ? 'a' : 'e' ?>.
    Per stampare: usa il pulsante <strong>Stampa</strong> oppure Ctrl+P / Cmd+P.
</div>

<div class="bc-grid" id="bc-grid">
    <?php foreach ($copies as $copy): ?>
    <div class="bc-label">
        <svg class="bc-svg" data-barcode="<?= h((string)$copy['barcode_nmbr']) ?>"></svg>
        <div class="bc-title"><?= h((string)$copy['title']) ?></div>
        <div class="bc-meta"><?= h((string)$copy['call_nmbr1']) ?> · #<?= (int)$copy['copyid'] ?></div>
    </div>
    <?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/barcodes/JsBarcode.code128.min.js"></script>
<script>
(function () {
    document.querySelectorAll('.bc-svg').forEach(function (el) {
        var val = el.getAttribute('data-barcode');
        if (!val) return;
        try {
            JsBarcode(el, val, {
                format: 'CODE128',
                width: 2,
                height: 60,
                displayValue: true,
                fontSize: 11,
                margin: 4,
                background: '#ffffff',
                lineColor: '#000000'
            });
        } catch (e) {
            el.insertAdjacentHTML('afterend', '<span style="color:#c00;font-size:.65rem">' + val + '</span>');
        }
    });
})();
</script>
</body>
</html>
