<?php
declare(strict_types=1);

/**
 * Richiesta reset password staff.
 *
 * - Input: username OPPURE email
 * - Crea un token monouso in tabella staff_password_reset
 * - Per ora mostra il link di reset a video (modalità test)
 */

$pdo = DB::conn();
$baseUrl = function_exists('base_url') ? base_url() : '';

$info    = '';
$errors  = [];
$ident   = ''; // username o email inserito

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ident = trim((string)($_POST['ident'] ?? ''));

    if ($ident === '') {
        $errors[] = 'Inserisci username o indirizzo email.';
    } else {
        try {
            // Cerco lo staff sia per username che per email
            $sql = '
                SELECT userid, username, email
                FROM staff
                WHERE username = :ident OR email = :ident
                LIMIT 1
            ';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':ident', $ident, PDO::PARAM_STR);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Messaggio sempre "neutro"
            $info = 'Se i dati inseriti sono corretti, riceverai un link per reimpostare la password.';

            if ($user !== false) {
                $userid = (int)$user['userid'];

                // Invalido eventuali token precedenti non usati per questo utente
                $pdo->prepare(
                    'UPDATE staff_password_reset
                     SET used = 1
                     WHERE userid = :uid AND used = 0'
                )->execute([':uid' => $userid]);

                // Genero nuovo token e suo hash
                $token     = bin2hex(random_bytes(32));           // 64 caratteri
                $tokenHash = hash('sha256', $token);

                $now      = new DateTimeImmutable('now');
                $expires  = $now->add(new DateInterval('PT1H'));  // valido 1 ora

                $sqlIns = '
                    INSERT INTO staff_password_reset
                        (userid, token_hash, created_at, expires_at, used)
                    VALUES
                        (:uid, :thash, :created, :expires, 0)
                ';
                $stmtIns = $pdo->prepare($sqlIns);
                $stmtIns->execute([
                    ':uid'     => $userid,
                    ':thash'   => $tokenHash,
                    ':created' => $now->format('Y-m-d H:i:s'),
                    ':expires' => $expires->format('Y-m-d H:i:s'),
                ]);

                // Link di reset (per ora solo a video, in futuro via email)
                $resetLink = rtrim($baseUrl, '/') .
                    '/index.php?page=staff_reset&token=' . urlencode($token);

                // Sovrascrivo $info con la versione "test" che mostra il link
                $info = 'Link di reset (solo per test, da NON lasciare in produzione):<br>'
                      . '<a href="' . h($resetLink) . '">' . h($resetLink) . '</a>';
            }
        } catch (Throwable $e) {
            $errors[] = 'Si è verificato un errore durante la richiesta di reset.';
        }
    }
}
?>
<section class="page-section">
    <div class="auth-box">
        <h1>Recupero password staff</h1>

        <p>
            Inserisci il tuo <strong>username</strong> oppure l’indirizzo
            <strong>email</strong> associato all’account staff. Verrà generato
            un link per reimpostare la password.
        </p>

        <?php if ($info !== ''): ?>
            <div class="generic-box" style="margin-top:0.75rem;">
                <p><?= $info ?></p>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="generic-box" style="margin-top:0.75rem;">
                <?php foreach ($errors as $msg): ?>
                    <p><?= h($msg) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_forgot">
            <div class="search-row">
                <label for="ident">Username o email</label>
                <input
                    type="text"
                    id="ident"
                    name="ident"
                    value="<?= h($ident) ?>"
                    required
                >
            </div>

            <div class="search-actions">
                <button type="submit" class="btn-primary">
                    Genera link di reset
                </button>
                <a class="btn-link" href="<?= h($baseUrl) ?>/index.php?page=login">
                    Torna al login
                </a>
            </div>
        </form>
    </div>
</section>
