<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    $baseUrl  = function_exists('base_url') ? base_url() : '';
    $redirect = 'staff';
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=' . urlencode($redirect));
    exit;
}

$title   = 'Utenti biblioteca';
$baseUrl = $cfg['app']['base_url'] ?? (function_exists('base_url') ? base_url() : '');

global $db;
if (!($db instanceof PDO)) {
    throw new RuntimeException('Connessione DB non disponibile.');
}

$T_MEMBER        = 'member';
$T_MEMBER_FIELDS = 'member_fields';
$T_COPY          = 'biblio_copy';
$T_PATRON_AUTH   = 'patron_auth';
$LOAN_STATUS     = ['ln', 'out'];

$q    = trim((string)($_GET['q'] ?? ''));
$show = (string)($_GET['show'] ?? 'active'); // active | inactive | all | loans | no_account
$sort = (string)($_GET['sort'] ?? 'name');
$dir  = (string)($_GET['dir'] ?? 'asc');

$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;
$dirSql  = ($dir === 'desc') ? 'DESC' : 'ASC';

$err = '';

$where  = [];
$params = [];

if ($q !== '') {
    $where[] = "(m.last_name LIKE ? OR m.first_name LIKE ? OR m.email LIKE ? OR m.barcode_nmbr LIKE ? OR m.codice_fiscale LIKE ?)";
    $like    = "%{$q}%";
    $params  = [$like, $like, $like, $like, $like];
}

if ($show === 'active') {
    $where[] = "COALESCE(m.is_active,'Y')='Y'";
} elseif ($show === 'inactive') {
    $where[] = "COALESCE(m.is_active,'Y')<>'Y'";
} elseif ($show === 'loans') {
    $where[] = "COALESCE(l.loans_open,0) > 0";
} elseif ($show === 'no_account') {
    $where[] = "pa.mbrid IS NULL";
} else {
    $show = 'all';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

switch ($sort) {
    case 'recent':  $orderSql = "ORDER BY m.last_activity_dt {$dirSql}, m.last_name ASC"; break;
    case 'barcode': $orderSql = "ORDER BY m.barcode_nmbr {$dirSql}, m.last_name ASC"; break;
    case 'loans':   $orderSql = "ORDER BY loans_open {$dirSql}, m.last_name ASC"; break;
    default: $sort = 'name'; $orderSql = "ORDER BY m.last_name {$dirSql}, m.first_name {$dirSql}";
}

$ph = implode(',', array_fill(0, count($LOAN_STATUS), '?'));

$total = 0;
try {
    $st = $db->prepare("
        SELECT COUNT(*)
        FROM {$T_MEMBER} m
        LEFT JOIN (
            SELECT mbrid, COUNT(*) AS loans_open
            FROM {$T_COPY}
            WHERE status_cd IN ({$ph}) AND mbrid IS NOT NULL AND mbrid <> 0
            GROUP BY mbrid
        ) l ON l.mbrid = m.mbrid
        LEFT JOIN {$T_PATRON_AUTH} pa ON pa.mbrid = m.mbrid
        {$whereSql}
    ");
    $st->execute(array_merge($LOAN_STATUS, $params));
    $total = (int)$st->fetchColumn();
} catch (Throwable $e) {
    $err = 'Errore: ' . h($e->getMessage());
}

$totalPages = (int)max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$rows = [];
try {
    $sql = "
      SELECT
        m.mbrid,
        m.barcode_nmbr,
        m.last_name,
        m.first_name,
        m.email,
        m.cel,
        m.is_active,
        m.last_activity_dt,
        m.citta,
        m.cap,
        COALESCE(f.codes,'') AS field_codes,
        COALESCE(l.loans_open,0) AS loans_open,
        CASE WHEN pa.mbrid IS NOT NULL AND pa.pass_hash != '' THEN 1
             WHEN pa.mbrid IS NOT NULL THEN 2
             ELSE 0 END AS account_status
      FROM {$T_MEMBER} m
      LEFT JOIN (
        SELECT mbrid, GROUP_CONCAT(code ORDER BY code SEPARATOR ', ') AS codes
        FROM {$T_MEMBER_FIELDS}
        GROUP BY mbrid
      ) f ON f.mbrid = m.mbrid
      LEFT JOIN (
        SELECT mbrid, COUNT(*) AS loans_open
        FROM {$T_COPY}
        WHERE status_cd IN ({$ph}) AND mbrid IS NOT NULL AND mbrid <> 0
        GROUP BY mbrid
      ) l ON l.mbrid = m.mbrid
      LEFT JOIN {$T_PATRON_AUTH} pa ON pa.mbrid = m.mbrid
      {$whereSql}
      {$orderSql}
      LIMIT ? OFFSET ?
    ";

    $st = $db->prepare($sql);
$st->execute(array_merge($LOAN_STATUS, $params, [(int)$perPage, (int)$offset]));    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $err = 'Errore: ' . h($e->getMessage());
}

$mkUrl = static function(array $override = []) use ($q, $show, $sort, $dir, $page): string {
    $qs = ['page' => 'admin_patrons', 'q' => $q, 'show' => $show, 'sort' => $sort, 'dir' => $dir, 'p' => $page];
    foreach ($override as $k => $v) $qs[$k] = $v;
    return 'index.php?' . http_build_query($qs);
};

// Contatori per i badge filtro
$cntActive = $cntLoans = $cntNoAccount = 0;
try {
    $st = $db->prepare("SELECT COUNT(*) FROM {$T_MEMBER} m WHERE COALESCE(m.is_active,'Y')='Y'");
    $st->execute(); $cntActive = (int)$st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(DISTINCT c.mbrid) FROM {$T_COPY} c WHERE c.status_cd IN ({$ph}) AND c.mbrid IS NOT NULL AND c.mbrid <> 0");
    $st->execute($LOAN_STATUS); $cntLoans = (int)$st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM {$T_MEMBER} m LEFT JOIN {$T_PATRON_AUTH} pa ON pa.mbrid = m.mbrid WHERE pa.mbrid IS NULL");
    $st->execute(); $cntNoAccount = (int)$st->fetchColumn();
} catch (Throwable $e) {}
?>
<section class="card ap-wrap" style="margin-top:20px">

  <div class="ap-head">
    <div>
      <h1 class="ap-title">Utenti biblioteca</h1>
      <p class="ap-meta">
        Totale: <strong><?= (int)$total ?></strong> — Pagina <strong><?= (int)$page ?></strong> di <strong><?= (int)$totalPages ?></strong>
      </p>
    </div>
    <div style="display:flex;gap:8px">
      <a class="button" href="index.php?page=admin_patron_new">+ Nuovo utente</a>
      <a class="button secondary" href="index.php?page=staff">← Area staff</a>
    </div>
  </div>

  <?php if ($err): ?>
    <p style="color:#b00020"><?= $err ?></p>
  <?php endif; ?>

  <!-- Filtri rapidi -->
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
    <?php
    $quickFilters = [
        ['show' => 'active',     'label' => 'Attivi',           'count' => $cntActive],
        ['show' => 'loans',      'label' => 'Con prestiti',      'count' => $cntLoans],
        ['show' => 'no_account', 'label' => 'Solo anagrafica',   'count' => $cntNoAccount],
        ['show' => 'inactive',   'label' => 'Disattivi',         'count' => null],
        ['show' => 'all',        'label' => 'Tutti',             'count' => null],
    ];
    foreach ($quickFilters as $f):
        $isActive = ($show === $f['show']);
    ?>
      <a href="<?= h($mkUrl(['show' => $f['show'], 'p' => 1])) ?>"
         style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:999px;border:1px solid <?= $isActive ? '#111' : '#e5e5e5' ?>;background:<?= $isActive ? '#111' : '#fff' ?>;color:<?= $isActive ? '#fff' : '#444' ?>;font-size:13px;font-weight:600;text-decoration:none">
        <?= h($f['label']) ?>
        <?php if ($f['count'] !== null): ?>
          <span style="background:<?= $isActive ? 'rgba(255,255,255,.25)' : '#f1f1f1' ?>;padding:1px 7px;border-radius:999px;font-size:11px"><?= (int)$f['count'] ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <form method="get" class="ap-toolbar">
    <input type="hidden" name="page" value="admin_patrons">
    <input type="hidden" name="show" value="<?= h($show) ?>">

    <input class="input ap-q" name="q" value="<?= h($q) ?>" placeholder="Cerca: cognome, nome, email, barcode, CF">

    <select class="input" name="sort">
      <option value="name"    <?= $sort==='name'?'selected':'' ?>>Nome</option>
      <option value="recent"  <?= $sort==='recent'?'selected':'' ?>>Attività</option>
      <option value="barcode" <?= $sort==='barcode'?'selected':'' ?>>Barcode</option>
      <option value="loans"   <?= $sort==='loans'?'selected':'' ?>>Prestiti</option>
    </select>

    <select class="input" name="dir">
      <option value="asc"  <?= $dir==='asc'?'selected':'' ?>>A→Z</option>
      <option value="desc" <?= $dir==='desc'?'selected':'' ?>>Z→A</option>
    </select>

    <div class="ap-actions">
      <button class="button" type="submit">Cerca</button>
      <a class="ap-reset" href="index.php?page=admin_patrons">Reset</a>
    </div>
  </form>

  <?php if (!$rows): ?>
    <div style="padding:20px;text-align:center;color:#666;border:1px dashed #ddd;border-radius:8px">
      Nessun utente trovato.
    </div>
  <?php else: ?>
    <div class="ap-grid">
      <?php foreach ($rows as $r): ?>
        <?php
          $mbrid         = (int)($r['mbrid'] ?? 0);
          $name          = trim((string)($r['last_name'] ?? '') . ' ' . (string)($r['first_name'] ?? ''));
          $barcode       = (string)($r['barcode_nmbr'] ?? '');
          $email         = (string)($r['email'] ?? '');
          $cel           = (string)($r['cel'] ?? '');
          $codes         = (string)($r['field_codes'] ?? '');
          $isActive      = (((string)($r['is_active'] ?? 'Y')) === 'Y');
          $lastAct       = (string)($r['last_activity_dt'] ?? '');
          $loansOpen     = (int)($r['loans_open'] ?? 0);
          $accountStatus = (int)($r['account_status'] ?? 0); // 0=nessuno, 1=attivo, 2=invitato
          $citta         = (string)($r['citta'] ?? '');
          $cap           = (string)($r['cap'] ?? '');
          $luogo         = trim($cap . ($cap && $citta ? ' ' : '') . $citta);
          $detailUrl     = 'index.php?page=admin_patron&mbrid=' . $mbrid;
          $lastActShort  = ($lastAct !== '') ? substr($lastAct, 0, 10) : '';
        ?>
        <article class="ap-card">
          <div class="ap-toprow">
            <div>
              <p class="ap-name"><?= h($name !== '' ? $name : '[Senza nome]') ?></p>
              <p class="ap-sub">Tessera: <strong><?= h($barcode !== '' ? $barcode : '—') ?></strong>
                <?php if ($luogo !== ''): ?>
                  · <?= h($luogo) ?>
                <?php endif; ?>
              </p>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px">
              <span class="ap-pill <?= $isActive ? '' : 'inactive' ?>">
                <span class="ap-dot"></span>
                <?= $isActive ? 'Attivo' : 'Disattivo' ?>
              </span>
              <?php if ($accountStatus === 1): ?>
                <span style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;font-size:11px;font-weight:600">
                  <span style="width:6px;height:6px;border-radius:50%;background:#16a34a;display:inline-block"></span>
                  Account attivo
                </span>
              <?php elseif ($accountStatus === 2): ?>
                <span style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;font-size:11px;font-weight:600">
                  <span style="width:6px;height:6px;border-radius:50%;background:#f97316;display:inline-block"></span>
                  Invitato
                </span>
              <?php else: ?>
                <span style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;border:1px solid #e5e5e5;background:#f9f9f9;color:#9ca3af;font-size:11px">
                  Solo anagrafica
                </span>
              <?php endif; ?>
            </div>
          </div>

          <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <?php if ($loansOpen > 0): ?>
              <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;border:1px solid #111;background:#111;color:#fff;font-size:12px;font-weight:600">
                <span style="width:6px;height:6px;border-radius:50%;background:#fff;display:inline-block"></span>
                In prestito: <?= (int)$loansOpen ?>
              </span>
            <?php endif; ?>
          </div>

          <div class="ap-kv">
            <div>
              <p class="k">Email</p>
              <p class="v <?= $email !== '' ? '' : 'muted' ?>"><?= $email !== '' ? h($email) : '—' ?></p>
            </div>
            <div>
              <p class="k">Telefono</p>
              <p class="v <?= $cel !== '' ? '' : 'muted' ?>"><?= $cel !== '' ? h($cel) : '—' ?></p>
            </div>
            <div>
              <p class="k">Ultima attività</p>
              <p class="v <?= $lastActShort !== '' ? '' : 'muted' ?>"><?= $lastActShort !== '' ? h($lastActShort) : '—' ?></p>
            </div>
            <div>
              <p class="k">Profilo</p>
              <p class="v <?= $codes !== '' ? '' : 'muted' ?>"><?= $codes !== '' ? h($codes) : '—' ?></p>
            </div>
          </div>

          <div class="ap-card-bottom">
            <div style="color:var(--ap-muted,#6b7280);font-size:11px">
              ID: <strong><?= (int)$mbrid ?></strong>
            </div>
            <a class="button secondary" href="<?= h($detailUrl) ?>">Dettagli →</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="ap-footer">
    <div>Mostrati <strong><?= count($rows) ?></strong> su <strong><?= (int)$total ?></strong></div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <?php if ($page > 1): ?>
        <a class="button secondary" href="<?= h($mkUrl(['p'=>$page-1])) ?>">← Precedente</a>
      <?php endif; ?>
      <?php if ($page < $totalPages): ?>
        <a class="button secondary" href="<?= h($mkUrl(['p'=>$page+1])) ?>">Successiva →</a>
      <?php endif; ?>
    </div>
  </div>

</section>
