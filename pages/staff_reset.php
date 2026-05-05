<?php
declare(strict_types=1);

/**
 * Pagina di reset password staff a partire da un token.
 *
 * URL: index.php?page=staff_reset&token=...
 */

$pdo     = DB::conn();
$baseUrl = function_exists('base_url') ? base_url() : '';

$tokenRaw = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$errors   = [];
$info     = '';
$showForm = true;

if ($tokenRaw === '') {
    $errors[] = 'Token di reset mancante o non valido.';
    $showForm = false;
} else {
    $tokenHash = hash('sha256', $tokenRaw);

    // Se arrivo in POST, sto provando a impostare la nuova password
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pwd1 = (string)($_POST['password'] ?? '');
        $pwd2 = (string)($_POST['password_confirm'] ?? '');

        if ($pwd1 === '' || $pwd2 === '') {
            $errors[] = 'Inserisci e conferma la nuova password.';
        } elseif ($pwd1 !== $pwd2) {
            $errors[] = 'Le password non coincidono.';
        } elseif (strlen($pwd1) < 8) {
            $errors[] = 'La password deve contenere almeno 8 caratteri.';
        }

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                $sql = '
                    SELECT r.id, r.userid, r.expires_at, r.used, s.username
                    FROM staff_password_reset r
                    JOIN staff s ON s.userid = r.userid
                    WHERE r.token_hash = :thash
                      AND r.used = 0
                      AND r.expires_at >= NOW()
                    LIMIT 1
                ';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':thash' => $tokenHash]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row === false) {
                    $pdo->rollBack();
                    $errors[] = 'Token non valido o scaduto. Richiedi un nuovo reset.';
                    $showForm = false;
                } else {
                    $userid  = (int)$row['userid'];
                    $resetId = (int)$row['id'];

                    // Aggiorno la password staff (MD5 per compatibilità)
                    $newHash = md5($pwd1);

                    $sqlUpdStaff = '
                        UPDATE staff
                        SET pwd = :pwd,
                            last_change_dt    = NOW(),
                            last_change_userid = :uid
                        WHERE userid = :uid
                    ';
                    $stmtUpd = $pdo->prepare($sqlUpdStaff);
                    $stmtUpd->execute([
                        ':pwd' => $newHash,
                        ':uid' => $userid,
                    ]);

                    // Segno il token come usato
                    $sqlUpdReset = '
                        UPDATE staff_password_reset
                        SET used = 1
                        WHERE id = :id
                    ';
                    $pdo->prepare($sqlUpdReset)->execute([':id' => $resetId]);

                    $pdo->commit();

                    $info     = 'Password aggiornata correttamente. Ora puoi accedere con le nuove credenziali.';
                    $showForm = false;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Si è verificato un errore durante l\'aggiornamento della password.';
            }
        }
    } else {
        // GET: controllo preventivo che il token sia valido prima di mostrare il form
        try {
            $sql = '
                SELECT r.id
                FROM staff_password_reset r
                WHERE r.token_hash = :thash
                  AND r.used = 0
                  AND r.expires_at >= NOW()
                LIMIT 1
            ';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':thash' => $tokenHash]);
            if ($stmt->fetch(PDO::FETCH_ASSOC) === false) {
                $errors[] = 'Token non valido o scaduto. Richiedi un nuovo reset.';
                $showForm = false;
            }
        } catch (Throwable $e) {
            $errors[] = 'Si è verificato un errore durante la verifica del token.';
            $showForm = false;
        }
    }
}
?>
<section class="page-section">
    <div class="auth-box">
        <h1>Imposta una nuova password</h1>

        <?php if ($info !== ''): ?>
            <div class="generic-box" style="margin-top:0.75rem;">
                <p><?= h($info) ?></p>
                <p>
                    <a class="btn-primary" href="<?= h($baseUrl) ?>/index.php?page=login">
                        Vai al login staff
                    </a>
                </p>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="generic-box" style="margin-top:0.75rem;">
                <?php foreach ($errors as $msg): ?>
                    <p><?= h($msg) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($showForm && $errors === []): ?>
            <p>
                Scegli una nuova password per il tuo account staff. Dopo il salvataggio
                potrai accedere normalmente dall’apposita pagina di login.
            </p>

            <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_reset">
                <input type="hidden" name="token" value="<?= h($tokenRaw) ?>">

                <div class="search-row">
                    <label for="pwd1">Nuova password</label>
                    <input
                        type="password"
                        id="pwd1"
                        name="password"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="search-row">
                    <label for="pwd2">Conferma password</label>
                    <input
                        type="password"
                        id="pwd2"
                        name="password_confirm"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="search-actions">
                    <button type="submit" class="btn-primary">
                        Salva nuova password
                    </button>
                    <a class="btn-link" href="<?= h($baseUrl) ?>/index.php?page=login">
                        Annulla
                    </a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>
