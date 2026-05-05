<?php
$title = 'Area Utente';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

global $db;
if (!($db instanceof PDO) && isset($pdo) && ($pdo instanceof PDO)) {
  $db = $pdo;
}

$Tcfg = (isset($cfg['tables']) && is_array($cfg['tables'])) ? $cfg['tables'] : [];

$T = $Tcfg + [
  'member'             => 'member',
  'biblio'             => 'biblio',
  'biblio_copy'        => 'biblio_copy',
  'biblio_status_hist' => 'biblio_status_hist',
  'biblio_status_dm'   => 'biblio_status_dm',
  'biblio_hold'        => 'biblio_hold',
];

require_once __DIR__ . '/../lib/PatronAuth.php';

$u = PatronAuth::user();
$base = rtrim((string)($cfg['app']['base_url'] ?? ''), '/');

if (!$u) {
  header('Location: ' . $base . '/index.php?page=patron_login');
  exit;
}

if (!($db instanceof PDO)) {
  echo '<div class="page-section"><h1>Errore</h1><p>Connessione database non disponibile.</p></div>';
  exit;
}

$mbrid = (int)$u['mbrid'];

$tab = (string)($_GET['tab'] ?? 'loans');
if (!in_array($tab, ['loans', 'history', 'holds', 'profile', 'password'], true)) {
  $tab = 'loans';
}

$flashOk  = '';
$flashErr = '';

// Leggo flash da sessione (Post/Redirect/Get)
if (!empty($_SESSION['patron_flash_ok'])) {
  $flashOk = $_SESSION['patron_flash_ok'];
  unset($_SESSION['patron_flash_ok']);
}
if (!empty($_SESSION['patron_flash_err'])) {
  $flashErr = $_SESSION['patron_flash_err'];
  unset($_SESSION['patron_flash_err']);
}

$hasCsrf = function_exists('csrf_check') && function_exists('csrf_token');
$action  = (string)($_POST['action'] ?? '');

// Helper: giorni alla scadenza
$daysLeft = function (?string $due): ?int {
  if (!$due) return null;
  $ts = strtotime($due);
  if ($ts === false) return null;
  return (int)floor(($ts - time()) / 86400);
};

// Helper: formatta data italiana
$fmtDate = function (?string $d): string {
  if (!$d || $d === '0000-00-00') return '—';
  $ts = strtotime($d);
  if ($ts === false) return h($d);
  return date('d/m/Y', $ts);
};

// ----------------------------
// LETTURA PROFILO
// ----------------------------
$memberRow = null;
$queryMember = "
  SELECT mbrid, barcode_nmbr, last_name, first_name,
         address, home_phone, work_phone, cel, email,
         born_dt, other, is_active,
         codice_fiscale, indirizzo, civico, cap, citta, provincia
  FROM {$T['member']}
  WHERE mbrid = ?
  LIMIT 1
";
try {
  $stM = $db->prepare($queryMember);
  $stM->execute([$mbrid]);
  $memberRow = $stM->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $memberRow = null;
}

// ----------------------------
// POST: AGGIORNA PROFILO
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_profile') {
  if ($hasCsrf && !csrf_check($_POST['csrf'] ?? '')) {
    $flashErr = 'Token CSRF non valido.';
    $tab = 'profile';
  } else {
    $fields = [
      'first_name'     => trim((string)($_POST['first_name']     ?? '')),
      'last_name'      => trim((string)($_POST['last_name']      ?? '')),
      'email'          => trim((string)($_POST['email']          ?? '')),
      'codice_fiscale' => strtoupper(trim((string)($_POST['codice_fiscale'] ?? ''))),
      'indirizzo'      => trim((string)($_POST['indirizzo']      ?? '')),
      'civico'         => trim((string)($_POST['civico']         ?? '')),
      'cap'            => trim((string)($_POST['cap']            ?? '')),
      'citta'          => trim((string)($_POST['citta']          ?? '')),
      'provincia'      => strtoupper(trim((string)($_POST['provincia']    ?? ''))),
      'cel'            => trim((string)($_POST['cel']            ?? '')),
      'home_phone'     => trim((string)($_POST['home_phone']     ?? '')),
      'work_phone'     => trim((string)($_POST['work_phone']     ?? '')),
    ];

    $err = '';
    if ($fields['first_name'] === '') {
      $err = 'Il nome è obbligatorio.';
    } elseif ($fields['last_name'] === '') {
      $err = 'Il cognome è obbligatorio.';
    } elseif ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
      $err = 'Indirizzo email non valido.';
    } elseif ($fields['provincia'] !== '' && strlen($fields['provincia']) !== 2) {
      $err = 'La provincia deve essere una sigla di 2 lettere (es. UD).';
    }

    if ($err) {
      $flashErr = $err;
      $tab = 'profile';
    } else {
      try {
        $sql = "
          UPDATE {$T['member']}
          SET first_name     = :first_name,
              last_name      = :last_name,
              email          = :email,
              codice_fiscale = :codice_fiscale,
              indirizzo      = :indirizzo,
              civico         = :civico,
              cap            = :cap,
              citta          = :citta,
              provincia      = :provincia,
              cel            = :cel,
              home_phone     = :home_phone,
              work_phone     = :work_phone,
              last_change_dt = NOW()
          WHERE mbrid = :mbrid
          LIMIT 1
        ";
        $up = $db->prepare($sql);
        $up->execute(array_merge(
          $fields,
          [':mbrid' => $mbrid]
        ));
        // aggiorno la sessione (name + email)
        $newName = trim($fields['first_name'] . ' ' . $fields['last_name']);
        $_SESSION['patron']['name']  = $newName;
        $_SESSION['patron']['email'] = $fields['email'] ?: ($_SESSION['patron']['email'] ?? null);
        // PRG: redirect con flash in sessione per evitare doppio invio
        $_SESSION['patron_flash_ok'] = 'Dati aggiornati con successo.';
        header('Location: ' . $base . '/index.php?page=patron_area&tab=profile');
        exit;
      } catch (Throwable $e) {
        $flashErr = 'Errore durante il salvataggio. Riprova.';
        $tab = 'profile';
      }
    }
  }
}

// ----------------------------
// POST: CAMBIO PASSWORD
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'change_password') {
  if ($hasCsrf && !csrf_check($_POST['csrf'] ?? '')) {
    $flashErr = 'Token CSRF non valido.';
    $tab = 'password';
  } else {
    $pwCurrent = (string)($_POST['pw_current'] ?? '');
    $pwNew     = (string)($_POST['pw_new']     ?? '');
    $pwConfirm = (string)($_POST['pw_confirm'] ?? '');

    $errPw = '';
    if ($pwCurrent === '' || $pwNew === '' || $pwConfirm === '') {
      $errPw = 'Compila tutti i campi.';
    } elseif ($pwNew !== $pwConfirm) {
      $errPw = 'La nuova password e la conferma non corrispondono.';
    } else {
      // verifica password attuale
      try {
        $stPw = $db->prepare("SELECT pass_user FROM {$T['member']} WHERE mbrid = ? LIMIT 1");
        $stPw->execute([$mbrid]);
        $row = $stPw->fetch(PDO::FETCH_ASSOC);
        $stored = (string)($row['pass_user'] ?? '');
        $ok = password_verify($pwCurrent, $stored)
           || md5($pwCurrent) === $stored;
        if (!$ok) {
          $errPw = 'La password attuale non è corretta.';
        }
      } catch (Throwable $e) {
        $errPw = 'Errore di verifica. Riprova.';
      }
    }

    if (!$errPw) {
      $valResult = PatronAuth::validatePassword($pwNew);
      if ($valResult !== null) {
        $errPw = $valResult;
      }
    }

    if ($errPw) {
      $flashErr = $errPw;
      $tab = 'password';
    } else {
      try {
        $hash = password_hash($pwNew, PASSWORD_DEFAULT);
        $stUp = $db->prepare("UPDATE {$T['member']} SET pass_user = ?, last_change_dt = NOW() WHERE mbrid = ? LIMIT 1");
        $stUp->execute([$hash, $mbrid]);
        $flashOk = 'Password aggiornata con successo.';
        $tab = 'password';
      } catch (Throwable $e) {
        $flashErr = 'Errore durante l\'aggiornamento della password.';
        $tab = 'password';
      }
    }
  }
}

// ----------------------------
// POST: ANNULLA PRENOTAZIONE
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cancel_hold') {
  if ($hasCsrf && !csrf_check($_POST['csrf'] ?? '')) {
    $flashErr = 'Token CSRF non valido.';
  } else {
    $holdid = (int)($_POST['holdid'] ?? 0);
    if ($holdid <= 0) {
      $flashErr = 'Prenotazione non valida.';
    } else {
      try {
        $del = $db->prepare("DELETE FROM {$T['biblio_hold']} WHERE holdid = ? AND mbrid = ? LIMIT 1");
        $del->execute([$holdid, $mbrid]);
        if ($del->rowCount() > 0) {
          $flashOk = 'Prenotazione annullata.';
        } else {
          $flashErr = 'Prenotazione non trovata.';
        }
      } catch (Throwable $e) {
        $flashErr = 'Errore durante l\'annullamento.';
      }
      $tab = 'holds';
    }
  }
}

// ----------------------------
// PRESTITI ATTIVI
// ----------------------------
$loans = [];
try {
  $stL = $db->prepare("
    SELECT c.bibid, c.copyid, b.title, b.author,
           c.status_cd, c.status_begin_dt, c.due_back_dt
    FROM {$T['biblio_copy']} c
    JOIN {$T['biblio']} b ON b.bibid = c.bibid
    WHERE c.mbrid = ? AND c.status_cd = 'out'
    ORDER BY c.due_back_dt ASC
  ");
  $stL->execute([$mbrid]);
  $loans = $stL->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $loans = [];
}

// ----------------------------
// STORICO PRESTITI
// ----------------------------
$history    = [];
$historyErr = '';
try {
  $stH = $db->prepare("
    SELECT h.bibid, h.copyid, h.status_cd,
           sd.description AS status_desc,
           h.status_begin_dt, h.due_back_dt, h.renewal_count,
           b.title, b.author
    FROM {$T['biblio_status_hist']} h
    JOIN {$T['biblio']} b ON b.bibid = h.bibid
    LEFT JOIN {$T['biblio_status_dm']} sd ON sd.code = h.status_cd
    WHERE h.mbrid = ? AND h.status_cd = 'out'
    ORDER BY h.status_begin_dt DESC
    LIMIT 200
  ");
  $stH->execute([$mbrid]);
  $history = $stH->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $historyErr = 'Storico non disponibile.';
}

// ----------------------------
// PRENOTAZIONI
// ----------------------------
$holds    = [];
$holdsErr = '';
try {
  $stP = $db->prepare("
    SELECT h.holdid, h.bibid, h.copyid, h.hold_begin_dt,
           b.title, b.author,
           SUM(CASE WHEN c.status_cd='in' THEN 1 ELSE 0 END) AS copies_in,
           COUNT(*) AS copies_total
    FROM {$T['biblio_hold']} h
    JOIN {$T['biblio']} b ON b.bibid = h.bibid
    LEFT JOIN {$T['biblio_copy']} c ON c.bibid = h.bibid
    WHERE h.mbrid = ?
    GROUP BY h.holdid, h.bibid, h.copyid, h.hold_begin_dt, b.title, b.author
    ORDER BY h.hold_begin_dt DESC
    LIMIT 200
  ");
  $stP->execute([$mbrid]);
  $holds = $stP->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $holdsErr = 'Prenotazioni non disponibili.';
}

// ----------------------------
// CONTATORI per le stat card
// ----------------------------
$countLoans   = count($loans);
$countHolds   = count($holds);
$countHistory = count($history);

// Iniziali avatar
$nameParts = array_filter(explode(' ', trim((string)($u['name'] ?? ''))));
$initials  = '';
foreach (array_slice($nameParts, 0, 2) as $part) {
  $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
if ($initials === '') $initials = '?';

?>
<!-- =====================================================
     HEADER UTENTE
     ===================================================== -->
<div class="page-section" style="margin-top:1.5rem">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;justify-content:space-between">

    <div style="display:flex;align-items:center;gap:16px">
      <!-- Avatar iniziali -->
      <div style="
        width:52px;height:52px;border-radius:50%;
        background-color:#fff1f2;border:2px solid #fecaca;
        display:flex;align-items:center;justify-content:center;
        font-size:1.1rem;font-weight:700;color:var(--color-primary);
        flex-shrink:0
      "><?= h($initials) ?></div>

      <div>
        <div style="font-size:1.35rem;font-weight:700;line-height:1.2;margin-bottom:4px">
          <?= h($u['name']) ?>
        </div>
        <div style="font-size:0.85rem;color:#6b7280">
          Tessera&nbsp;<strong style="color:#111">#<?= $mbrid ?></strong>
          <?php if (!empty($u['email'])): ?>
            &nbsp;·&nbsp;<?= h($u['email']) ?>
          <?php endif; ?>
        </div>
        <div style="margin-top:6px">
          <span class="ap-pill" style="font-size:0.75rem">
            <span class="ap-dot"></span> Account attivo
          </span>
        </div>
      </div>
    </div>

    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <a href="<?= h($base) ?>/index.php?page=search" class="btn-secondary">Vai al catalogo</a>
      <a href="<?= h($base) ?>/index.php?page=patron_logout" class="btn-secondary">Esci</a>
    </div>
  </div>
</div>

<!-- =====================================================
     STAT CARD
     ===================================================== -->
<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:1rem">
  <div class="staff-metric">
    <span class="staff-metric-num <?= $countLoans > 0 ? 'staff-metric--warn' : '' ?>"><?= $countLoans ?></span>
    <span class="staff-metric-label">Prestiti attivi</span>
  </div>
  <div class="staff-metric">
    <span class="staff-metric-num"><?= $countHolds ?></span>
    <span class="staff-metric-label">Prenotazioni</span>
  </div>
  <div class="staff-metric">
    <span class="staff-metric-num"><?= $countHistory ?></span>
    <span class="staff-metric-label">Prestiti totali</span>
  </div>
</div>

<?php if ($flashOk): ?>
  <div class="page-section" style="padding:0.75rem 1rem;margin-bottom:0.75rem;border-left:3px solid #16a34a;background:#f0fdf4">
    <p style="margin:0;color:#15803d;font-size:0.9rem"><?= h($flashOk) ?></p>
  </div>
<?php endif; ?>
<?php if ($flashErr): ?>
  <div class="page-section" style="padding:0.75rem 1rem;margin-bottom:0.75rem;border-left:3px solid var(--color-primary);background:#fff1f2">
    <p style="margin:0;color:var(--color-primary);font-size:0.9rem"><?= h($flashErr) ?></p>
  </div>
<?php endif; ?>

<!-- =====================================================
     TAB BAR
     ===================================================== -->
<div class="apd-tabs" style="margin-bottom:0">
  <?php
  $tabs = [
    'loans'    => ['label' => 'Prestiti attivi',  'count' => $countLoans   > 0 ? $countLoans   : null],
    'history'  => ['label' => 'Storico',           'count' => $countHistory > 0 ? $countHistory : null],
    'holds'    => ['label' => 'Prenotazioni',      'count' => $countHolds  > 0 ? $countHolds   : null],
    'profile'  => ['label' => 'Dati anagrafici',   'count' => null],
    'password' => ['label' => 'Cambio password',   'count' => null],
  ];
  foreach ($tabs as $key => $t):
  ?>
    <a href="<?= h($base) ?>/index.php?page=patron_area&tab=<?= $key ?>"
       class="apd-tab <?= $tab === $key ? 'is-active' : '' ?>">
      <?= h($t['label']) ?>
      <?php if ($t['count'] !== null): ?>
        <span class="apd-tab-count"><?= (int)$t['count'] ?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<!-- =====================================================
     TAB: PRESTITI ATTIVI
     ===================================================== -->
<?php if ($tab === 'loans'): ?>
<div class="page-section" style="margin-top:0;border-top-left-radius:0;border-top-right-radius:0">
  <h2 class="apd-h2" style="margin-bottom:1rem">Prestiti attivi</h2>

  <?php if (!$loans): ?>
    <div style="padding:1.25rem;border:1px dashed #e5e7eb;border-radius:8px;background:#fafafa;color:#6b7280;text-align:center">
      Nessun prestito attivo al momento.
    </div>
  <?php else: ?>
    <div class="apd-table-wrap">
      <table class="apd-table">
        <thead>
          <tr>
            <th>Titolo</th>
            <th class="apd-nowrap">Copia</th>
            <th class="apd-nowrap">Dal</th>
            <th class="apd-nowrap">Scadenza</th>
            <th class="apd-nowrap">Stato</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($loans as $r):
            $dl = $daysLeft($r['due_back_dt'] ?? null);
            if ($dl === null) {
              $badgeCls  = '';
              $badgeTxt  = '—';
            } elseif ($dl < 0) {
              $badgeCls  = 'staff-status-badge--off';
              $badgeTxt  = 'Scaduto';
            } elseif ($dl <= 3) {
              $badgeCls  = '';
              $badgeTxt  = 'Scade in ' . $dl . ' ' . ($dl === 1 ? 'giorno' : 'giorni');
              $badgeStyle = 'background:#fff7ed;color:#b45309;border:1px solid #fed7aa';
            } else {
              $badgeCls  = 'staff-status-badge--on';
              $badgeTxt  = $dl . ' ' . ($dl === 1 ? 'giorno' : 'giorni');
            }
          ?>
            <tr>
              <td>
                <div class="apd-titlecell"><?= h($r['title']) ?></div>
                <?php if (!empty($r['author'])): ?>
                  <div class="apd-author"><?= h($r['author']) ?></div>
                <?php endif; ?>
              </td>
              <td class="apd-nowrap apd-col-copy">#<?= (int)$r['copyid'] ?></td>
              <td class="apd-col-date"><?= $fmtDate($r['status_begin_dt'] ?? null) ?></td>
              <td class="apd-col-date"><?= $fmtDate($r['due_back_dt'] ?? null) ?></td>
              <td class="apd-nowrap">
                <?php if ($dl !== null && $dl <= 3 && $dl >= 0): ?>
                  <span class="staff-status-badge" style="background:#fff7ed;color:#b45309;border:1px solid #fed7aa"><?= h($badgeTxt) ?></span>
                <?php elseif ($dl !== null && $dl < 0): ?>
                  <span class="staff-status-badge staff-status-badge--off"><?= h($badgeTxt) ?></span>
                <?php elseif ($dl !== null): ?>
                  <span class="staff-status-badge staff-status-badge--on"><?= h($badgeTxt) ?></span>
                <?php else: ?>
                  <span style="color:#9ca3af">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- =====================================================
     TAB: STORICO
     ===================================================== -->
<?php elseif ($tab === 'history'): ?>
<div class="page-section" style="margin-top:0;border-top-left-radius:0;border-top-right-radius:0">
  <h2 class="apd-h2" style="margin-bottom:1rem">Storico prestiti</h2>

  <?php if ($historyErr): ?>
    <p style="color:#6b7280"><?= h($historyErr) ?></p>
  <?php elseif (!$history): ?>
    <div style="padding:1.25rem;border:1px dashed #e5e7eb;border-radius:8px;background:#fafafa;color:#6b7280;text-align:center">
      Nessun prestito in storico.
    </div>
  <?php else: ?>
    <div class="apd-table-wrap">
      <table class="apd-table">
        <thead>
          <tr>
            <th>Titolo</th>
            <th class="apd-nowrap">Copia</th>
            <th class="apd-nowrap">Data prestito</th>
            <th class="apd-nowrap">Scadenza</th>
            <th class="apd-nowrap">Rinnovi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $r): ?>
            <tr>
              <td>
                <div class="apd-titlecell"><?= h((string)$r['title']) ?></div>
                <?php if (!empty($r['author'])): ?>
                  <div class="apd-author"><?= h((string)$r['author']) ?></div>
                <?php endif; ?>
              </td>
              <td class="apd-nowrap apd-col-copy">#<?= (int)$r['copyid'] ?></td>
              <td class="apd-col-date"><?= $fmtDate($r['status_begin_dt'] ?? null) ?></td>
              <td class="apd-col-date"><?= $fmtDate($r['due_back_dt'] ?? null) ?></td>
              <td class="apd-col-num"><?= (int)($r['renewal_count'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- =====================================================
     TAB: PRENOTAZIONI
     ===================================================== -->
<?php elseif ($tab === 'holds'): ?>
<div class="page-section" style="margin-top:0;border-top-left-radius:0;border-top-right-radius:0">
  <h2 class="apd-h2" style="margin-bottom:1rem">Prenotazioni</h2>

  <?php if ($holdsErr): ?>
    <p style="color:#6b7280"><?= h($holdsErr) ?></p>
  <?php elseif (!$holds): ?>
    <div style="padding:1.25rem;border:1px dashed #e5e7eb;border-radius:8px;background:#fafafa;color:#6b7280;text-align:center">
      Nessuna prenotazione attiva.
    </div>
  <?php else: ?>
    <div class="apd-table-wrap">
      <table class="apd-table">
        <thead>
          <tr>
            <th>Titolo</th>
            <th class="apd-nowrap">Data</th>
            <th class="apd-nowrap">Disponibilità</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($holds as $r):
            $in    = (int)($r['copies_in']    ?? 0);
            $total = (int)($r['copies_total'] ?? 0);
          ?>
            <tr>
              <td>
                <div class="apd-titlecell"><?= h((string)$r['title']) ?></div>
                <?php if (!empty($r['author'])): ?>
                  <div class="apd-author"><?= h((string)$r['author']) ?></div>
                <?php endif; ?>
              </td>
              <td class="apd-col-date"><?= $fmtDate($r['hold_begin_dt'] ?? null) ?></td>
              <td class="apd-nowrap">
                <?php if ($in > 0): ?>
                  <span class="staff-status-badge staff-status-badge--on"><?= $in ?>/<?= $total ?> disponibili</span>
                <?php else: ?>
                  <span class="staff-status-badge staff-status-badge--off">Nessuna copia libera</span>
                <?php endif; ?>
              </td>
              <td class="apd-nowrap">
                <form method="post" style="margin:0">
                  <?php if ($hasCsrf): ?>
                    <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                  <?php endif; ?>
                  <input type="hidden" name="action" value="cancel_hold">
                  <input type="hidden" name="holdid" value="<?= (int)$r['holdid'] ?>">
                  <button type="submit" class="btn-secondary" style="font-size:0.82rem;padding:0.3rem 0.75rem">
                    Annulla
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p style="margin-top:0.75rem;font-size:0.82rem;color:#9ca3af">
      L'assegnazione della copia e l'avviso di disponibilità vengono gestiti dallo staff.
    </p>
  <?php endif; ?>
</div>

<!-- =====================================================
     TAB: DATI ANAGRAFICI
     ===================================================== -->
<?php elseif ($tab === 'profile'): ?>
<div class="page-section" style="margin-top:0;border-top-left-radius:0;border-top-right-radius:0">
  <h2 class="apd-h2" style="margin-bottom:0.25rem">Dati anagrafici</h2>
  <p class="apd-subhead" style="margin-bottom:1.25rem">Puoi modificare tutti i campi tranne tessera e barcode.</p>

  <?php if (!$memberRow): ?>
    <p style="color:#6b7280">Impossibile caricare i dati anagrafici.</p>
  <?php else: ?>
    <form method="post" novalidate>
      <?php if ($hasCsrf): ?>
        <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
      <?php endif; ?>
      <input type="hidden" name="action" value="update_profile">

      <!-- Sezione: dati principali -->
      <fieldset style="border:none;padding:0;margin:0 0 1.25rem 0">
        <legend style="font-size:0.78rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.75rem">
          Dati principali
        </legend>
        <div class="apd-form-grid">
          <div class="apd-field">
            <label class="apd-label" for="pf_first_name">Nome <span style="color:var(--color-primary)">*</span></label>
            <input type="text" id="pf_first_name" name="first_name"
                   value="<?= h((string)($memberRow['first_name'] ?? '')) ?>"
                   required>
          </div>
          <div class="apd-field">
            <label class="apd-label" for="pf_last_name">Cognome <span style="color:var(--color-primary)">*</span></label>
            <input type="text" id="pf_last_name" name="last_name"
                   value="<?= h((string)($memberRow['last_name'] ?? '')) ?>"
                   required>
          </div>
          <div class="apd-field">
            <label class="apd-label" for="pf_barcode">N° tessera (non modificabile)</label>
            <input type="text" id="pf_barcode"
                   value="<?= h((string)($memberRow['barcode_nmbr'] ?? '')) ?>"
                   disabled>
          </div>
          <div class="apd-field">
            <label class="apd-label" for="pf_cf">Codice fiscale</label>
            <input type="text" id="pf_cf" name="codice_fiscale"
                   maxlength="16" style="text-transform:uppercase"
                   value="<?= h((string)($memberRow['codice_fiscale'] ?? '')) ?>">
          </div>
          <div class="apd-field apd-col-span-2">
            <label class="apd-label" for="pf_email">Email</label>
            <input type="email" id="pf_email" name="email"
                   value="<?= h((string)($memberRow['email'] ?? '')) ?>">
          </div>
        </div>
      </fieldset>

      <!-- Sezione: indirizzo -->
      <fieldset style="border:none;padding:0;margin:0 0 1.25rem 0">
        <legend style="font-size:0.78rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.75rem">
          Indirizzo
        </legend>
        <div class="apd-form-grid">
          <div class="apd-field" style="grid-column:1/-1">
            <label class="apd-label" for="pf_indirizzo">Via / Piazza</label>
            <input type="text" id="pf_indirizzo" name="indirizzo"
                   value="<?= h((string)($memberRow['indirizzo'] ?? '')) ?>">
          </div>
          <div class="apd-field">
            <label class="apd-label" for="pf_civico">Civico</label>
            <input type="text" id="pf_civico" name="civico" maxlength="10"
                   value="<?= h((string)($memberRow['civico'] ?? '')) ?>">
          </div>
          <div class="apd-field">
            <label class="apd-label" for="pf_cap">CAP</label>
            <input type="text" id="pf_cap" name="cap" maxlength="5"
                   value="<?= h((string)($memberRow['cap'] ?? '')) ?>">
          </div>
          <div class="apd-field">
            <label class="apd-label" for="pf_citta">Città</label>
            <input type="text" id="pf_citta" name="citta"
                   value="<?= h((string)($memberRow['citta'] ?? '')) ?>">
          </div>
          <div class="apd-field">
            <label class="apd-label" for="pf_provincia">Provincia (sigla)</label>
            <input type="text" id="pf_provincia" name="provincia" maxlength="2"
                   style="text-transform:uppercase"
                   value="<?= h((string)($memberRow['provincia'] ?? '')) ?>">
          </div>
        </div>
      </fieldset>

      <!-- Sezione: contatti -->
      <fieldset style="border:none;padding:0;margin:0 0 1.25rem 0">
        <legend style="font-size:0.78rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.75rem">
          Contatti telefonici
        </legend>
        <div class="apd-form-grid">
          <div class="apd-field">
            <label class="apd-label" for="pf_cel">Cellulare</label>
            <input type="tel" id="pf_cel" name="cel"
                   value="<?= h((string)($memberRow['cel'] ?? '')) ?>">
          </div>
          <div class="apd-field">
            <label class="apd-label" for="pf_home">Telefono casa</label>
            <input type="tel" id="pf_home" name="home_phone"
                   value="<?= h((string)($memberRow['home_phone'] ?? '')) ?>">
          </div>
          <div class="apd-field">
            <label class="apd-label" for="pf_work">Telefono lavoro</label>
            <input type="tel" id="pf_work" name="work_phone"
                   value="<?= h((string)($memberRow['work_phone'] ?? '')) ?>">
          </div>
        </div>
      </fieldset>

      <div style="display:flex;align-items:center;gap:12px;padding-top:0.5rem;border-top:1px solid #f1f1f1">
        <button type="submit" class="btn-primary">Salva modifiche</button>
        <span style="font-size:0.82rem;color:#9ca3af">I campi con * sono obbligatori</span>
      </div>
    </form>
  <?php endif; ?>
</div>

<!-- =====================================================
     TAB: CAMBIO PASSWORD
     ===================================================== -->
<?php elseif ($tab === 'password'): ?>
<div class="page-section" style="margin-top:0;border-top-left-radius:0;border-top-right-radius:0">
  <h2 class="apd-h2" style="margin-bottom:0.25rem">Cambio password</h2>
  <p class="apd-subhead" style="margin-bottom:1.25rem">Per sicurezza ti chiediamo di inserire la password attuale.</p>

  <form method="post" novalidate style="max-width:400px">
    <?php if ($hasCsrf): ?>
      <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
    <?php endif; ?>
    <input type="hidden" name="action" value="change_password">

    <div class="apd-field" style="margin-bottom:0.9rem">
      <label class="apd-label" for="pw_current">Password attuale</label>
      <input type="password" id="pw_current" name="pw_current" autocomplete="current-password" required>
    </div>
    <div class="apd-field" style="margin-bottom:0.9rem">
      <label class="apd-label" for="pw_new">Nuova password</label>
      <input type="password" id="pw_new" name="pw_new" autocomplete="new-password" required>
    </div>
    <div class="apd-field" style="margin-bottom:1rem">
      <label class="apd-label" for="pw_confirm">Conferma nuova password</label>
      <input type="password" id="pw_confirm" name="pw_confirm" autocomplete="new-password" required>
    </div>

    <div style="
      padding:0.75rem 0.9rem;margin-bottom:1rem;
      background:#fff7ed;border-left:3px solid #f59e0b;
      border-radius:0 6px 6px 0;font-size:0.83rem;color:#78350f;line-height:1.5
    ">
      La password deve contenere almeno 8 caratteri, una lettera maiuscola, un numero e un carattere speciale.
    </div>

    <button type="submit" class="btn-primary">Aggiorna password</button>
  </form>
</div>
<?php endif; ?>