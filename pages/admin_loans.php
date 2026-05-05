<?php
declare(strict_types=1);

/**
 * Admin – Prestiti (circolazione)
 * URL: index.php?page=admin_loans
 *
 * Operazioni:
 * - Prestito (checkout)
 * - Restituzione (checkin)
 * - Rinnovo (renew)
 * - Visualizzazione prestiti aperti + prenotazioni (biblio_hold)
 *
 * NOVITÀ:
 * - Colonna Collocazione (call_nmbr1)
 * - Colonna Giorni di ritardo (calcolato da due_back_dt vs oggi)
 * - Evidenziazione rossa per prestiti scaduti
 */

// -----------------------------------------------------------------------------
// Protezione: accesso solo per staff autenticato
// -----------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    $baseUrl  = function_exists('base_url') ? base_url() : '';
    $redirect = 'staff';
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=' . urlencode($redirect));
    exit;
}

$title = 'Prestiti';

/** @var PDO $db */
global $db, $cfg;

if (!($db instanceof PDO)) {
    http_response_code(500);
    echo '<h1>Errore</h1><p>Connessione DB non disponibile.</p>';
    exit;
}

$baseUrl = (string)($cfg['app']['base_url'] ?? (function_exists('base_url') ? base_url() : ''));

// Tabelle (default)
$T_MEMBER      = 'member';
$T_BIBLIO      = 'biblio';
$T_COPY        = 'biblio_copy';
$T_HOLD        = 'biblio_hold';
$T_STATUS_DM   = 'biblio_status_dm';
$T_STATUS_HIST = 'biblio_status_hist';
$T_PRIVS       = 'checkout_privs';

$staffId = (int)($_SESSION['staff_user_id'] ?? 0);

// -----------------------------------------------------------------------------
// Costanti operative
// -----------------------------------------------------------------------------
$LOAN_DAYS_DEFAULT = 30;

// status: disponibile / prestito (compatibilità out + ln)
$STATUS_AVAILABLE = 'in';
$STATUS_LOAN_A    = 'out';
$STATUS_LOAN_B    = 'ln';

// -----------------------------------------------------------------------------
// Helper redirect
// -----------------------------------------------------------------------------
$go = static function (string $url): void {
    header('Location: ' . $url);
    exit;
};

// -----------------------------------------------------------------------------
// CSRF (staff)
// -----------------------------------------------------------------------------
$csrfToken = static function (): string {
    if (empty($_SESSION['_csrf_staff'])) {
        $_SESSION['_csrf_staff'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['_csrf_staff'];
};
$csrfCheck = static function (?string $token) use ($csrfToken): bool {
    $t = (string)$token;
    return hash_equals($csrfToken(), $t);
};

// -----------------------------------------------------------------------------
// Label stati (fallback)
// -----------------------------------------------------------------------------
$statusLabel = static function (string $code, string $desc = ''): string {
    $map = [
        'in'  => 'Disponibile',
        'out' => 'In prestito',
        'ln'  => 'In prestito',
        'crt' => 'Da reintegrare',
        'lst' => 'Smarrito',
    ];
    $code = strtolower(trim($code));
    if (isset($map[$code])) return $map[$code];

    $d = trim($desc);
    return $d !== '' ? $d : ($code !== '' ? $code : '—');
};

// -----------------------------------------------------------------------------
// NUOVO: Helper calcolo giorni di ritardo
// -----------------------------------------------------------------------------
$getDelayDays = static function (?string $dueBackDt): int {
    if (empty($dueBackDt) || $dueBackDt === '0000-00-00') {
        return 0;
    }
    try {
        $due = new DateTimeImmutable($dueBackDt);
        $now = new DateTimeImmutable('today');
        if ($due >= $now) {
            return 0;
        }
        return (int)$now->diff($due)->days;
    } catch (Throwable $e) {
        return 0;
    }
};

// -----------------------------------------------------------------------------
// NUOVO: Helper formatta data
// -----------------------------------------------------------------------------
$fmtDate = static function (?string $dt): string {
    if (empty($dt) || $dt === '0000-00-00') {
        return '—';
    }
    return date('d/m/Y', strtotime($dt));
};

// -----------------------------------------------------------------------------
// Lookup: member/copy da barcode
// -----------------------------------------------------------------------------
$findMemberByBarcode = static function (PDO $db, string $barcode) use ($T_MEMBER): ?array {
    $barcode = trim($barcode);
    if ($barcode === '') return null;
    $st = $db->prepare("SELECT mbrid, barcode_nmbr, last_name, first_name, email, is_active, classification
                        FROM {$T_MEMBER}
                        WHERE barcode_nmbr = ?
                        LIMIT 1");
    $st->execute([$barcode]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
};

$findCopyByBarcode = static function (PDO $db, string $copyBarcode) use ($T_COPY): ?array {
    $copyBarcode = trim($copyBarcode);
    if ($copyBarcode === '') return null;
    $st = $db->prepare("SELECT bibid, copyid, barcode_nmbr, status_cd, status_begin_dt, due_back_dt, mbrid, renewal_count
                        FROM {$T_COPY}
                        WHERE barcode_nmbr = ?
                        LIMIT 1");
    $st->execute([$copyBarcode]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
};

// -----------------------------------------------------------------------------
// Policy rinnovi: checkout_privs.renewal_limit (schema reale)
// -----------------------------------------------------------------------------
$getMaxRenewals = static function (PDO $db, int $classification, int $materialCd) use ($T_PRIVS): int {
    $classification = (int)$classification;
    $materialCd     = (int)$materialCd;

    $st = $db->prepare("SELECT renewal_limit FROM {$T_PRIVS} WHERE classification=? AND material_cd=? LIMIT 1");
    $st->execute([$classification, $materialCd]);
    $max = (int)($st->fetchColumn() ?? 0);
    if ($max > 0) return $max;

    $st = $db->prepare("SELECT renewal_limit FROM {$T_PRIVS} WHERE classification=0 AND material_cd=? LIMIT 1");
    $st->execute([$materialCd]);
    $max = (int)($st->fetchColumn() ?? 0);
    if ($max > 0) return $max;

    return 0;
};

// -----------------------------------------------------------------------------
// Storico stato (biblio_status_hist)
// -----------------------------------------------------------------------------
$insertHist = static function (
    PDO $db,
    int $bibid,
    int $copyid,
    string $statusCd,
    ?int $mbrid,
    ?string $dueBackDt,
    int $renewalCount,
    string $statusBeginDt
) use ($T_STATUS_HIST): void {
    $sql = "INSERT INTO {$T_STATUS_HIST}
            (bibid, copyid, status_cd, status_begin_dt, due_back_dt, mbrid, renewal_count)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    try {
        $db->prepare($sql)->execute([
            $bibid,
            $copyid,
            $statusCd,
            $statusBeginDt,
            $dueBackDt,
            $mbrid,
            $renewalCount,
        ]);
    } catch (PDOException $e) {
        if ((int)$e->getCode() === 23000 || str_contains((string)$e->getMessage(), 'Duplicate')) {
            $dt = (new DateTimeImmutable($statusBeginDt))->modify('+1 second')->format('Y-m-d H:i:s');
            $db->prepare($sql)->execute([
                $bibid, $copyid, $statusCd, $dt, $dueBackDt, $mbrid, $renewalCount
            ]);
            return;
        }
        throw $e;
    }
};

// -----------------------------------------------------------------------------
// Input UI
// -----------------------------------------------------------------------------
$tab = (string)($_GET['tab'] ?? 'open');
if (!in_array($tab, ['open', 'checkout', 'checkin', 'holds'], true)) {
    $tab = 'open';
}

$q = trim((string)($_GET['q'] ?? ''));

$memberQ = trim((string)($_GET['member_q'] ?? ''));
$copyQ   = trim((string)($_GET['copy_q'] ?? ''));

$err = '';
$ok  = '';

$nowDt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$today = new DateTimeImmutable('today');

// -----------------------------------------------------------------------------
// Selezioni persistenti
// -----------------------------------------------------------------------------
if (!isset($_SESSION['_al_member_barcode'])) $_SESSION['_al_member_barcode'] = '';
if (!isset($_SESSION['_al_copy_barcode']))   $_SESSION['_al_copy_barcode']   = '';

if (isset($_GET['set_member'])) {
    $_SESSION['_al_member_barcode'] = trim((string)$_GET['set_member']);
}
if (isset($_GET['set_copy'])) {
    $_SESSION['_al_copy_barcode'] = trim((string)$_GET['set_copy']);
}
if (isset($_GET['clear_pick'])) {
    $w = (string)$_GET['clear_pick'];
    if ($w === 'member') $_SESSION['_al_member_barcode'] = '';
    if ($w === 'copy')   $_SESSION['_al_copy_barcode']   = '';
    if ($w === 'all') {
        $_SESSION['_al_member_barcode'] = '';
        $_SESSION['_al_copy_barcode']   = '';
    }
}

$prefillMemberBarcode = trim((string)$_SESSION['_al_member_barcode']);
$prefillCopyBarcode   = trim((string)$_SESSION['_al_copy_barcode']);

// -----------------------------------------------------------------------------
// POST: Azioni operative
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$csrfCheck($_POST['csrf'] ?? null)) {
        $err = 'Token CSRF non valido.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            // CHECKOUT
            if ($action === 'checkout') {
                $memberBarcode = trim((string)($_POST['member_barcode'] ?? ''));
                $copyBarcode   = trim((string)($_POST['copy_barcode'] ?? ''));

                if ($memberBarcode === '' || $copyBarcode === '') {
                    throw new RuntimeException('Servono Barcode utente e Barcode copia.');
                }

                if (!$db->inTransaction()) $db->beginTransaction();

                $member = $findMemberByBarcode($db, $memberBarcode);
                if (!$member) throw new RuntimeException('Utente non trovato (barcode).');
                if (((string)($member['is_active'] ?? 'Y')) !== 'Y') {
                    throw new RuntimeException('Utente disattivo: impossibile registrare prestito.');
                }

                $copy = $findCopyByBarcode($db, $copyBarcode);
                if (!$copy) throw new RuntimeException('Copia non trovata (barcode).');

                $bibid  = (int)($copy['bibid'] ?? 0);
                $copyid = (int)($copy['copyid'] ?? 0);
                if ($bibid <= 0 || $copyid <= 0) throw new RuntimeException('Copia non valida (bibid/copyid).');

                $curStatus = strtolower(trim((string)($copy['status_cd'] ?? '')));
                if (in_array($curStatus, [strtolower($STATUS_LOAN_A), strtolower($STATUS_LOAN_B)], true)) {
                    throw new RuntimeException('Copia già in prestito.');
                }
                if ($curStatus !== strtolower($STATUS_AVAILABLE)) {
                    throw new RuntimeException('Copia non disponibile (stato: ' . ($curStatus !== '' ? $curStatus : '—') . ').');
                }

                $due = $today->modify("+{$LOAN_DAYS_DEFAULT} days")->format('Y-m-d');
                $mbrid = (int)($member['mbrid'] ?? 0);

                $sql = "UPDATE {$T_COPY}
                        SET status_cd=?,
                            status_begin_dt=?,
                            mbrid=?,
                            due_back_dt=?,
                            renewal_count=0
                        WHERE bibid=? AND copyid=? LIMIT 1";
                $db->prepare($sql)->execute([$STATUS_LOAN_A, $nowDt, $mbrid, $due, $bibid, $copyid]);

                $insertHist($db, $bibid, $copyid, $STATUS_LOAN_A, $mbrid, $due, 0, $nowDt);

                $db->prepare("UPDATE {$T_MEMBER} SET last_activity_dt=? WHERE mbrid=? LIMIT 1")
                   ->execute([$nowDt, $mbrid]);

                if ($db->inTransaction()) $db->commit();

                $_SESSION['_al_member_barcode'] = $memberBarcode;
                $_SESSION['_al_copy_barcode']   = $copyBarcode;

                $ok = 'Prestito registrato: copia ' . $copyBarcode . ' → utente ' . $memberBarcode . ' (scadenza ' . $due . ').';
                $go('index.php?page=admin_loans&tab=open');
            }

            // CHECKIN
            if ($action === 'checkin') {
                $copyBarcode = trim((string)($_POST['copy_barcode'] ?? ''));
                if ($copyBarcode === '') throw new RuntimeException('Serve il Barcode copia.');

                if (!$db->inTransaction()) $db->beginTransaction();

                $copy = $findCopyByBarcode($db, $copyBarcode);
                if (!$copy) throw new RuntimeException('Copia non trovata (barcode).');

                $bibid  = (int)($copy['bibid'] ?? 0);
                $copyid = (int)($copy['copyid'] ?? 0);

                $curStatus = strtolower(trim((string)($copy['status_cd'] ?? '')));
                $curMbrid  = (int)($copy['mbrid'] ?? 0);
                $curRenew  = (int)($copy['renewal_count'] ?? 0);

                if (!in_array($curStatus, [strtolower($STATUS_LOAN_A), strtolower($STATUS_LOAN_B)], true)) {
                    throw new RuntimeException('La copia non risulta in prestito (stato: ' . ($curStatus !== '' ? $curStatus : '—') . ').');
                }

                $sql = "UPDATE {$T_COPY}
                        SET status_cd=?,
                            status_begin_dt=?,
                            mbrid=NULL,
                            due_back_dt=NULL,
                            renewal_count=0
                        WHERE bibid=? AND copyid=? LIMIT 1";
                $db->prepare($sql)->execute([$STATUS_AVAILABLE, $nowDt, $bibid, $copyid]);

                $insertHist($db, $bibid, $copyid, $STATUS_AVAILABLE, $curMbrid > 0 ? $curMbrid : null, null, $curRenew, $nowDt);

                if ($db->inTransaction()) $db->commit();

                $ok = 'Restituzione registrata: copia ' . $copyBarcode . '.';
                $go('index.php?page=admin_loans&tab=open');
            }

            // RENEW
            if ($action === 'renew') {
                $copyBarcode = trim((string)($_POST['copy_barcode'] ?? ''));
                if ($copyBarcode === '') throw new RuntimeException('Serve il Barcode copia.');

                if (!$db->inTransaction()) $db->beginTransaction();

                $copy = $findCopyByBarcode($db, $copyBarcode);
                if (!$copy) throw new RuntimeException('Copia non trovata (barcode).');

                $bibid  = (int)($copy['bibid'] ?? 0);
                $copyid = (int)($copy['copyid'] ?? 0);

                $curStatus = strtolower(trim((string)($copy['status_cd'] ?? '')));
                $mbrid = (int)($copy['mbrid'] ?? 0);
                $renewals = (int)($copy['renewal_count'] ?? 0);
                $curDue = trim((string)($copy['due_back_dt'] ?? ''));

                if (!in_array($curStatus, [strtolower($STATUS_LOAN_A), strtolower($STATUS_LOAN_B)], true) || $mbrid <= 0) {
                    throw new RuntimeException('La copia non risulta in prestito.');
                }

                $st = $db->prepare("SELECT classification, is_active FROM {$T_MEMBER} WHERE mbrid=? LIMIT 1");
                $st->execute([$mbrid]);
                $member = $st->fetch(PDO::FETCH_ASSOC);
                if (!$member) throw new RuntimeException('Utente non trovato per questo prestito.');
                if (((string)($member['is_active'] ?? 'Y')) !== 'Y') {
                    throw new RuntimeException('Utente disattivo: rinnovo non consentito.');
                }
                $classification = (int)($member['classification'] ?? 0);

                $st = $db->prepare("SELECT material_cd FROM {$T_BIBLIO} WHERE bibid=? LIMIT 1");
                $st->execute([$bibid]);
                $materialCd = (int)($st->fetchColumn() ?? 0);

                $maxRen = $getMaxRenewals($db, $classification, $materialCd);
                if ($maxRen > 0 && $renewals >= $maxRen) {
                    throw new RuntimeException('Numero massimo rinnovi raggiunto (' . $maxRen . ').');
                }

                $baseDate = $today;
                if ($curDue !== '') {
                    try {
                        $dueDate = new DateTimeImmutable($curDue);
                        if ($dueDate >= $today) $baseDate = $dueDate;
                    } catch (Throwable $e) {}
                }

                $newDue = $baseDate->modify("+{$LOAN_DAYS_DEFAULT} days")->format('Y-m-d');
                $newRenewals = $renewals + 1;

                $sql = "UPDATE {$T_COPY}
                        SET due_back_dt=?,
                            renewal_count=?
                        WHERE bibid=? AND copyid=? LIMIT 1";
                $db->prepare($sql)->execute([$newDue, $newRenewals, $bibid, $copyid]);

                $insertHist($db, $bibid, $copyid, $STATUS_LOAN_A, $mbrid, $newDue, $newRenewals, $nowDt);

                if ($db->inTransaction()) $db->commit();

                $ok = 'Rinnovo registrato: copia ' . $copyBarcode . ' (nuova scadenza ' . $newDue . ').';
                $go('index.php?page=admin_loans&tab=open');
            }

        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $err = 'Errore: ' . h($e->getMessage());
        }
    }
}

// -----------------------------------------------------------------------------
// Dati per UI
// -----------------------------------------------------------------------------
$openLoans  = [];
$holds      = [];
$statusDm   = [];
$memberMatches = [];
$copyMatches   = [];

try {
    $st = $db->query("SELECT code, description FROM {$T_STATUS_DM}");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $statusDm[(string)$r['code']] = (string)$r['description'];
    }
} catch (Throwable $e) {}

try {
    // Prestiti aperti: status out/ln
    // NUOVO: aggiunto b.call_nmbr1 per collocazione
    $params = [];
    $where = "(c.status_cd = ? OR c.status_cd = ?)";
    $params[] = $STATUS_LOAN_A;
    $params[] = $STATUS_LOAN_B;

    if ($q !== '') {
        $where .= " AND (
            m.last_name LIKE ? OR m.first_name LIKE ? OR m.email LIKE ? OR m.barcode_nmbr LIKE ?
            OR c.barcode_nmbr LIKE ?
            OR b.title LIKE ? OR b.author LIKE ?
        )";
        $like = "%{$q}%";
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }

    $sql = "
        SELECT
            c.bibid, c.copyid, c.barcode_nmbr AS copy_barcode,
            c.due_back_dt, c.renewal_count, c.mbrid,
            m.barcode_nmbr AS member_barcode, m.last_name, m.first_name, m.email,
            b.title, b.author, b.call_nmbr1
        FROM {$T_COPY} c
        LEFT JOIN {$T_MEMBER} m ON m.mbrid = c.mbrid
        LEFT JOIN {$T_BIBLIO} b ON b.bibid = c.bibid
        WHERE {$where}
        ORDER BY c.due_back_dt ASC, m.last_name ASC, m.first_name ASC, c.bibid ASC, c.copyid ASC
        LIMIT 400
    ";
    $st = $db->prepare($sql);
    $st->execute($params);
    $openLoans = $st->fetchAll(PDO::FETCH_ASSOC);

    // Prenotazioni
    $sql = "
        SELECT
            h.hold_begin_dt, h.bibid, h.copyid, h.mbrid,
            m.barcode_nmbr AS member_barcode, m.last_name, m.first_name,
            c.barcode_nmbr AS copy_barcode,
            b.title, b.author
        FROM {$T_HOLD} h
        LEFT JOIN {$T_MEMBER} m ON m.mbrid = h.mbrid
        LEFT JOIN {$T_COPY} c ON c.bibid = h.bibid AND c.copyid = h.copyid
        LEFT JOIN {$T_BIBLIO} b ON b.bibid = h.bibid
        ORDER BY h.hold_begin_dt DESC
        LIMIT 200
    ";
    $holds = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // Trova utente (checkout)
    if ($tab === 'checkout' && $memberQ !== '') {
        $like = '%' . $memberQ . '%';
        $st = $db->prepare("SELECT mbrid, barcode_nmbr, last_name, first_name, email, is_active
                            FROM {$T_MEMBER}
                            WHERE barcode_nmbr LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR email LIKE ?
                            ORDER BY last_name ASC, first_name ASC
                            LIMIT 25");
        $st->execute([$like, $like, $like, $like]);
        $memberMatches = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // Trova copia (checkout)
    if ($tab === 'checkout' && $copyQ !== '') {
        $like = '%' . $copyQ . '%';

        $st = $db->prepare("SELECT bibid FROM {$T_BIBLIO} WHERE title LIKE ? OR author LIKE ? ORDER BY title ASC LIMIT 20");
        $st->execute([$like, $like]);
        $bibids = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: [])));

        if ($bibids !== []) {
            $ph = implode(',', array_fill(0, count($bibids), '?'));
            $sql = "SELECT
                        c.bibid, c.copyid, c.barcode_nmbr AS copy_barcode, c.status_cd, c.due_back_dt,
                        b.title, b.author
                    FROM {$T_COPY} c
                    LEFT JOIN {$T_BIBLIO} b ON b.bibid = c.bibid
                    WHERE c.bibid IN ({$ph})
                    ORDER BY c.bibid ASC, c.copyid ASC
                    LIMIT 200";
            $st = $db->prepare($sql);
            $st->execute($bibids);
            $copyMatches = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $st = $db->prepare("SELECT
                                    c.bibid, c.copyid, c.barcode_nmbr AS copy_barcode, c.status_cd, c.due_back_dt,
                                    b.title, b.author
                                FROM {$T_COPY} c
                                LEFT JOIN {$T_BIBLIO} b ON b.bibid = c.bibid
                                WHERE c.barcode_nmbr LIKE ?
                                ORDER BY c.bibid ASC, c.copyid ASC
                                LIMIT 50");
            $st->execute([$like]);
            $copyMatches = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }

} catch (Throwable $e) {
    $err = 'Errore: ' . h($e->getMessage());
}

// -----------------------------------------------------------------------------
// UI
// -----------------------------------------------------------------------------
$tabs = [
    'open'     => 'Prestiti aperti',
    'checkout' => 'Nuovo prestito',
    'checkin'  => 'Restituzione',
    'holds'    => 'Prenotazioni',
];

$tabUrl = static function (string $t) use ($q): string {
    $qs = ['page' => 'admin_loans', 'tab' => $t];
    if ($q !== '') $qs['q'] = $q;
    return 'index.php?' . http_build_query($qs);
};

$pickClearUrl = static function(string $what) use ($tab): string {
    return 'index.php?' . http_build_query([
        'page' => 'admin_loans',
        'tab'  => $tab,
        'clear_pick' => $what,
    ]);
};

?>
<section class="card" style="margin-top:20px">
  <style>
    .al-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap}
    .al-title{margin:0;font-size:22px;letter-spacing:-.2px;font-weight:700}
    .al-sub{margin:6px 0 0 0;color:#6b7280;font-size:13px}
    .al-msg{margin:10px 0 0 0}
    .al-msg.err{color:#b00020}
    .al-msg.ok{color:#0a7}

    .al-tabs{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
    .al-tab{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:12px;border:1px solid #e6e6e6;background:#fafafa;color:#111;text-decoration:none;font-weight:600}
    .al-tab.active{border-color:#111;background:#fff}

    .al-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:14px 0 12px 0;padding:12px;border:1px solid #e7e7e7;border-radius:10px;background:#fafafa}
    .al-toolbar .al-q{flex:1 1 320px;min-width:240px}
    .al-toolbar .al-actions{margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .al-reset{color:#b00020;text-decoration:none;font-weight:700;padding:6px 6px}

    .al-panel{border:1px solid #e7e7e7;border-radius:12px;background:#fff;padding:12px}
    .al-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:980px){.al-grid{grid-template-columns:1fr}}

    .al-table{width:100%;border-collapse:collapse}
    .al-table th{padding:8px;text-align:left;border-bottom:1px solid #eee;background:#fafafa;font-size:13px;color:#444;font-weight:600}
    .al-table td{padding:8px;border-top:1px solid #f1f1f1;vertical-align:top;font-size:14px}
    .al-muted{color:#6b7280;font-size:12px}
    .al-small{font-size:12px;color:#6b7280}
    .al-cta{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:flex-end}
    .al-form{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
    .al-form .input{min-width:240px}
    .al-form label{display:block}
    .al-form .al-lbl{display:block;font-size:12px;color:#6b7280;margin:0 0 4px 0}
    .al-pick{display:inline-flex;align-items:center;gap:6px;text-decoration:none}

    .al-picks{margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .al-chip{display:inline-flex;gap:8px;align-items:center;border:1px solid #e5e7eb;background:#fafafa;border-radius:999px;padding:6px 10px;font-size:12px}
    .al-chip strong{font-weight:700}
    .al-chip a{color:#b00020;text-decoration:none;font-weight:700}

    /* NUOVO: Stili per ritardo */
    .al-overdue{background-color:#fef2f2 !important}
    .al-overdue td{border-top-color:#fecaca !important}
    .al-delay-badge{display:inline-block;padding:2px 8px;background:#dc2626;color:#fff;border-radius:999px;font-size:11px;font-weight:700}
    .al-ok-badge{display:inline-block;padding:2px 8px;background:#16a34a;color:#fff;border-radius:999px;font-size:11px;font-weight:700}
    .al-due-soon{color:#ca8a04;font-weight:600}
    .al-due-ok{color:#16a34a}
    .al-due-overdue{color:#dc2626;font-weight:700}
  </style>

  <div class="al-head">
    <div>
      <h1 class="al-title">Prestiti</h1>
      <p class="al-sub">Gestione circolazione: prestiti, restituzioni, rinnovi e prenotazioni.</p>
    </div>
    <a class="button secondary" href="index.php?page=staff">← Area staff</a>
  </div>

  <?php if ($err): ?>
    <p class="al-msg err"><?= $err ?></p>
  <?php elseif ($ok): ?>
    <p class="al-msg ok"><?= h($ok) ?></p>
  <?php endif; ?>

  <div class="al-tabs">
    <?php foreach ($tabs as $k => $label): ?>
      <a class="al-tab <?= $tab === $k ? 'active' : '' ?>" href="<?= h($tabUrl($k)) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <form class="al-toolbar" method="get">
    <input type="hidden" name="page" value="admin_loans">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <input class="input al-q" name="q" value="<?= h($q) ?>" placeholder="Filtra: utente, email, barcode, copia, titolo...">
    <div class="al-actions">
      <button class="button" type="submit">Filtra</button>
      <a class="al-reset" href="index.php?page=admin_loans&tab=<?= h($tab) ?>">Reset</a>
    </div>
  </form>

  <?php if ($tab === 'checkout'): ?>
    <div class="al-picks">
      <span class="al-chip">Utente selezionato: <strong><?= h($prefillMemberBarcode !== '' ? $prefillMemberBarcode : '—') ?></strong> <?php if ($prefillMemberBarcode !== ''): ?><a href="<?= h($pickClearUrl('member')) ?>">x</a><?php endif; ?></span>
      <span class="al-chip">Copia selezionata: <strong><?= h($prefillCopyBarcode !== '' ? $prefillCopyBarcode : '—') ?></strong> <?php if ($prefillCopyBarcode !== ''): ?><a href="<?= h($pickClearUrl('copy')) ?>">x</a><?php endif; ?></span>
      <?php if ($prefillMemberBarcode !== '' || $prefillCopyBarcode !== ''): ?>
        <span class="al-chip"><a href="<?= h($pickClearUrl('all')) ?>">Svuota tutto</a></span>
      <?php endif; ?>
    </div>

    <div class="al-grid" style="margin-top:10px">
      <div class="al-panel">
        <h2 style="margin:0 0 10px 0;font-size:18px;font-weight:700">Nuovo prestito (barcode)</h2>

        <form method="post" class="al-form">
          <input type="hidden" name="csrf" value="<?= h($csrfToken()) ?>">
          <input type="hidden" name="action" value="checkout">

          <label>
            <span class="al-lbl">Barcode utente</span>
            <input class="input" name="member_barcode" placeholder="Es. 1012" required value="<?= h($prefillMemberBarcode) ?>">
          </label>

          <label>
            <span class="al-lbl">Barcode copia</span>
            <input class="input" name="copy_barcode" placeholder="Es. 011837" required value="<?= h($prefillCopyBarcode) ?>">
          </label>

          <div class="al-cta">
            <button class="button" type="submit">Registra prestito</button>
          </div>
        </form>

        <p class="al-small" style="margin:10px 0 0 0">
          Scadenza: <strong><?= (int)$LOAN_DAYS_DEFAULT ?></strong> giorni (fallback). Limite rinnovi: <strong>checkout_privs.renewal_limit</strong>.
          Prestito su status <strong><?= h($STATUS_LOAN_A) ?></strong> (compatibile anche con <strong><?= h($STATUS_LOAN_B) ?></strong>).
        </p>
      </div>

      <div class="al-panel">
        <h2 style="margin:0 0 10px 0;font-size:18px;font-weight:700">Trova barcode (senza conoscere gli ID)</h2>

        <div style="margin-bottom:14px">
          <h3 style="margin:0 0 6px 0;font-size:14px;font-weight:700">Cerca utente</h3>
          <form method="get" class="al-form" style="align-items:center">
            <input type="hidden" name="page" value="admin_loans">
            <input type="hidden" name="tab" value="checkout">
            <input class="input" name="member_q" value="<?= h($memberQ) ?>" placeholder="Cognome, nome, email o barcode...">
            <button class="button secondary" type="submit">Cerca</button>
          </form>

          <?php if ($memberQ !== '' && !$memberMatches): ?>
            <p class="al-small" style="margin:8px 0 0 0">Nessun utente trovato.</p>
          <?php elseif ($memberMatches): ?>
            <table class="al-table" style="margin-top:8px">
              <thead>
                <tr><th>Utente</th><th>Barcode</th><th>Email</th><th style="width:1%"></th></tr>
              </thead>
              <tbody>
                <?php foreach ($memberMatches as $m): ?>
                  <?php
                    $bc = (string)($m['barcode_nmbr'] ?? '');
                    $nm = trim((string)($m['last_name'] ?? '') . ' ' . (string)($m['first_name'] ?? ''));
                    $em = (string)($m['email'] ?? '');
                    $active = (string)($m['is_active'] ?? 'Y');
                    $pickUrl = 'index.php?' . http_build_query([
                      'page' => 'admin_loans',
                      'tab'  => 'checkout',
                      'set_member' => $bc,
                      'member_q' => $memberQ,
                      'copy_q'   => $copyQ,
                    ]);
                  ?>
                  <tr>
                    <td>
                      <?= h($nm !== '' ? $nm : '—') ?>
                      <?php if ($active !== 'Y'): ?>
                        <div class="al-small" style="color:#b00020">Disattivo</div>
                      <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap"><?= h($bc !== '' ? $bc : '—') ?></td>
                    <td><?= h($em) ?></td>
                    <td style="white-space:nowrap">
                      <?php if ($bc !== ''): ?>
                        <a class="al-pick" href="<?= h($pickUrl) ?>">Usa</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div>
          <h3 style="margin:0 0 6px 0;font-size:14px;font-weight:700">Cerca copia (titolo/autore o barcode)</h3>
          <form method="get" class="al-form" style="align-items:center">
            <input type="hidden" name="page" value="admin_loans">
            <input type="hidden" name="tab" value="checkout">
            <input class="input" name="copy_q" value="<?= h($copyQ) ?>" placeholder="Titolo, autore o barcode copia...">
            <button class="button secondary" type="submit">Cerca</button>
          </form>

          <?php if ($copyQ !== '' && !$copyMatches): ?>
            <p class="al-small" style="margin:8px 0 0 0">Nessuna copia trovata.</p>
          <?php elseif ($copyMatches): ?>
            <table class="al-table" style="margin-top:8px">
              <thead>
                <tr><th>Titolo</th><th>Copia</th><th>Barcode</th><th>Stato</th><th>Scadenza</th><th style="width:1%"></th></tr>
              </thead>
              <tbody>
                <?php foreach ($copyMatches as $c): ?>
                  <?php
                    $bc = (string)($c['copy_barcode'] ?? '');
                    $copyid = (int)($c['copyid'] ?? 0);
                    $bibid = (int)($c['bibid'] ?? 0);
                    $t = trim((string)($c['title'] ?? ''));
                    $a = trim((string)($c['author'] ?? ''));
                    $stCd = (string)($c['status_cd'] ?? '');
                    $due = (string)($c['due_back_dt'] ?? '');
                    $stDesc = $statusDm[$stCd] ?? '';
                    $pickUrl = 'index.php?' . http_build_query([
                      'page' => 'admin_loans',
                      'tab'  => 'checkout',
                      'set_copy' => $bc,
                      'member_q' => $memberQ,
                      'copy_q'   => $copyQ,
                    ]);
                  ?>
                  <tr>
                    <td>
                      <?= h($t !== '' ? $t : ('BIBID ' . $bibid)) ?>
                      <?php if ($a !== ''): ?><div class="al-muted"><?= h($a) ?></div><?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">#<?= (int)$copyid ?></td>
                    <td style="white-space:nowrap"><?= h($bc !== '' ? $bc : '—') ?></td>
                    <td style="white-space:nowrap"><?= h($statusLabel($stCd, $stDesc)) ?></td>
                    <td style="white-space:nowrap"><?= h($due !== '' ? $due : '—') ?></td>
                    <td style="white-space:nowrap">
                      <?php if ($bc !== ''): ?>
                        <a class="al-pick" href="<?= h($pickUrl) ?>">Usa</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p class="al-small" style="margin:8px 0 0 0">
              Se cerchi per titolo/autore, qui vengono mostrate <strong>tutte le copie</strong> dei record trovati (limite 200).
            </p>
          <?php endif; ?>
        </div>
      </div>
    </div>

  <?php elseif ($tab === 'checkin'): ?>
    <div class="al-panel">
      <h2 style="margin:0 0 10px 0;font-size:18px;font-weight:700">Restituzione</h2>

      <form method="post" class="al-form" style="margin-bottom:12px">
        <input type="hidden" name="csrf" value="<?= h($csrfToken()) ?>">
        <input type="hidden" name="action" value="checkin">
        <label>
          <span class="al-lbl">Barcode copia</span>
          <input class="input" name="copy_barcode" placeholder="Es. 011837" required>
        </label>
        <div class="al-cta">
          <button class="button" type="submit">Registra restituzione</button>
        </div>
      </form>

      <p class="al-small" style="margin:0 0 10px 0">
        La restituzione accetta copie in stato <strong><?= h($STATUS_LOAN_A) ?></strong> o <strong><?= h($STATUS_LOAN_B) ?></strong>.
        Qui sotto trovi anche l'elenco dei prestiti aperti per fare check-in con un click.
      </p>

      <?php if (!$openLoans): ?>
        <p style="margin:10px 0 0 0;color:#666">Nessun prestito aperto.</p>
      <?php else: ?>
        <table class="al-table" style="margin-top:10px">
          <thead>
            <tr>
              <th>Utente</th>
              <th>Titolo</th>
              <th>Collocazione</th>
              <th>Copia</th>
              <th>Scadenza</th>
              <th>Ritardo</th>
              <th style="width:1%;white-space:nowrap">Check-in</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($openLoans as $r): ?>
              <?php
                $mName = trim((string)($r['last_name'] ?? '') . ' ' . (string)($r['first_name'] ?? ''));
                $mBarcode = (string)($r['member_barcode'] ?? '');
                $bibid = (int)($r['bibid'] ?? 0);
                $titleRow = trim((string)($r['title'] ?? ''));
                $author = trim((string)($r['author'] ?? ''));
                $callNmbr1 = trim((string)($r['call_nmbr1'] ?? ''));
                $copyid = (int)($r['copyid'] ?? 0);
                $copyBarcode = (string)($r['copy_barcode'] ?? '');
                $due = (string)($r['due_back_dt'] ?? '');
                $delayDays = $getDelayDays($due);
                $isOverdue = $delayDays > 0;
                $itemUrl = 'index.php?page=item&bibid=' . $bibid;
              ?>
              <tr class="<?= $isOverdue ? 'al-overdue' : '' ?>">
                <td>
                  <div><?= h($mName !== '' ? $mName : '—') ?></div>
                  <div class="al-muted">Barcode: <?= h($mBarcode !== '' ? $mBarcode : '—') ?></div>
                </td>
                <td>
                  <div>
                    <?php if ($bibid > 0): ?>
                      <a href="<?= h($itemUrl) ?>" style="color:#111;text-decoration:underline">
                        <?= h($titleRow !== '' ? $titleRow : ('BIBID ' . $bibid)) ?>
                      </a>
                    <?php else: ?>
                      <?= h($titleRow !== '' ? $titleRow : '—') ?>
                    <?php endif; ?>
                  </div>
                  <?php if ($author !== ''): ?><div class="al-muted"><?= h($author) ?></div><?php endif; ?>
                </td>
                <td style="font-family:monospace;white-space:nowrap">
                  <?= h($callNmbr1 !== '' ? $callNmbr1 : '—') ?>
                </td>
                <td style="white-space:nowrap">
                  Copia <?= $copyid > 0 ? (int)$copyid : '—' ?>
                  <?php if ($copyBarcode !== ''): ?><span class="al-muted"> — <?= h($copyBarcode) ?></span><?php endif; ?>
                </td>
                <td style="white-space:nowrap" class="<?= $isOverdue ? 'al-due-overdue' : 'al-due-ok' ?>">
                  <?= h($due !== '' ? $due : '—') ?>
                </td>
                <td style="white-space:nowrap">
                  <?php if ($isOverdue): ?>
                    <span class="al-delay-badge"><?= $delayDays ?> gg</span>
                  <?php else: ?>
                    <span class="al-ok-badge">In regola</span>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                  <?php if ($copyBarcode !== ''): ?>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="csrf" value="<?= h($csrfToken()) ?>">
                      <input type="hidden" name="action" value="checkin">
                      <input type="hidden" name="copy_barcode" value="<?= h($copyBarcode) ?>">
                      <button class="button secondary" type="submit"
                        onclick="return confirm('Confermi la restituzione della copia <?= h($copyBarcode) ?>?');">
                        Restituisci
                      </button>
                    </form>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  <?php elseif ($tab === 'holds'): ?>
    <div class="al-panel">
      <h2 style="margin:0 0 10px 0;font-size:18px;font-weight:700">Prenotazioni</h2>

      <?php if (!$holds): ?>
        <p style="margin:0;color:#666">Nessuna prenotazione.</p>
      <?php else: ?>
        <table class="al-table">
          <thead>
            <tr>
              <th>Data</th>
              <th>Utente</th>
              <th>Titolo</th>
              <th>Copia</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($holds as $r): ?>
              <?php
                $dt = (string)($r['hold_begin_dt'] ?? '');
                $mName = trim((string)($r['last_name'] ?? '') . ' ' . (string)($r['first_name'] ?? ''));
                $mBarcode = (string)($r['member_barcode'] ?? '');
                $bibid = (int)($r['bibid'] ?? 0);
                $titleRow = trim((string)($r['title'] ?? ''));
                $author = trim((string)($r['author'] ?? ''));
                $copyid = (int)($r['copyid'] ?? 0);
                $copyBarcode = (string)($r['copy_barcode'] ?? '');
                $itemUrl = 'index.php?page=item&bibid=' . $bibid;
              ?>
              <tr>
                <td style="white-space:nowrap"><?= h($dt) ?></td>
                <td>
                  <div><?= h($mName !== '' ? $mName : '—') ?></div>
                  <div class="al-muted">Barcode: <?= h($mBarcode !== '' ? $mBarcode : '—') ?></div>
                </td>
                <td>
                  <div>
                    <?php if ($bibid > 0): ?>
                      <a href="<?= h($itemUrl) ?>" style="color:#111;text-decoration:underline"><?= h($titleRow !== '' ? $titleRow : ('BIBID ' . $bibid)) ?></a>
                    <?php else: ?>
                      <?= h($titleRow !== '' ? $titleRow : '—') ?>
                    <?php endif; ?>
                  </div>
                  <?php if ($author !== ''): ?><div class="al-muted"><?= h($author) ?></div><?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                  <?= $copyid > 0 ? 'Copia ' . (int)$copyid : '—' ?>
                  <?php if ($copyBarcode !== ''): ?><span class="al-muted"> — <?= h($copyBarcode) ?></span><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  <?php else: /* open */ ?>
    <div class="al-panel">
      <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:10px;flex-wrap:wrap">
        <div>
          <h2 style="margin:0 0 4px 0;font-size:18px;font-weight:700">Prestiti aperti</h2>
          <div class="al-muted">Elenco copie in prestito (status <strong><?= h($STATUS_LOAN_A) ?></strong>/<strong><?= h($STATUS_LOAN_B) ?></strong>) con azioni rapide.</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a class="button secondary" href="index.php?page=admin_loans&tab=checkout">Nuovo prestito</a>
          <a class="button secondary" href="index.php?page=admin_loans&tab=checkin">Restituzione</a>
        </div>
      </div>

      <?php if (!$openLoans): ?>
        <p style="margin:10px 0 0 0;color:#666">Nessun prestito aperto.</p>
      <?php else: ?>
        <table class="al-table" style="margin-top:10px">
          <thead>
            <tr>
              <th>Utente</th>
              <th>Titolo</th>
              <th>Collocazione</th>
              <th>Copia</th>
              <th>Scadenza</th>
              <th>Ritardo</th>
              <th>Rinnovi</th>
              <th style="width:1%;white-space:nowrap">Azioni</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($openLoans as $r): ?>
              <?php
                $mbrid = (int)($r['mbrid'] ?? 0);
                $mName = trim((string)($r['last_name'] ?? '') . ' ' . (string)($r['first_name'] ?? ''));
                $mBarcode = (string)($r['member_barcode'] ?? '');
                $email = (string)($r['email'] ?? '');

                $bibid = (int)($r['bibid'] ?? 0);
                $titleRow = trim((string)($r['title'] ?? ''));
                $author = trim((string)($r['author'] ?? ''));
                $callNmbr1 = trim((string)($r['call_nmbr1'] ?? ''));

                $copyid = (int)($r['copyid'] ?? 0);
                $copyBarcode = (string)($r['copy_barcode'] ?? '');

                $due = (string)($r['due_back_dt'] ?? '');
                $ren = (int)($r['renewal_count'] ?? 0);
                
                // NUOVO: calcolo ritardo
                $delayDays = $getDelayDays($due);
                $isOverdue = $delayDays > 0;

                $itemUrl = 'index.php?page=item&bibid=' . $bibid;
                $patronUrl = 'index.php?page=admin_patron&mbrid=' . $mbrid . '&tab=loans';
              ?>
              <tr class="<?= $isOverdue ? 'al-overdue' : '' ?>">
                <td>
                  <div>
                    <?php if ($mbrid > 0): ?>
                      <a href="<?= h($patronUrl) ?>" style="color:#111;text-decoration:underline"><?= h($mName !== '' ? $mName : ('MBRID ' . $mbrid)) ?></a>
                    <?php else: ?>
                      <?= h($mName !== '' ? $mName : '—') ?>
                    <?php endif; ?>
                  </div>
                  <div class="al-muted">Barcode: <?= h($mBarcode !== '' ? $mBarcode : '—') ?><?= $email !== '' ? ' — ' . h($email) : '' ?></div>
                </td>
                <td>
                  <div>
                    <?php if ($bibid > 0): ?>
                      <a href="<?= h($itemUrl) ?>" style="color:#111;text-decoration:underline"><?= h($titleRow !== '' ? $titleRow : ('BIBID ' . $bibid)) ?></a>
                    <?php else: ?>
                      <?= h($titleRow !== '' ? $titleRow : '—') ?>
                    <?php endif; ?>
                  </div>
                  <?php if ($author !== ''): ?><div class="al-muted"><?= h($author) ?></div><?php endif; ?>
                </td>
                <td style="font-family:monospace;white-space:nowrap">
                  <?= h($callNmbr1 !== '' ? $callNmbr1 : '—') ?>
                </td>
                <td style="white-space:nowrap">
                  Copia <?= $copyid > 0 ? (int)$copyid : '—' ?>
                  <?php if ($copyBarcode !== ''): ?><span class="al-muted"> — <?= h($copyBarcode) ?></span><?php endif; ?>
                </td>
                <td style="white-space:nowrap" class="<?= $isOverdue ? 'al-due-overdue' : 'al-due-ok' ?>">
                  <?= h($due !== '' ? $due : '—') ?>
                </td>
                <td style="white-space:nowrap">
                  <?php if ($isOverdue): ?>
                    <span class="al-delay-badge"><?= $delayDays ?> gg</span>
                  <?php else: ?>
                    <span class="al-ok-badge">In regola</span>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap"><?= (int)$ren ?></td>
                <td style="white-space:nowrap">
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= h($csrfToken()) ?>">
                    <input type="hidden" name="action" value="renew">
                    <input type="hidden" name="copy_barcode" value="<?= h($copyBarcode) ?>">
                    <button class="button secondary" type="submit">Rinnova</button>
                  </form>
                  <form method="post" style="display:inline;margin-left:6px">
                    <input type="hidden" name="csrf" value="<?= h($csrfToken()) ?>">
                    <input type="hidden" name="action" value="checkin">
                    <input type="hidden" name="copy_barcode" value="<?= h($copyBarcode) ?>">
                    <button class="button secondary" type="submit"
                      onclick="return confirm('Confermi la restituzione della copia <?= h($copyBarcode) ?>?');">
                      Restituisci
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <p class="al-small" style="margin:10px 0 0 0">
          Azioni registrano anche lo storico in <strong><?= h($T_STATUS_HIST) ?></strong>.
        </p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div style="margin-top:12px" class="al-muted">
    Tabelle utilizzate: <?= h($T_MEMBER) ?>, <?= h($T_BIBLIO) ?>, <?= h($T_COPY) ?>, <?= h($T_HOLD) ?>, <?= h($T_STATUS_DM) ?>, <?= h($T_STATUS_HIST) ?>, <?= h($T_PRIVS) ?>.
  </div>
</section>