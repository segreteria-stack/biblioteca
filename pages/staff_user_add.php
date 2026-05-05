<?php
declare(strict_types=1);

/**
 * Area Staff – Creazione nuovo account staff
 *
 * Usa:
 * - tabella `staff` per i dati dell'operatore
 * - tabella `staff_password_reset` per generare (opzionale) un link di impostazione password
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$baseUrl = function_exists('base_url') ? base_url() : '';

// -----------------------------------------------------------------------------
// Protezione accesso: solo staff loggato, con permesso admin
// -----------------------------------------------------------------------------
if (empty($_SESSION['staff_user_id'])) {
    $redirect = 'staff_user_add';
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=' . urlencode($redirect));
    exit;
}

$isAdmin = !empty($_SESSION['staff_is_admin']) && $_SESSION['staff_is_admin'] === true;
if (!$isAdmin) {
    ?>
    <section class="page-section page-staff">
        <header class="staff-header">
            <h1>Nuovo account staff</h1>
        </header>
        <div class="generic-box">
            <p>Non hai i permessi necessari per creare nuovi account staff.</p>
        </div>
    </section>
    <?php
    return;
}

$pdo = DB::conn();

// -----------------------------------------------------------------------------
// Inizializzazione variabili form
// -----------------------------------------------------------------------------
$errors      = [];
$successMsg  = '';
$resetInfo   = '';   // eventuale info sul link di reset generato
$mode        = 'invite'; // default: genera link di impostazione password

$username    = '';
$email       = '';
$firstName   = '';
$lastName    = '';
$suspended   = 'N';
$adminFlg    = 'N';
$circFlg     = 'Y';
$circMbrFlg  = 'Y';
$catalogFlg  = 'Y';
$reportsFlg  = 'N';
$passwordSet = '';   // per modalità "set"
$password2   = '';

// -----------------------------------------------------------------------------
// Gestione POST – creazione nuovo staff
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lettura campi
    $username   = trim((string)($_POST['username'] ?? ''));
    $email      = trim((string)($_POST['email'] ?? ''));
    $firstName  = trim((string)($_POST['first_name'] ?? ''));
    $lastName   = trim((string)($_POST['last_name'] ?? ''));
    $suspended  = (isset($_POST['suspended']) && $_POST['suspended'] === 'Y') ? 'Y' : 'N';

    $adminFlg   = (isset($_POST['admin_flg'])   && $_POST['admin_flg']   === 'Y') ? 'Y' : 'N';
    $circFlg    = (isset($_POST['circ_flg'])    && $_POST['circ_flg']    === 'Y') ? 'Y' : 'N';
    $circMbrFlg = (isset($_POST['circ_mbr_flg'])&& $_POST['circ_mbr_flg']=== 'Y') ? 'Y' : 'N';
    $catalogFlg = (isset($_POST['catalog_flg']) && $_POST['catalog_flg'] === 'Y') ? 'Y' : 'N';
    $reportsFlg = (isset($_POST['reports_flg']) && $_POST['reports_flg'] === 'Y') ? 'Y' : 'N';

    $mode       = (string)($_POST['password_mode'] ?? 'invite');
    if ($mode !== 'set' && $mode !== 'invite') {
        $mode = 'invite';
    }

    if ($mode === 'set') {
        $passwordSet = (string)($_POST['password'] ?? '');
        $password2   = (string)($_POST['password_confirm'] ?? '');
    }

    // -------------------------------------------------------------------------
    // Validazione
    // -------------------------------------------------------------------------
    if ($username === '') {
        $errors[] = 'Lo username è obbligatorio.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,20}$/', $username)) {
        $errors[] = 'Lo username deve essere lungo 3–20 caratteri e può contenere solo lettere, numeri, punto, trattino e underscore.';
    }

    if ($lastName === '') {
        $errors[] = 'Il cognome è obbligatorio.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'L\'indirizzo email non sembra valido.';
    }

    // Almeno un permesso operativo (admin/circ/catalog/reports) – opzionale ma utile
    if ($adminFlg === 'N' && $circFlg === 'N' && $circMbrFlg === 'N' && $catalogFlg === 'N' && $reportsFlg === 'N') {
        $errors[] = 'Seleziona almeno un permesso operativo per l\'utente staff.';
    }

    // Gestione password a seconda della modalità
    $passwordHash = null;

    if ($mode === 'set') {
        if ($passwordSet === '' || $password2 === '') {
            $errors[] = 'Inserisci la password e la conferma.';
        } elseif ($passwordSet !== $password2) {
            $errors[] = 'La password e la conferma non coincidono.';
        } elseif (mb_strlen($passwordSet) < 8) {
            $errors[] = 'La password deve essere lunga almeno 8 caratteri.';
        } else {
            $passwordHash = password_hash($passwordSet, PASSWORD_DEFAULT);
        }
    } else {
        // mode = invite — password bloccante casuale (accesso solo via link di reset)
        $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }

    // Se finora nessun errore, controlliamo unicità username
    if ($errors === []) {
        try {
            $sqlCheck = 'SELECT COUNT(*) FROM staff WHERE username = :u';
            $stmtC = $pdo->prepare($sqlCheck);
            $stmtC->bindValue(':u', $username, PDO::PARAM_STR);
            $stmtC->execute();
            $cnt = (int)$stmtC->fetchColumn();

            if ($cnt > 0) {
                $errors[] = 'Lo username scelto è già in uso. Scegline un altro.';
            }
        } catch (PDOException $e) {
            $errors[] = 'Errore durante il controllo dello username.';
        }
    }

    // -------------------------------------------------------------------------
    // Insert in staff (e, opzionalmente, in staff_password_reset)
    // -------------------------------------------------------------------------
    if ($errors === [] && $passwordHash !== null) {
        $now = date('Y-m-d H:i:s');
        $creatorId = (int)($_SESSION['staff_user_id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $sqlIns = '
                INSERT INTO staff
                    (create_dt, last_change_dt, last_change_userid,
                     username, email, pwd,
                     last_name, first_name,
                     suspended_flg, admin_flg, circ_flg, circ_mbr_flg, catalog_flg, reports_flg)
                VALUES
                    (:create_dt, :last_change_dt, :last_change_userid,
                     :username, :email, :pwd,
                     :last_name, :first_name,
                     :suspended_flg, :admin_flg, :circ_flg, :circ_mbr_flg, :catalog_flg, :reports_flg)
            ';

            $stmt = $pdo->prepare($sqlIns);
            $stmt->bindValue(':create_dt',        $now, PDO::PARAM_STR);
            $stmt->bindValue(':last_change_dt',   $now, PDO::PARAM_STR);
            $stmt->bindValue(':last_change_userid', $creatorId, PDO::PARAM_INT);
            $stmt->bindValue(':username',         $username, PDO::PARAM_STR);
            $stmt->bindValue(':email',            $email !== '' ? $email : null, $email !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':pwd',              $passwordHash, PDO::PARAM_STR);
            $stmt->bindValue(':last_name',        $lastName, PDO::PARAM_STR);
            $stmt->bindValue(':first_name',       $firstName !== '' ? $firstName : null, $firstName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':suspended_flg',    $suspended, PDO::PARAM_STR);
            $stmt->bindValue(':admin_flg',        $adminFlg, PDO::PARAM_STR);
            $stmt->bindValue(':circ_flg',         $circFlg, PDO::PARAM_STR);
            $stmt->bindValue(':circ_mbr_flg',     $circMbrFlg, PDO::PARAM_STR);
            $stmt->bindValue(':catalog_flg',      $catalogFlg, PDO::PARAM_STR);
            $stmt->bindValue(':reports_flg',      $reportsFlg, PDO::PARAM_STR);

            $stmt->execute();

            $newUserId = (int)$pdo->lastInsertId();

            // Se modalità "invite", creiamo anche un token in staff_password_reset
            if ($mode === 'invite' && $newUserId > 0) {
                $rawToken   = bin2hex(random_bytes(32));
                $tokenHash  = hash('sha256', $rawToken);
                $createdAt  = $now;
                $expiresAt  = date('Y-m-d H:i:s', time() + 86400); // 24 ore

                $sqlReset = '
                    INSERT INTO staff_password_reset (userid, token_hash, created_at, expires_at, used)
                    VALUES (:userid, :token_hash, :created_at, :expires_at, 0)
                ';
                $stmtR = $pdo->prepare($sqlReset);
                $stmtR->bindValue(':userid',     $newUserId, PDO::PARAM_INT);
                $stmtR->bindValue(':token_hash', $tokenHash, PDO::PARAM_STR);
                $stmtR->bindValue(':created_at', $createdAt, PDO::PARAM_STR);
                $stmtR->bindValue(':expires_at', $expiresAt, PDO::PARAM_STR);
                $stmtR->execute();

                // Invia link di impostazione password via email
                $resetUrl = rtrim($baseUrl, '/') . '/index.php?page=staff_reset&token=' . urlencode($rawToken);
                $staffEmailTo = trim((string)($email));
                if ($staffEmailTo !== '' && is_file(dirname(__DIR__) . '/lib/EmailService.php')) {
                    require_once dirname(__DIR__) . '/lib/EmailService.php';
                    $mailer = new EmailService($cfg ?? [], dirname(__DIR__));
                    $mailer->send(
                        $staffEmailTo,
                        'Imposta la tua password — Biblioteca della Resistenza',
                        'staff/reset_password',
                        [
                            'username'  => $username,
                            'resetLink' => $resetUrl,
                        ]
                    );
                    $resetInfo = 'Link di impostazione password inviato a ' . $staffEmailTo . '.';
                } else {
                    $resetInfo = 'Email non configurata. Link di impostazione (da comunicare manualmente): ' . $resetUrl;
                }
            }

            $pdo->commit();

            $successMsg = 'Nuovo account staff creato correttamente.';

            if ($mode === 'set') {
                $successMsg .= ' La password è stata impostata manualmente.';
            } else {
                $successMsg .= ' È stato generato un link di impostazione password.';
            }

            // Pulizia dei campi del form dopo inserimento
            $username    = '';
            $email       = '';
            $firstName   = '';
            $lastName    = '';
            $suspended   = 'N';
            $adminFlg    = 'N';
            $circFlg     = 'Y';
            $circMbrFlg  = 'Y';
            $catalogFlg  = 'Y';
            $reportsFlg  = 'N';
            $mode        = 'invite';
            $passwordSet = '';
            $password2   = '';

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Errore durante la creazione del nuovo account staff.';
        }
    }
}
?>

<section class="page-section page-staff page-staff-user-add">
    <header class="staff-header">
        <div class="staff-header-top">
            <div class="staff-header-main">
                <h1>Nuovo account staff</h1>
                <p class="staff-header-subtitle">
                    Crea un nuovo utente staff con i permessi appropriati per catalogo, circolazione e reportistica.
                </p>
            </div>
        </div>
    </header>

    <?php if ($successMsg !== ''): ?>
        <div class="generic-box" style="border-left:4px solid #0a7;background:#f4f8f4;">
            <p><?= h($successMsg) ?></p>
            <?php if ($resetInfo !== ''): ?>
                <p style="margin-top:0.5rem;font-size:0.9rem;color:#333;">
                    <?= h($resetInfo) ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="generic-box" style="border-left:4px solid #c00;background:#fdf4f4;">
            <?php foreach ($errors as $msg): ?>
                <p><?= h($msg) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="staff-card" style="margin-top:1.5rem;">
        <header class="staff-card-header">
            <div class="staff-card-icon-wrap">
                <span class="staff-card-icon">👤</span>
            </div>
            <div>
                <h2 class="staff-card-title">Dati anagrafici e account</h2>
                <p class="staff-card-subtitle">
                    Compila i dati principali del nuovo operatore e assegna i permessi.
                </p>
            </div>
        </header>

        <form method="post" class="staff-form">
            <div class="staff-form-grid">
                <div class="search-row">
                    <label for="username">Username (obbligatorio)</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        maxlength="20"
                        value="<?= h($username) ?>"
                        required
                    >
                    <p class="search-help" style="font-size:0.85rem;">
                        3–20 caratteri. Lettere, numeri, punto, trattino, underscore.
                    </p>
                </div>

                <div class="search-row">
                    <label for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="191"
                        value="<?= h($email) ?>"
                    >
                </div>

                <div class="search-row">
                    <label for="first_name">Nome</label>
                    <input
                        type="text"
                        id="first_name"
                        name="first_name"
                        maxlength="30"
                        value="<?= h($firstName) ?>"
                    >
                </div>

                <div class="search-row">
                    <label for="last_name">Cognome (obbligatorio)</label>
                    <input
                        type="text"
                        id="last_name"
                        name="last_name"
                        maxlength="30"
                        value="<?= h($lastName) ?>"
                        required
                    >
                </div>

                <div class="search-row">
                    <label>
                        <input
                            type="checkbox"
                            name="suspended"
                            value="Y"
                            <?= $suspended === 'Y' ? 'checked' : '' ?>
                        >
                        Account sospeso
                    </label>
                    <p class="search-help" style="font-size:0.85rem;">
                        Se selezionato l'utente non potrà accedere finché non verrà riattivato.
                    </p>
                </div>
            </div>

            <hr style="margin:1.5rem 0;">

            <h3 style="margin-bottom:0.75rem;">Permessi operativi</h3>
            <div class="staff-form-grid">
                <div class="search-row">
                    <label>
                        <input
                            type="checkbox"
                            name="admin_flg"
                            value="Y"
                            <?= $adminFlg === 'Y' ? 'checked' : '' ?>
                        >
                        Amministratore (admin_flg)
                    </label>
                    <p class="search-help" style="font-size:0.85rem;">
                        Accesso completo alle funzioni di sistema, inclusa gestione staff.
                    </p>
                </div>

                <div class="search-row">
                    <label>
                        <input
                            type="checkbox"
                            name="circ_flg"
                            value="Y"
                            <?= $circFlg === 'Y' ? 'checked' : '' ?>
                        >
                        Circolazione (circ_flg)
                    </label>
                    <p class="search-help" style="font-size:0.85rem;">
                        Gestione dei prestiti e delle restituzioni.
                    </p>
                </div>

                <div class="search-row">
                    <label>
                        <input
                            type="checkbox"
                            name="circ_mbr_flg"
                            value="Y"
                            <?= $circMbrFlg === 'Y' ? 'checked' : '' ?>
                        >
                        Gestione utenti lettori (circ_mbr_flg)
                    </label>
                    <p class="search-help" style="font-size:0.85rem;">
                        Creazione e modifica delle anagrafiche dei lettori.
                    </p>
                </div>

                <div class="search-row">
                    <label>
                        <input
                            type="checkbox"
                            name="catalog_flg"
                            value="Y"
                            <?= $catalogFlg === 'Y' ? 'checked' : '' ?>
                        >
                        Catalogazione (catalog_flg)
                    </label>
                    <p class="search-help" style="font-size:0.85rem;">
                        Inserimento e modifica dei record in catalogo.
                    </p>
                </div>

                <div class="search-row">
                    <label>
                        <input
                            type="checkbox"
                            name="reports_flg"
                            value="Y"
                            <?= $reportsFlg === 'Y' ? 'checked' : '' ?>
                        >
                        Report e statistiche (reports_flg)
                    </label>
                    <p class="search-help" style="font-size:0.85rem;">
                        Accesso ai report e alle statistiche d’uso.
                    </p>
                </div>
            </div>

            <hr style="margin:1.5rem 0;">

            <h3 style="margin-bottom:0.75rem;">Impostazione password</h3>

            <div class="search-row">
                <label>
                    <input
                        type="radio"
                        name="password_mode"
                        value="invite"
                        <?= $mode === 'invite' ? 'checked' : '' ?>
                    >
                    Genera un link di impostazione password
                </label>
                <p class="search-help" style="font-size:0.85rem;">
                    Verrà creato un token nella tabella <code>staff_password_reset</code>.
                    Al momento il link verrà mostrato solo sullo schermo (per test e futura integrazione).
                </p>
            </div>

            <div class="search-row">
                <label>
                    <input
                        type="radio"
                        name="password_mode"
                        value="set"
                        <?= $mode === 'set' ? 'checked' : '' ?>
                    >
                    Imposto io una password provvisoria
                </label>
            </div>

            <div class="staff-form-grid">
                <div class="search-row">
                    <label for="password">Password (se impostata manualmente)</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="new-password"
                    >
                </div>

                <div class="search-row">
                    <label for="password_confirm">Conferma password</label>
                    <input
                        type="password"
                        id="password_confirm"
                        name="password_confirm"
                        autocomplete="new-password"
                    >
                    <p class="search-help" style="font-size:0.85rem;">
                        Minimo 8 caratteri. Usata solo se la modalità sopra è "Imposto io".
                    </p>
                </div>
            </div>

            <div class="search-actions" style="margin-top:1.5rem;">
                <button type="submit" class="btn-primary">
                    Crea account staff
                </button>
                <a href="<?= h($baseUrl) ?>/index.php?page=staff" class="btn-link">
                    Torna alla dashboard staff
                </a>
            </div>
        </form>
    </div>
</section>
