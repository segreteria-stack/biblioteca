<?php
/**
 * Area Staff – Aggiornamento rapido disponibilità copie
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    /** @var array<string,mixed> $cfg */
    $baseUrl = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/');
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=staff_copies');
    exit;
}

/** @var array<string,mixed> $cfg */
$baseUrl  = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/');
$pdo      = DB::conn();
$errors   = [];
$messages = [];

$statusList   = [];
$statusLabels = [];
$statusColors = [
    'in'  => '#16a34a', 'out' => '#dc2626', 'hld' => '#ca8a04',
    'ln'  => '#9333ea', 'mnd' => '#ea580c', 'dis' => '#6b7280',
    'lst' => '#7c3aed', 'ord' => '#0891b2', 'crt' => '#059669', '8' => '#be123c',
];

try {
    $statusList = $pdo->query('SELECT code, description FROM biblio_status_dm ORDER BY description')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($statusList as $s) { $statusLabels[$s['code']] = $s['description']; }
} catch (PDOException) {}

// =============================================================================
// POST
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? '')) {
        $errors[] = 'Sessione scaduta o token non valido, riprova.';
    } else {
        $bibid     = (int)($_POST['bibid'] ?? 0);
        $copyid    = (int)($_POST['copyid'] ?? 0);
        $newStatus = trim((string)($_POST['status_cd'] ?? ''));
        $newDesc   = trim((string)($_POST['copy_desc'] ?? ''));

        if ($bibid <= 0 || $copyid <= 0) {
            $errors[] = 'Dati copia non validi.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT status_cd, mbrid FROM biblio_copy WHERE bibid = :b AND copyid = :c');
                $stmt->execute([':b' => $bibid, ':c' => $copyid]);
                $cur = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$cur) {
                    $errors[] = 'Copia non trovata.';
                } elseif ($cur['mbrid'] !== null && !in_array($newStatus, ['out', 'ln'], true)) {
                    $errors[] = 'Copia in prestito: usa il modulo prestiti per restituirla.';
                } else {
                    $pdo->prepare('
                        UPDATE biblio_copy
                        SET status_cd = :s, status_begin_dt = NOW(), copy_desc = :d
                        WHERE bibid = :b AND copyid = :c LIMIT 1
                    ')->execute([':s' => $newStatus, ':d' => $newDesc !== '' ? $newDesc : null, ':b' => $bibid, ':c' => $copyid]);
                    $messages[] = 'Copia #' . $copyid . ' aggiornata: stato → ' . h($statusLabels[$newStatus] ?? $newStatus) . '.';
                }
            } catch (Throwable) {
                $errors[] = 'Errore durante l\'aggiornamento.';
            }
        }
    }
}

// =============================================================================
// GET – ricerca
// =============================================================================
$qBarcode = trim((string)($_GET['barcode'] ?? ''));
$qBibid   = trim((string)($_GET['bibid'] ?? ''));
$results  = [];

if ($qBarcode !== '' || ($qBibid !== '' && ctype_digit($qBibid))) {
    try {
        if ($qBarcode !== '') {
            $stmt = $pdo->prepare('
                SELECT c.bibid, c.copyid, c.barcode_nmbr, c.status_cd, c.copy_desc, c.due_back_dt, c.mbrid,
                       b.title, b.author, b.call_nmbr1,
                       m.first_name, m.last_name
                FROM biblio_copy c
                JOIN biblio b ON b.bibid = c.bibid
                LEFT JOIN member m ON m.mbrid = c.mbrid
                WHERE c.barcode_nmbr LIKE :q
                ORDER BY c.copyid
                LIMIT 20
            ');
            $stmt->execute([':q' => '%' . $qBarcode . '%']);
        } else {
            $stmt = $pdo->prepare('
                SELECT c.bibid, c.copyid, c.barcode_nmbr, c.status_cd, c.copy_desc, c.due_back_dt, c.mbrid,
                       b.title, b.author, b.call_nmbr1,
                       m.first_name, m.last_name
                FROM biblio_copy c
                JOIN biblio b ON b.bibid = c.bibid
                LEFT JOIN member m ON m.mbrid = c.mbrid
                WHERE c.bibid = :q
                ORDER BY c.copyid
                LIMIT 50
            ');
            $stmt->execute([':q' => (int)$qBibid]);
        }
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException) {
        $errors[] = 'Errore durante la ricerca.';
    }
}
?>
<section class="page-section page-staff">
    <header class="staff-header">
        <div class="staff-header-top">
            <div class="staff-header-main">
                <h1>Disponibilità copie</h1>
                <p class="staff-header-subtitle">Aggiornamento rapido dello stato e della collocazione fisica.</p>
            </div>
        </div>
    </header>

    <?php if (!empty($messages)): ?>
    <div class="alert--success"><?php foreach ($messages as $m): ?><p><?= h($m) ?></p><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
    <div class="alert--error"><?php foreach ($errors as $m): ?><p><?= h($m) ?></p><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="staff-block">
        <form method="get" action="<?= h($baseUrl) ?>/index.php" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="page" value="staff_copies">
            <div class="copy-inline-field" style="flex:1 1 200px;">
                <label for="q-barcode">Barcode</label>
                <input type="text" id="q-barcode" name="barcode" value="<?= h($qBarcode) ?>" placeholder="es. 0001234" autofocus>
            </div>
            <div style="align-self:flex-end;color:#6b7280;font-size:0.85rem;padding-bottom:0.4rem;">oppure</div>
            <div class="copy-inline-field" style="flex:0 1 120px;">
                <label for="q-bibid">BibID</label>
                <input type="text" id="q-bibid" name="bibid" value="<?= h($qBibid) ?>" placeholder="es. 1234" inputmode="numeric">
            </div>
            <div style="align-self:flex-end;"><button type="submit" class="btn-primary">Cerca</button></div>
            <div style="align-self:flex-end;"><a class="btn-link" href="<?= h($baseUrl) ?>/index.php?page=staff">Dashboard</a></div>
        </form>
    </div>

    <?php if ($qBarcode !== '' || $qBibid !== ''): ?>
    <div class="staff-block">
        <?php if (empty($results)): ?>
        <p style="color:#6b7280;">Nessuna copia trovata.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="copy-table">
                <thead>
                    <tr>
                        <th>Barcode</th><th>BibID</th><th>Titolo / Autore</th>
                        <th>Stato attuale</th><th>Prestatario</th><th colspan="3">Aggiorna</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $row):
                    $cid    = (int)$row['copyid'];
                    $bid    = (int)$row['bibid'];
                    $status = (string)$row['status_cd'];
                    $color  = $statusColors[$status] ?? '#374151';
                    $inLoan = $row['mbrid'] !== null;
                ?>
                <tr>
                    <td class="copy-barcode"><?= h($row['barcode_nmbr']) ?></td>
                    <td><a href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_edit&edit_bibid=<?= $bid ?>">#<?= $bid ?></a></td>
                    <td>
                        <strong><?= h(mb_strimwidth($row['title'] ?? '', 0, 60, '…')) ?></strong>
                        <?php if (!empty($row['author'])): ?><br><small style="color:#6b7280;"><?= h($row['author']) ?></small><?php endif; ?>
                    </td>
                    <td><span style="color:<?= $color ?>;font-weight:600;"><?= h($statusLabels[$status] ?? $status) ?></span></td>
                    <td><?= $inLoan ? h(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) : '—' ?></td>
                    <td colspan="3">
                        <?php if ($inLoan): ?>
                        <small style="color:#9ca3af;">In prestito — usa il modulo prestiti per la restituzione</small>
                        <?php else: ?>
                        <form method="post"
                              action="<?= h($baseUrl) ?>/index.php?page=staff_copies<?= ($qBarcode !== '' ? '&barcode=' . urlencode($qBarcode) : '&bibid=' . $bid) ?>"
                              style="display:flex;gap:0.4rem;flex-wrap:wrap;align-items:flex-end;">
                            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="bibid" value="<?= $bid ?>">
                            <input type="hidden" name="copyid" value="<?= $cid ?>">
                            <div class="copy-inline-field" style="flex:0 1 140px;">
                                <label>Stato</label>
                                <select name="status_cd">
                                    <?php foreach ($statusList as $s):
                                        if (in_array($s['code'], ['out', 'ln'], true)) continue;
                                    ?>
                                    <option value="<?= h($s['code']) ?>" <?= $status === $s['code'] ? 'selected' : '' ?>><?= h($s['description']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="copy-inline-field" style="flex:1 1 140px;">
                                <label>Nota</label>
                                <input type="text" name="copy_desc" value="<?= h($row['copy_desc'] ?? '') ?>">
                            </div>
                            <div class="copy-inline-actions">
                                <button type="submit" class="btn-primary" style="padding:0.35rem 0.7rem;font-size:0.85rem;">Salva</button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>
