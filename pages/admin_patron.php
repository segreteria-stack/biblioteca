<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    $baseUrl  = base_url();
    $redirect = 'staff';
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=' . urlencode($redirect));
    exit;
}

require_once __DIR__ . '/../lib/Validate.php';

$title   = 'Scheda utente';
$baseUrl = base_url();

global $db;
if (!($db instanceof PDO)) {
    throw new RuntimeException('Connessione DB non disponibile.');
}

$T_MEMBER          = 'member';
$T_MEMBER_FIELDS   = 'member_fields';
$T_MEMBER_FIELDSDM = 'member_fields_dm';
$T_BIBLIO          = 'biblio';
$T_COPY            = 'biblio_copy';
$T_HOLD            = 'biblio_hold';
$T_STATUS_DM       = 'biblio_status_dm';
$T_STATUS_HIST     = 'biblio_status_hist';
$LOAN_STATUS       = ['ln', 'out'];

$staffId = (int)($_SESSION['staff_user_id'] ?? 0);

$go = static function (string $url): void {
    header('Location: ' . $url);
    exit;
};

$csrfToken = static function (): string {
    if (empty($_SESSION['_csrf_staff'])) {
        $_SESSION['_csrf_staff'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['_csrf_staff'];
};
$csrfCheck = static function (?string $token) use ($csrfToken): bool {
    return hash_equals($csrfToken(), (string)$token);
};

$mbrid = (int)($_GET['mbrid'] ?? 0);
$tab   = (string)($_GET['tab'] ?? 'profile');

$allowedTabs = ['profile', 'loans', 'holds', 'history'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'profile';
}

$err = '';
$ok  = '';

if ($mbrid <= 0) {
    $err = 'ID utente non valido.';
}

$now = date('Y-m-d H:i:s');

$normalizeStatus = static function (string $code, string $desc): string {
    $mapByCode = ['crt' => 'Da reintegrare', 'in' => 'Disponibile', 'out' => 'Non disponibile', 'ln' => 'In prestito'];
    if ($code !== '' && isset($mapByCode[$code])) return $mapByCode[$code];
    $descNorm = mb_strtolower(trim($desc));
    if ($descNorm === 'para reponer') return 'Da reintegrare';
    return trim($desc) !== '' ? trim($desc) : ($code !== '' ? $code : '—');
};

if ($err === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$csrfCheck($_POST['csrf'] ?? null)) {
        $err = 'Token CSRF non valido.';
    } else {
        try {
            if (isset($_POST['save_profile'])) {
                $last           = V::str($_POST['last_name'] ?? '', 50);
                $first          = V::str($_POST['first_name'] ?? '', 50);
                $barcode        = V::str($_POST['barcode_nmbr'] ?? '', 20);
                $email          = V::str($_POST['email'] ?? '', 128);
                $home_phone     = V::str($_POST['home_phone'] ?? '', 15);
                $work_phone     = V::str($_POST['work_phone'] ?? '', 15);
                $cel            = V::str($_POST['cel'] ?? '', 15);
                $born_dt        = trim((string)($_POST['born_dt'] ?? ''));
                $codiceFiscale  = strtoupper(trim((string)($_POST['codice_fiscale'] ?? '')));
                $indirizzo      = trim((string)($_POST['indirizzo'] ?? ''));
                $civico         = trim((string)($_POST['civico'] ?? ''));
                $cap            = trim((string)($_POST['cap'] ?? ''));
                $citta          = trim((string)($_POST['citta'] ?? ''));
                $provincia      = strtoupper(trim((string)($_POST['provincia'] ?? '')));
                $other          = trim((string)($_POST['other'] ?? ''));
                $classification = (int)($_POST['classification'] ?? 1);

                if ($last === '' || $first === '' || $barcode === '') {
                    throw new RuntimeException('Cognome, Nome e Barcode sono obbligatori.');
                }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Email non valida.');
                }
                if ($codiceFiscale !== '' && !preg_match('/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/', $codiceFiscale)) {
                    throw new RuntimeException('Codice fiscale non valido.');
                }
                if ($born_dt === '') $born_dt = '0000-00-00';

                $db->prepare("UPDATE {$T_MEMBER}
                    SET barcode_nmbr=?, last_name=?, first_name=?, email=?,
                        home_phone=?, work_phone=?, cel=?,
                        born_dt=?, other=?, classification=?,
                        codice_fiscale=?, indirizzo=?, civico=?, cap=?, citta=?, provincia=?,
                        last_change_dt=?, last_change_userid=?
                    WHERE mbrid=?")->execute([
                    $barcode, $last, $first, $email,
                    $home_phone, $work_phone, $cel,
                    $born_dt, $other, $classification,
                    $codiceFiscale, $indirizzo, $civico, $cap, $citta, $provincia,
                    $now, $staffId, $mbrid
                ]);

                $ok = 'Scheda aggiornata.';
            }
            elseif (isset($_POST['toggle_active'])) {
                $cur = (string)($_POST['cur_active'] ?? 'Y');
                $new = ($cur === 'Y') ? 'N' : 'Y';
                $db->prepare("UPDATE {$T_MEMBER} SET is_active=?, last_change_dt=?, last_change_userid=? WHERE mbrid=?")
                   ->execute([$new, $now, $staffId, $mbrid]);
                $ok = ($new === 'Y') ? 'Utente attivato.' : 'Utente disattivato.';
            }
            elseif (isset($_POST['add_field'])) {
                $code = trim((string)($_POST['code'] ?? ''));
                if ($code === '' || strlen($code) > 16) throw new RuntimeException('Codice non valido (max 16 caratteri).');
                $data = trim((string)($_POST['data'] ?? ''));
                $db->prepare("INSERT INTO {$T_MEMBER_FIELDS} (mbrid, code, data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data=VALUES(data)")
                   ->execute([$mbrid, $code, $data]);
                $ok = 'Campo aggiornato.';
            }
            elseif (isset($_POST['del_field'])) {
                $code = trim((string)($_POST['code'] ?? ''));
                if ($code === '') throw new RuntimeException('Codice mancante.');
                $db->prepare("DELETE FROM {$T_MEMBER_FIELDS} WHERE mbrid=? AND code=? LIMIT 1")->execute([$mbrid, $code]);
                $ok = 'Campo rimosso.';
            }

            $go(rtrim($baseUrl, '/') . '/index.php?page=admin_patron&mbrid=' . $mbrid . '&tab=' . rawurlencode($tab));

        } catch (Throwable $e) {
            $err = 'Errore: ' . h($e->getMessage());
        }
    }
}

$member  = null;
$fields  = [];
$dmCodes = [];
$loans   = [];
$holds   = [];
$history = [];
$cntLoans = $cntHolds = $cntHist = 0;

if ($err === '') {
    try {
        $st = $db->prepare("SELECT * FROM {$T_MEMBER} WHERE mbrid=? LIMIT 1");
        $st->execute([$mbrid]);
        $member = $st->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            $err = 'Utente non trovato.';
        } else {
            $st = $db->prepare("SELECT code, data FROM {$T_MEMBER_FIELDS} WHERE mbrid=? ORDER BY code");
            $st->execute([$mbrid]);
            $fields = $st->fetchAll(PDO::FETCH_ASSOC);

            $st = $db->query("SELECT code, description FROM {$T_MEMBER_FIELDSDM} ORDER BY description");
            $dmCodes = $st->fetchAll(PDO::FETCH_ASSOC);

            $ph = implode(',', array_fill(0, count($LOAN_STATUS), '?'));
            $st = $db->prepare("SELECT COUNT(*) FROM {$T_COPY} WHERE mbrid=? AND status_cd IN ({$ph})");
            $st->execute(array_merge([$mbrid], $LOAN_STATUS));
            $cntLoans = (int)$st->fetchColumn();

            $st = $db->prepare("SELECT COUNT(*) FROM {$T_HOLD} WHERE mbrid=?");
            $st->execute([$mbrid]);
            $cntHolds = (int)$st->fetchColumn();

            $st = $db->prepare("SELECT COUNT(*) FROM {$T_STATUS_HIST} WHERE mbrid=?");
            $st->execute([$mbrid]);
            $cntHist = (int)$st->fetchColumn();

            if ($tab === 'loans') {
                $st = $db->prepare("SELECT c.bibid, c.copyid, c.barcode_nmbr AS copy_barcode, c.due_back_dt, c.renewal_count, c.status_cd, b.title, b.author FROM {$T_COPY} c LEFT JOIN {$T_BIBLIO} b ON b.bibid=c.bibid WHERE c.mbrid=? AND c.status_cd IN ({$ph}) ORDER BY c.due_back_dt ASC");
                $st->execute(array_merge([$mbrid], $LOAN_STATUS));
                $loans = $st->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($tab === 'holds') {
                $st = $db->prepare("SELECT h.holdid, h.hold_begin_dt, h.bibid, h.copyid, c.barcode_nmbr AS copy_barcode, b.title, b.author FROM {$T_HOLD} h LEFT JOIN {$T_COPY} c ON c.bibid=h.bibid AND c.copyid=h.copyid LEFT JOIN {$T_BIBLIO} b ON b.bibid=h.bibid WHERE h.mbrid=? ORDER BY h.hold_begin_dt DESC");
                $st->execute([$mbrid]);
                $holds = $st->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($tab === 'history') {
                $st = $db->prepare("SELECT h.status_begin_dt, h.bibid, h.copyid, c.barcode_nmbr AS copy_barcode, h.status_cd, d.description AS status_desc, h.due_back_dt, h.renewal_count, b.title, b.author FROM {$T_STATUS_HIST} h LEFT JOIN {$T_COPY} c ON c.bibid=h.bibid AND c.copyid=h.copyid LEFT JOIN {$T_STATUS_DM} d ON d.code=h.status_cd LEFT JOIN {$T_BIBLIO} b ON b.bibid=h.bibid WHERE h.mbrid=? ORDER BY h.status_begin_dt DESC LIMIT 200");
                $st->execute([$mbrid]);
                $history = $st->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Throwable $e) {
        $err = 'Errore: ' . h($e->getMessage());
    }
}

$backUrl = rtrim($baseUrl, '/') . '/index.php?page=admin_patrons';
$tabUrl  = static function (string $t) use ($baseUrl, $mbrid): string {
    return rtrim($baseUrl, '/') . '/index.php?page=admin_patron&mbrid=' . $mbrid . '&tab=' . rawurlencode($t);
};
$fmtDate = static function (string $dt): string {
    $dt = trim($dt);
    if ($dt === '') return '—';
    return (strlen($dt) >= 10) ? substr($dt, 0, 10) : $dt;
};
$copyLabel = static function (int $copyid, string $barcode): string {
    $barcode = trim($barcode);
    if ($copyid <= 0 && $barcode === '') return '—';
    if ($barcode !== '' && $copyid > 0) return 'CopyID ' . $copyid . ' — ' . $barcode;
    if ($copyid > 0) return 'CopyID ' . $copyid;
    return $barcode;
};
?>
<section class="card apd-wrap" style="margin-top:20px">

  <div class="apd-head">
    <div class="apd-head-left">
      <a class="apd-back" href="<?= h($backUrl) ?>">← Lista utenti</a>
      <h1 class="apd-title">
        Scheda utente
        <?php if ($member): ?>
          <span class="apd-title-name">— <?= h(trim(($member['last_name'] ?? '') . ' ' . ($member['first_name'] ?? ''))) ?></span>
        <?php endif; ?>
      </h1>
    </div>
    <?php if ($member): ?>
      <?php $isActive = (($member['is_active'] ?? 'Y') === 'Y'); ?>
      <div class="apd-badges">
        <span class="apd-badge">
          <span class="apd-badge-label">Barcode</span>
          <span class="apd-badge-value"><?= h((string)($member['barcode_nmbr'] ?? '')) ?></span>
        </span>
        <span class="apd-badge apd-badge-status <?= $isActive ? 'is-ok' : 'is-bad' ?>">
          <span class="apd-dot"></span>
          <span class="apd-badge-value"><?= $isActive ? 'Attivo' : 'Disattivo' ?></span>
        </span>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($err): ?>
    <p class="apd-msg apd-msg-err"><?= $err ?></p>
    </section>
    <?php return; ?>
  <?php endif; ?>

  <?php if ($ok): ?>
    <p class="apd-msg apd-msg-ok"><?= h($ok) ?></p>
  <?php endif; ?>

  <nav class="apd-tabs" aria-label="Navigazione scheda utente">
    <?php
    $tabs = [
        'profile' => ['label' => 'Profilo',       'count' => null],
        'loans'   => ['label' => 'Prestiti',       'count' => $cntLoans],
        'holds'   => ['label' => 'Prenotazioni',   'count' => $cntHolds],
        'history' => ['label' => 'Storico',        'count' => $cntHist],
    ];
    foreach ($tabs as $k => $t):
        $active = ($tab === $k);
    ?>
      <a class="apd-tab <?= $active ? 'is-active' : '' ?>" href="<?= h($tabUrl($k)) ?>">
        <span class="apd-tab-label"><?= h($t['label']) ?></span>
        <?php if ($t['count'] !== null): ?>
          <span class="apd-tab-count"><?= (int)$t['count'] ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="apd-body">
    <?php if ($tab === 'profile'): ?>

      <div class="apd-grid">
        <section class="card apd-panel">
          <div class="apd-panel-head">
            <h2 class="apd-h2">Anagrafica</h2>
          </div>

          <form method="post" class="apd-form-grid">
            <input type="hidden" name="csrf" value="<?= h($csrfToken()) ?>">

            <label class="apd-field">
              <span class="apd-label">Cognome *</span>
              <input class="input" name="last_name" required value="<?= h((string)($member['last_name'] ?? '')) ?>">
            </label>
            <label class="apd-field">
              <span class="apd-label">Nome *</span>
              <input class="input" name="first_name" required value="<?= h((string)($member['first_name'] ?? '')) ?>">
            </label>
            <label class="apd-field">
              <span class="apd-label">Barcode *</span>
              <input class="input" name="barcode_nmbr" required value="<?= h((string)($member['barcode_nmbr'] ?? '')) ?>">
            </label>
            <label class="apd-field">
              <span class="apd-label">Codice fiscale</span>
              <input class="input" name="codice_fiscale" maxlength="16" style="text-transform:uppercase"
                     value="<?= h((string)($member['codice_fiscale'] ?? '')) ?>">
            </label>
            <label class="apd-field">
              <span class="apd-label">Email</span>
              <input class="input" name="email" value="<?= h((string)($member['email'] ?? '')) ?>">
            </label>
            <label class="apd-field">
              <span class="apd-label">Data nascita</span>
              <input class="input" type="date" name="born_dt"
                     value="<?= h((string)($member['born_dt'] ?? '')) ?>">
            </label>
            <label class="apd-field">
              <span class="apd-label">Tel. casa</span>
              <input class="input" name="home_phone" value="<?= h((string)($member['home_phone'] ?? '')) ?>">
            </label>
            <label class="apd-field">
              <span class="apd-label">Tel. lavoro</span>
              <input class="input" name="work_phone" value="<?= h((string)($member['work_phone'] ?? '')) ?>">
            </label>
            <label class="apd-field">
              <span class="apd-label">Cellulare</span>
              <input class="input" name="cel" value="<?= h((string)($member['cel'] ?? '')) ?>">
            </label>
            <label class="apd-field">
              <span class="apd-label">Classificazione</span>
              <input class="input" name="classification" value="<?= (int)($member['classification'] ?? 1) ?>">
            </label>

            <!-- Indirizzo strutturato -->
            <label class="apd-field" style="grid-column:1/-1">
              <span class="apd-label">Via/Piazza</span>
              <div style="display:grid;grid-template-columns:3fr 1fr;gap:8px">
                <input class="input" name="indirizzo" value="<?= h((string)($member['indirizzo'] ?? '')) ?>">
                <input class="input" name="civico" placeholder="Civico" value="<?= h((string)($member['civico'] ?? '')) ?>">
              </div>
            </label>
            <label class="apd-field" style="grid-column:1/-1">
              <span class="apd-label">CAP / Città / Provincia</span>
              <div style="display:grid;grid-template-columns:1fr 2fr 1fr;gap:8px">
                <input class="input" name="cap" maxlength="5" placeholder="CAP" value="<?= h((string)($member['cap'] ?? '')) ?>">
                <input class="input" name="citta" placeholder="Città" value="<?= h((string)($member['citta'] ?? '')) ?>">
                <input class="input" name="provincia" maxlength="2" placeholder="Prov." style="text-transform:uppercase" value="<?= h((string)($member['provincia'] ?? '')) ?>">
              </div>
            </label>

            <label class="apd-field apd-col-span-2">
              <span class="apd-label">Note</span>
              <textarea class="input" name="other" rows="3"><?= h((string)($member['other'] ?? '')) ?></textarea>
            </label>

            <div class="apd-actions apd-col-span-2">
              <button class="button" type="submit" name="save_profile" value="1">Salva scheda</button>
            </div>
          </form>

          <div class="apd-footnote">
            Ultima attività: <strong><?= h($fmtDate((string)($member['last_activity_dt'] ?? ''))) ?></strong>
          </div>
        </section>

        <section class="card apd-panel">
          <div class="apd-panel-head">
            <h2 class="apd-h2">Stato e profili</h2>
          </div>

          <form method="post" class="apd-inline">
            <input type="hidden" name="csrf" value="<?= h($csrfToken()) ?>">
            <input type="hidden" name="cur_active" value="<?= h((string)($member['is_active'] ?? 'Y')) ?>">
            <button class="button secondary" type="submit" name="toggle_active" value="1">
              <?= (($member['is_active'] ?? 'Y') === 'Y') ? 'Disattiva utente' : 'Attiva utente' ?>
            </button>
          </form>

          <h3 class="apd-h3">Campi / tag</h3>

          <?php if ($fields): ?>
            <ul class="apd-tags">
              <?php foreach ($fields as $f): ?>
                <li class="apd-tag">
                  <span class="apd-tag-code"><?= h((string)$f['code']) ?></span>
                  <?php if (!empty($f['data'])): ?>
                    <span class="apd-tag-data"><?= h((string)$f['data']) ?></span>
                  <?php endif; ?>
                  <form method="post" class="apd-tag-actions">
                    <input type="hidden" name="csrf" value="<?= h($csrfToken()) ?>">
                    <input type="hidden" name="code" value="<?= h((string)$f['code']) ?>">
                    <button class="button secondary" type="submit" name="del_field" value="1">Rimuovi</button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="apd-muted">Nessun campo associato.</p>
          <?php endif; ?>

          <form method="post" class="apd-addtag">
            <input type="hidden" name="csrf" value="<?= h($csrfToken()) ?>">
            <select class="input" name="code" style="min-width:180px">
              <?php foreach ($dmCodes as $d): ?>
                <option value="<?= h((string)$d['code']) ?>"><?= h((string)$d['description']) ?></option>
              <?php endforeach; ?>
            </select>
            <input class="input" name="data" placeholder="Valore (opzionale)" style="flex:1;min-width:160px">
            <button class="button" type="submit" name="add_field" value="1">Aggiungi/aggiorna</button>
          </form>
        </section>
      </div>

    <?php elseif ($tab === 'loans'): ?>
      <section class="card apd-panel">
        <div class="apd-panel-head">
          <h2 class="apd-h2">Prestiti in corso</h2>
          <div class="apd-subhead">Status <strong><?= h(implode(', ', $LOAN_STATUS)) ?></strong></div>
        </div>
        <?php if (!$loans): ?>
          <p class="apd-muted">Nessun prestito in corso.</p>
        <?php else: ?>
          <div class="apd-table-wrap">
            <table class="apd-table">
              <thead>
                <tr>
                  <th>Titolo</th>
                  <th class="apd-col-copy">Copia</th>
                  <th class="apd-col-date">Scadenza</th>
                  <th class="apd-col-num">Rinnovi</th>
                  <th class="apd-col-num">Stato</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($loans as $r): ?>
                  <?php
                    $bibid = (int)($r['bibid'] ?? 0);
                    $itemUrl = rtrim($baseUrl, '/') . '/index.php?page=item&bibid=' . $bibid;
                  ?>
                  <tr>
                    <td>
                      <div class="apd-titlecell">
                        <?php if ($bibid > 0): ?>
                          <a class="apd-link" href="<?= h($itemUrl) ?>"><?= h((string)($r['title'] ?? 'BIBID ' . $bibid)) ?></a>
                        <?php else: ?>
                          <?= h((string)($r['title'] ?? '—')) ?>
                        <?php endif; ?>
                        <?php if (!empty($r['author'])): ?>
                          <div class="apd-author"><?= h((string)$r['author']) ?></div>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td class="apd-mono"><?= h($copyLabel((int)($r['copyid'] ?? 0), (string)($r['copy_barcode'] ?? ''))) ?></td>
                    <td class="apd-nowrap"><?= h($fmtDate((string)($r['due_back_dt'] ?? ''))) ?></td>
                    <td class="apd-col-num"><?= (int)($r['renewal_count'] ?? 0) ?></td>
                    <td class="apd-col-num"><?= h($normalizeStatus((string)($r['status_cd'] ?? ''), '')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

    <?php elseif ($tab === 'holds'): ?>
      <section class="card apd-panel">
        <div class="apd-panel-head">
          <h2 class="apd-h2">Prenotazioni</h2>
        </div>
        <?php if (!$holds): ?>
          <p class="apd-muted">Nessuna prenotazione.</p>
        <?php else: ?>
          <div class="apd-table-wrap">
            <table class="apd-table">
              <thead>
                <tr>
                  <th>Titolo</th>
                  <th class="apd-col-copy">Copia</th>
                  <th class="apd-col-date">Data prenotazione</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($holds as $r): ?>
                  <?php
                    $bibid = (int)($r['bibid'] ?? 0);
                    $itemUrl = rtrim($baseUrl, '/') . '/index.php?page=item&bibid=' . $bibid;
                  ?>
                  <tr>
                    <td>
                      <div class="apd-titlecell">
                        <?php if ($bibid > 0): ?>
                          <a class="apd-link" href="<?= h($itemUrl) ?>"><?= h((string)($r['title'] ?? 'BIBID ' . $bibid)) ?></a>
                        <?php else: ?>
                          <?= h((string)($r['title'] ?? '—')) ?>
                        <?php endif; ?>
                        <?php if (!empty($r['author'])): ?>
                          <div class="apd-author"><?= h((string)$r['author']) ?></div>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td class="apd-mono"><?= h($copyLabel((int)($r['copyid'] ?? 0), (string)($r['copy_barcode'] ?? ''))) ?></td>
                    <td class="apd-nowrap"><?= h($fmtDate((string)($r['hold_begin_dt'] ?? ''))) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

    <?php elseif ($tab === 'history'): ?>
      <section class="card apd-panel">
        <div class="apd-panel-head">
          <h2 class="apd-h2">Storico prestiti / stati</h2>
          <div class="apd-subhead">Ultimi 200 eventi</div>
        </div>
        <?php if (!$history): ?>
          <p class="apd-muted">Nessuno storico disponibile.</p>
        <?php else: ?>
          <div class="apd-table-wrap">
            <table class="apd-table">
              <thead>
                <tr>
                  <th class="apd-col-date">Data</th>
                  <th>Titolo</th>
                  <th class="apd-col-copy">Copia</th>
                  <th>Stato</th>
                  <th class="apd-col-date">Scadenza</th>
                  <th class="apd-col-num">Rinnovi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($history as $r): ?>
                  <?php
                    $bibid = (int)($r['bibid'] ?? 0);
                    $itemUrl = rtrim($baseUrl, '/') . '/index.php?page=item&bibid=' . $bibid;
                  ?>
                  <tr>
                    <td class="apd-nowrap"><?= h($fmtDate((string)($r['status_begin_dt'] ?? ''))) ?></td>
                    <td>
                      <div class="apd-titlecell">
                        <?php if ($bibid > 0): ?>
                          <a class="apd-link" href="<?= h($itemUrl) ?>"><?= h((string)($r['title'] ?? 'BIBID ' . $bibid)) ?></a>
                        <?php else: ?>
                          <?= h((string)($r['title'] ?? '—')) ?>
                        <?php endif; ?>
                        <?php if (!empty($r['author'])): ?>
                          <div class="apd-author"><?= h((string)$r['author']) ?></div>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td class="apd-mono"><?= h($copyLabel((int)($r['copyid'] ?? 0), (string)($r['copy_barcode'] ?? ''))) ?></td>
                    <td><span class="apd-status"><?= h($normalizeStatus((string)($r['status_cd'] ?? ''), (string)($r['status_desc'] ?? ''))) ?></span></td>
                    <td class="apd-nowrap"><?= h($fmtDate((string)($r['due_back_dt'] ?? ''))) ?></td>
                    <td class="apd-col-num"><?= (int)($r['renewal_count'] ?? 0) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

    <?php endif; ?>
  </div>
</section>
