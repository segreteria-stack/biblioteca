<?php
declare(strict_types=1);

/**
 * AJAX endpoint – Admin Loans
 * Path: /public/pages/admin_loans_ajax.php
 *
 * Sicurezza:
 * - richiede sessione staff (staff_user_id)
 * - per azioni mutanti (checkin) richiede CSRF (_csrf_staff)
 *
 * Output: JSON
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------------------
// Accesso staff obbligatorio
// -----------------------------------------------------------------------------
if (empty($_SESSION['staff_user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']);
    exit;
}

// -----------------------------------------------------------------------------
// DB dal bootstrap
// -----------------------------------------------------------------------------
/** @var PDO $db */
global $db;
if (!($db instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Connessione DB non disponibile.']);
    exit;
}

// -----------------------------------------------------------------------------
// JSON header
// -----------------------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// -----------------------------------------------------------------------------
// Tabelle (default OpenBiblio/derivati)
// -----------------------------------------------------------------------------
$T_MEMBER      = 'member';
$T_BIBLIO      = 'biblio';
$T_COPY        = 'biblio_copy';
$T_STATUS_HIST = 'biblio_status_hist';

// Status compat
$STATUS_AVAILABLE = 'in';
$STATUS_LOAN_A    = 'out';
$STATUS_LOAN_B    = 'ln';

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
// Helpers
// -----------------------------------------------------------------------------
$ok = static function ($data = null): void {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
};

$fail = static function (string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$trimQ = static function (?string $s, int $maxLen = 120): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    if (mb_strlen($s) > $maxLen) $s = mb_substr($s, 0, $maxLen);
    return $s;
};

// -----------------------------------------------------------------------------
// Routing
// -----------------------------------------------------------------------------
$op = (string)($_GET['op'] ?? $_POST['op'] ?? '');

try {
    // -------------------------------------------------------------------------
    // 1) member_search: q -> lista utenti (barcode/nome/email)
    // GET: op=member_search&q=...
    // -------------------------------------------------------------------------
    if ($op === 'member_search') {
        $q = $trimQ($_GET['q'] ?? '');
        if ($q === '') $ok([]);

        $like = '%' . $q . '%';
        $sql = "SELECT mbrid, barcode_nmbr, last_name, first_name, email, is_active
                FROM {$T_MEMBER}
                WHERE barcode_nmbr LIKE ?
                   OR last_name LIKE ?
                   OR first_name LIKE ?
                   OR email LIKE ?
                ORDER BY last_name ASC, first_name ASC
                LIMIT 25";
        $st = $db->prepare($sql);
        $st->execute([$like, $like, $like, $like]);

        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'mbrid'   => (int)($r['mbrid'] ?? 0),
                'barcode' => (string)($r['barcode_nmbr'] ?? ''),
                'name'    => trim((string)($r['last_name'] ?? '') . ' ' . (string)($r['first_name'] ?? '')),
                'email'   => (string)($r['email'] ?? ''),
                'active'  => ((string)($r['is_active'] ?? 'Y') === 'Y'),
            ];
        }
        $ok($rows);
    }

    // -------------------------------------------------------------------------
    // 2) copy_search: q -> copie (da titolo/autore o barcode copia)
    // GET: op=copy_search&q=...
    // -------------------------------------------------------------------------
    if ($op === 'copy_search') {
        $q = $trimQ($_GET['q'] ?? '');
        if ($q === '') $ok([]);

        $like = '%' . $q . '%';

        // 2a) trova bibid da title/author
        $st = $db->prepare("SELECT bibid FROM {$T_BIBLIO}
                            WHERE title LIKE ? OR author LIKE ?
                            ORDER BY title ASC
                            LIMIT 20");
        $st->execute([$like, $like]);
        $bibids = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: [])));

        $rows = [];

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
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // 2b) fallback: barcode copia
            $sql = "SELECT
                        c.bibid, c.copyid, c.barcode_nmbr AS copy_barcode, c.status_cd, c.due_back_dt,
                        b.title, b.author
                    FROM {$T_COPY} c
                    LEFT JOIN {$T_BIBLIO} b ON b.bibid = c.bibid
                    WHERE c.barcode_nmbr LIKE ?
                    ORDER BY c.bibid ASC, c.copyid ASC
                    LIMIT 50";
            $st = $db->prepare($sql);
            $st->execute([$like]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'bibid'   => (int)($r['bibid'] ?? 0),
                'copyid'  => (int)($r['copyid'] ?? 0),
                'barcode' => (string)($r['copy_barcode'] ?? ''),
                'status'  => (string)($r['status_cd'] ?? ''),
                'due'     => (string)($r['due_back_dt'] ?? ''),
                'title'   => (string)($r['title'] ?? ''),
                'author'  => (string)($r['author'] ?? ''),
            ];
        }
        $ok($out);
    }

    // -------------------------------------------------------------------------
    // 3) open_loans: elenco prestiti aperti (out/ln) con dati utente + titolo
    // GET: op=open_loans&q=... (opzionale)
    // -------------------------------------------------------------------------
    if ($op === 'open_loans') {
        $q = $trimQ($_GET['q'] ?? '');
        $params = [$STATUS_LOAN_A, $STATUS_LOAN_B];

        $where = "(c.status_cd = ? OR c.status_cd = ?)";
        if ($q !== '') {
            $where .= " AND (
                m.last_name LIKE ? OR m.first_name LIKE ? OR m.email LIKE ? OR m.barcode_nmbr LIKE ?
                OR c.barcode_nmbr LIKE ?
                OR b.title LIKE ? OR b.author LIKE ?
            )";
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }

        $sql = "SELECT
                    c.bibid, c.copyid, c.barcode_nmbr AS copy_barcode,
                    c.due_back_dt, c.renewal_count, c.mbrid,
                    m.barcode_nmbr AS member_barcode, m.last_name, m.first_name, m.email,
                    b.title, b.author
                FROM {$T_COPY} c
                LEFT JOIN {$T_MEMBER} m ON m.mbrid = c.mbrid
                LEFT JOIN {$T_BIBLIO} b ON b.bibid = c.bibid
                WHERE {$where}
                ORDER BY c.due_back_dt ASC, m.last_name ASC, m.first_name ASC, c.bibid ASC, c.copyid ASC
                LIMIT 400";
        $st = $db->prepare($sql);
        $st->execute($params);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'mbrid'         => (int)($r['mbrid'] ?? 0),
                'member_barcode'=> (string)($r['member_barcode'] ?? ''),
                'member_name'   => trim((string)($r['last_name'] ?? '') . ' ' . (string)($r['first_name'] ?? '')),
                'email'         => (string)($r['email'] ?? ''),
                'bibid'         => (int)($r['bibid'] ?? 0),
                'title'         => (string)($r['title'] ?? ''),
                'author'        => (string)($r['author'] ?? ''),
                'copyid'        => (int)($r['copyid'] ?? 0),
                'copy_barcode'  => (string)($r['copy_barcode'] ?? ''),
                'due'           => (string)($r['due_back_dt'] ?? ''),
                'renewals'      => (int)($r['renewal_count'] ?? 0),
            ];
        }
        $ok($out);
    }

    // -------------------------------------------------------------------------
    // 4) checkin: restituzione by barcode (POST)
    // POST: op=checkin&csrf=...&copy_barcode=...
    // -------------------------------------------------------------------------
    if ($op === 'checkin') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $fail('Metodo non consentito.', 405);
        }
        if (!$csrfCheck($_POST['csrf'] ?? null)) {
            $fail('Token CSRF non valido.', 403);
        }

        $copyBarcode = $trimQ($_POST['copy_barcode'] ?? '', 40);
        if ($copyBarcode === '') $fail('Serve il barcode della copia.');

        // Lock logico: lavoriamo sul record copia
        if (!$db->inTransaction()) $db->beginTransaction();

        $st = $db->prepare("SELECT bibid, copyid, status_cd, mbrid, renewal_count
                            FROM {$T_COPY}
                            WHERE barcode_nmbr = ?
                            LIMIT 1");
        $st->execute([$copyBarcode]);
        $copy = $st->fetch(PDO::FETCH_ASSOC);
        if (!$copy) throw new RuntimeException('Copia non trovata.');

        $bibid  = (int)($copy['bibid'] ?? 0);
        $copyid = (int)($copy['copyid'] ?? 0);
        $status = strtolower(trim((string)($copy['status_cd'] ?? '')));
        $mbrid  = (int)($copy['mbrid'] ?? 0);
        $renew  = (int)($copy['renewal_count'] ?? 0);

        if ($bibid <= 0 || $copyid <= 0) throw new RuntimeException('Copia non valida.');
        if (!in_array($status, [strtolower($STATUS_LOAN_A), strtolower($STATUS_LOAN_B)], true)) {
            throw new RuntimeException('La copia non risulta in prestito.');
        }

        $nowDt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        // Update copia -> disponibile
        $sql = "UPDATE {$T_COPY}
                SET status_cd=?,
                    status_begin_dt=?,
                    mbrid=NULL,
                    due_back_dt=NULL,
                    renewal_count=0
                WHERE bibid=? AND copyid=?
                LIMIT 1";
        $db->prepare($sql)->execute([$STATUS_AVAILABLE, $nowDt, $bibid, $copyid]);

        // Storico
        $sql = "INSERT INTO {$T_STATUS_HIST}
                (bibid, copyid, status_cd, status_begin_dt, due_back_dt, mbrid, renewal_count)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $db->prepare($sql)->execute([
            $bibid,
            $copyid,
            $STATUS_AVAILABLE,
            $nowDt,
            null,
            ($mbrid > 0 ? $mbrid : null),
            $renew,
        ]);

        if ($db->inTransaction()) $db->commit();

        $ok([
            'message' => 'Restituzione registrata.',
            'copy_barcode' => $copyBarcode,
            'bibid' => $bibid,
            'copyid' => $copyid,
        ]);
    }

    // -------------------------------------------------------------------------
    // fallback
    // -------------------------------------------------------------------------
    $fail('Operazione non valida.', 400);

} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $fail('Errore: ' . $e->getMessage(), 500);
}
