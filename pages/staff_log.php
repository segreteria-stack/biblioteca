<?php
/**
 * Area Staff – Log di sistema
 *
 * Cronologia aggregata delle modifiche recenti: creazione/modifica record
 * catalogo e movimenti prestiti/restituzioni. Vista in sola lettura.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    /** @var array<string,mixed> $cfg */
    $baseUrl = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/');
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=staff_log');
    exit;
}

/** @var array<string,mixed> $cfg */
$baseUrl = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/');
$pdo     = DB::conn();
$errors  = [];

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// Filtro tipo
$filterType = in_array($_GET['type'] ?? '', ['catalog', 'loans'], true) ? ($_GET['type'] ?? 'all') : 'all';
$page       = max(1, (int)($_GET['p'] ?? 1));
$perPage    = 60;
$offset     = ($page - 1) * $perPage;

// Mappa staffid → nome
$staffMap = [];
try {
    $rows = $pdo->query('SELECT staffid, username, first_name, last_name FROM staff')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $staffMap[(int)$r['staffid']] = $name !== '' ? $name . ' (' . $r['username'] . ')' : $r['username'];
    }
} catch (PDOException) {}

// Etichette stati prestito
$loanLabels = [
    'out' => 'Prestito',
    'in'  => 'Restituzione',
    'ln'  => 'Rinnovo',
    'hld' => 'Prenotazione',
    'mnd' => 'Smarrito (mnd)',
    'lst' => 'Perso',
    'dis' => 'Scartato',
];
$loanColors = [
    'out' => '#dc2626', 'in'  => '#16a34a', 'ln' => '#9333ea',
    'hld' => '#ca8a04', 'mnd' => '#ea580c', 'lst' => '#7c3aed', 'dis' => '#6b7280',
];

// =============================================================================
// Recupero eventi
// =============================================================================
$events = [];

// --- Modifiche catalogo ---
if ($filterType !== 'loans') {
    try {
        $stmt = $pdo->prepare('
            SELECT bibid, title, author, create_dt, last_change_dt, last_change_userid
            FROM biblio
            WHERE last_change_dt IS NOT NULL
               OR create_dt IS NOT NULL
            ORDER BY GREATEST(IFNULL(last_change_dt, \'0000-01-01\'), IFNULL(create_dt, \'0000-01-01\')) DESC
            LIMIT 300
        ');
        $stmt->execute();
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $isNew = (string)$r['create_dt'] === (string)$r['last_change_dt'] || $r['last_change_dt'] === null;
            $dt    = $r['last_change_dt'] ?? $r['create_dt'];
            if ($dt === null) continue;
            $events[] = [
                'dt'      => $dt,
                'type'    => 'catalog',
                'subtype' => $isNew ? 'new' : 'edit',
                'bibid'   => (int)$r['bibid'],
                'title'   => (string)($r['title'] ?? ''),
                'author'  => (string)($r['author'] ?? ''),
                'userid'  => (int)($r['last_change_userid'] ?? 0),
                'extra'   => '',
            ];
        }
    } catch (PDOException $e) {
        $errors[] = 'Errore caricamento modifiche catalogo.';
    }
}

// --- Movimenti prestiti ---
if ($filterType !== 'catalog') {
    try {
        $stmt = $pdo->prepare('
            SELECT h.bibid, h.copyid, h.status_cd, h.status_begin_dt, h.mbrid,
                   b.title, b.author,
                   m.first_name, m.last_name
            FROM biblio_status_hist h
            LEFT JOIN biblio b ON b.bibid = h.bibid
            LEFT JOIN member m ON m.mbrid = h.mbrid
            ORDER BY h.status_begin_dt DESC
            LIMIT 300
        ');
        $stmt->execute();
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $dt = $r['status_begin_dt'];
            if (!$dt) continue;
            $mbrName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $events[] = [
                'dt'      => $dt,
                'type'    => 'loan',
                'subtype' => (string)$r['status_cd'],
                'bibid'   => (int)$r['bibid'],
                'title'   => (string)($r['title'] ?? ''),
                'author'  => (string)($r['author'] ?? ''),
                'userid'  => 0,
                'extra'   => $mbrName !== '' ? 'Lettore: ' . $mbrName : '',
            ];
        }
    } catch (PDOException $e) {
        $errors[] = 'Errore caricamento storico prestiti.';
    }
}

// Ordina per data decrescente e pagina
usort($events, fn($a, $b) => strcmp($b['dt'], $a['dt']));
$total  = count($events);
$events = array_slice($events, $offset, $perPage);

function fmtDt(string $dt): string {
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y H:i', $ts) : $dt;
}
?>
<section class="page-section page-staff">
    <header class="staff-header">
        <div class="staff-header-top">
            <div class="staff-header-main">
                <h1>Log di sistema</h1>
                <p class="staff-header-subtitle">Cronologia delle modifiche: catalogo, prestiti e restituzioni.</p>
            </div>
        </div>
    </header>

    <?php if (!empty($errors)): ?>
    <div class="alert--error"><?php foreach ($errors as $m): ?><p><?= h($m) ?></p><?php endforeach; ?></div>
    <?php endif; ?>

    <!-- Filtri -->
    <div class="staff-block" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
        <span style="font-size:0.88rem;color:#6b7280;">Filtra:</span>
        <?php
        $tabs = ['all' => 'Tutti', 'catalog' => 'Catalogo', 'loans' => 'Prestiti'];
        foreach ($tabs as $tKey => $tLabel):
        ?>
        <a class="<?= $filterType === $tKey ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= h($baseUrl) ?>/index.php?page=staff_log&type=<?= $tKey ?>"
           style="padding:0.3rem 0.8rem;font-size:0.85rem;"><?= h($tLabel) ?></a>
        <?php endforeach; ?>
        <a class="btn-link" style="margin-left:auto;" href="<?= h($baseUrl) ?>/index.php?page=staff">Dashboard</a>
    </div>

    <p style="font-size:0.85rem;color:#6b7280;margin:0.25rem 0 0.75rem;">
        <?= $total ?> eventi totali — pagina <?= $page ?> di <?= max(1, (int)ceil($total / $perPage)) ?>
    </p>

    <!-- Log -->
    <?php if (empty($events)): ?>
    <p style="color:#6b7280;">Nessun evento da mostrare.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="copy-table">
            <thead>
                <tr>
                    <th style="width:130px;">Data / ora</th>
                    <th style="width:100px;">Tipo</th>
                    <th>Titolo</th>
                    <th>BibID</th>
                    <th>Operatore / Lettore</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($events as $ev):
                $isCatalog = ($ev['type'] === 'catalog');
                if ($isCatalog) {
                    $label = $ev['subtype'] === 'new' ? 'Nuovo record' : 'Modifica';
                    $color = $ev['subtype'] === 'new' ? '#059669' : '#0891b2';
                } else {
                    $sub   = $ev['subtype'];
                    $label = $loanLabels[$sub] ?? ucfirst($sub);
                    $color = $loanColors[$sub] ?? '#374151';
                }
                $who = '';
                if ($isCatalog && $ev['userid'] > 0) {
                    $who = $staffMap[$ev['userid']] ?? 'Staff #' . $ev['userid'];
                } elseif (!$isCatalog && $ev['extra'] !== '') {
                    $who = $ev['extra'];
                }
            ?>
            <tr>
                <td style="font-size:0.82rem;color:#374151;white-space:nowrap;"><?= fmtDt($ev['dt']) ?></td>
                <td><span style="font-size:0.78rem;font-weight:700;color:<?= $color ?>;text-transform:uppercase;letter-spacing:.03em;"><?= h($label) ?></span></td>
                <td>
                    <?php if ($ev['title'] !== ''): ?>
                    <a href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_edit&edit_bibid=<?= $ev['bibid'] ?>"
                       style="font-size:0.88rem;"><?= h(mb_strimwidth($ev['title'], 0, 72, '…')) ?></a>
                    <?php if ($ev['author'] !== ''): ?>
                    <span style="font-size:0.78rem;color:#6b7280;margin-left:0.3em;"><?= h(mb_strimwidth($ev['author'], 0, 40, '…')) ?></span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span style="color:#9ca3af;font-size:0.85rem;">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:0.82rem;"><?= $ev['bibid'] > 0 ? $ev['bibid'] : '—' ?></td>
                <td style="font-size:0.82rem;color:#374151;"><?= h($who) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginazione -->
    <?php if ($total > $perPage): ?>
    <nav style="margin-top:1rem;display:flex;gap:0.4rem;flex-wrap:wrap;">
        <?php
        $totalPages = (int)ceil($total / $perPage);
        for ($pg = 1; $pg <= $totalPages; $pg++):
            $href = h($baseUrl) . '/index.php?page=staff_log&type=' . h($filterType) . '&p=' . $pg;
        ?>
        <a href="<?= $href ?>"
           class="<?= $pg === $page ? 'btn-primary' : 'btn-secondary' ?>"
           style="padding:0.3rem 0.65rem;font-size:0.82rem;min-width:2rem;text-align:center;"><?= $pg ?></a>
        <?php endfor; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</section>
