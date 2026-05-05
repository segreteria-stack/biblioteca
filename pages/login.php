<?php
declare(strict_types=1);

/**
 * Pagina di accesso staff.
 *
 * - Autentica gli utenti sulla tabella `staff`
 * - Password memorizzate come MD5 (colonna `pwd`, char(32))
 * - Usa la sessione per marcare l'operatore staff autenticato
 *
 * Parametri:
 * - GET/POST redirect: nome pagina su cui reindirizzare dopo il login (es. "staff")
 *
 * Dipendenze:
 * - DB::conn() in lib/DB.php
 * - h() in lib/helpers.php
 */

$pdo = DB::conn();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$baseUrl = function_exists('base_url') ? base_url() : '';

$errors   = [];
$username = '';
// Dove andare dopo il login: di default area staff
$redirect = (string)($_GET['redirect'] ?? ($_POST['redirect'] ?? 'staff'));

// Se l'utente è già loggato come staff, lo portiamo direttamente all'area staff
if (!empty($_SESSION['staff_user_id'])) {
    $target = 'index.php?page=' . urlencode($redirect);
    header('Location: ' . $target);
    exit;
}

// -----------------------------------------------------------------------------
// Gestione POST (tentativo di login)
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $errors[] = 'Inserisci sia lo username che la password.';
    } else {
        try {
            $sql = '
                SELECT
                    userid,
                    username,
                    pwd,
                    last_name,
                    first_name,
                    suspended_flg,
                    admin_flg,
                    circ_flg,
                    circ_mbr_flg,
                    catalog_flg,
                    reports_flg
                FROM staff
                WHERE username = :username
                LIMIT 1
            ';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                // Utente non trovato
                $errors[] = 'Credenziali non valide.';
            } else {
                // Utente eventualmente sospeso
                $suspended = strtoupper(trim((string)$row['suspended_flg'])) === 'Y';
                if ($suspended) {
                    $errors[] = 'L\'account è sospeso. Contatta l\'amministratore.';
                } else {
                    $dbHash = (string)($row['pwd'] ?? '');
                    $inHash = md5($password); // compatibile con schema esistente

                    if (!hash_equals($dbHash, $inHash)) {
                        $errors[] = 'Credenziali non valide.';
                    } else {
                        // Login ok: impostiamo i dati di sessione staff
                        $_SESSION['staff_user_id']    = (int)$row['userid'];
                        $_SESSION['staff_username']   = (string)$row['username'];
                        $_SESSION['staff_fullname']   = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
                        $_SESSION['staff_is_admin']   = (strtoupper((string)$row['admin_flg']) === 'Y');
                        $_SESSION['staff_can_circ']   = (strtoupper((string)$row['circ_flg']) === 'Y');
                        $_SESSION['staff_can_circ_m'] = (strtoupper((string)$row['circ_mbr_flg']) === 'Y');
                        $_SESSION['staff_can_catalog']= (strtoupper((string)$row['catalog_flg']) === 'Y');
                        $_SESSION['staff_can_reports']= (strtoupper((string)$row['reports_flg']) === 'Y');

                        // Reindirizzo all'area richiesta (di solito "staff")
                        $target = 'index.php?page=' . urlencode($redirect);
                        header('Location: ' . $target);
                        exit;
                    }
                }
            }
        } catch (PDOException $e) {
            // Errore DB: messaggio generico per sicurezza
            $errors[] = 'Si è verificato un errore durante il login. Riprova.';
        }
    }
}

?>
<section class="page-section">
    <div class="auth-box">
        <h1>Accesso staff</h1>

        <p>
            Inserisci le credenziali per accedere all'area di gestione del catalogo,
            degli utenti e dei prestiti.
        </p>

        <?php if ($errors !== []): ?>
            <div class="generic-box" style="margin-top:0.75rem;">
                <?php foreach ($errors as $msg): ?>
                    <p><?= h($msg) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= h($baseUrl) ?>/index.php?page=login">
            <input type="hidden" name="redirect" value="<?= h($redirect) ?>">

            <div class="search-row">
                <label for="staff-username">Username</label>
                <input
                    type="text"
                    id="staff-username"
                    name="username"
                    value="<?= h($username) ?>"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="search-row">
                <label for="staff-password">Password</label>
                <input
                    type="password"
                    id="staff-password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <div class="search-actions">
                <button type="submit" class="btn-primary">
                    Accedi
                </button>

                <a
                    class="btn-link"
                    href="<?= h($baseUrl) ?>/index.php?page=staff_forgot"
                >
                    Password dimenticata?
                </a>

                <a class="btn-link" href="<?= h($baseUrl) ?>/index.php">
                    Torna alla home
                </a>
            </div>
        </form>

        <p class="search-help" style="margin-top:0.75rem;font-size:0.85rem;color:#666;">
            In caso di problemi di accesso, contatta l'amministratore della Biblioteca
            della Resistenza.
        </p>
    </div>
</section>
