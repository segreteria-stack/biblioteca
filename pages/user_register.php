<?php
declare(strict_types=1);

$title = 'Registrazione Area Utente';

$err = '';
$ok  = '';

global $db, $pdo, $cfg;
if (!($db instanceof PDO) && isset($pdo) && ($pdo instanceof PDO)) {
    $db = $pdo;
}
if (!($db instanceof PDO)) {
    $err = 'Connessione al database non disponibile.';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$hasCsrf = function_exists('csrf_check') && function_exists('csrf_token');

require_once __DIR__ . '/../lib/PatronAuth.php';

$base    = rtrim((string)($cfg['app']['base_url'] ?? ''), '/');
$logFile = '/tmp/patron_register_error.log';

if ($err === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($hasCsrf && !csrf_check($_POST['csrf'] ?? '')) {
        $err = 'Token CSRF non valido.';
    } else {
        $firstName      = trim((string)($_POST['first_name'] ?? ''));
        $lastName       = trim((string)($_POST['last_name'] ?? ''));
        $email          = trim((string)($_POST['email'] ?? ''));
        $cel            = trim((string)($_POST['cel'] ?? ''));
        $bornDtRaw      = trim((string)($_POST['born_dt'] ?? ''));
        $codiceFiscale  = strtoupper(trim((string)($_POST['codice_fiscale'] ?? '')));
        $indirizzo      = trim((string)($_POST['indirizzo'] ?? ''));
        $civico         = trim((string)($_POST['civico'] ?? ''));
        $cap            = trim((string)($_POST['cap'] ?? ''));
        $citta          = trim((string)($_POST['citta'] ?? ''));
        $provincia      = strtoupper(trim((string)($_POST['provincia'] ?? '')));
        $other          = trim((string)($_POST['other'] ?? ''));
        $password       = (string)($_POST['password'] ?? '');
        $password2      = (string)($_POST['password2'] ?? '');

        if ($firstName === '' || $lastName === '' || $email === '' || $bornDtRaw === '' || $codiceFiscale === '' || $password === '') {
            $err = 'Compila tutti i campi obbligatori.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Email non valida.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bornDtRaw)) {
            $err = 'Data di nascita non valida.';
        } elseif (!preg_match('/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/', $codiceFiscale)) {
            $err = 'Codice fiscale non valido.';
        } elseif ($password !== $password2) {
            $err = 'Le password non coincidono.';
        } else {
            $pwErr = PatronAuth::validatePassword($password);
            if ($pwErr !== null) {
                $err = $pwErr;
            } else {
                try {
                    $chk = $db->prepare('SELECT id FROM patron_auth WHERE email = ? LIMIT 1');
                    $chk->execute([$email]);
                    if ($chk->fetch()) {
                        $err = 'Email già registrata. Prova ad accedere o recuperare la password.';
                    }
                } catch (Throwable $e) {
                    $err = 'Errore durante la verifica email.';
                }
            }
        }

        if ($err === '') {
            try {
                $db->beginTransaction();

                $tmpBarcode     = 'TMP' . bin2hex(random_bytes(6));
                $passUserMd5    = md5('');
                $classification = 1;

                $insMember = $db->prepare("
                    INSERT INTO member
                        (barcode_nmbr, create_dt, last_change_dt, last_change_userid,
                         last_name, first_name, address, home_phone, work_phone, cel,
                         email, foto, pass_user, born_dt, other, classification, is_active, last_activity_dt,
                         codice_fiscale, indirizzo, civico, cap, citta, provincia)
                    VALUES
                        (?, NOW(), NOW(), 0,
                         ?, ?, '', '', '', ?,
                         ?, '', ?, ?, ?, 1, 'Y', NOW(),
                         ?, ?, ?, ?, ?, ?)
                ");
                $insMember->execute([
                    $tmpBarcode,
                    $lastName, $firstName, $cel,
                    $email, $passUserMd5, $bornDtRaw, $other,
                    $codiceFiscale, $indirizzo, $civico, $cap, $citta, $provincia,
                ]);

                $mbrid   = (int)$db->lastInsertId();
                $barcode = (string)(1000 + $mbrid);

                $db->prepare("UPDATE member SET barcode_nmbr = ? WHERE mbrid = ? LIMIT 1")
                   ->execute([$barcode, $mbrid]);

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $db->prepare("INSERT INTO patron_auth (mbrid, email, pass_hash, created_at) VALUES (?, ?, ?, NOW())")
                   ->execute([$mbrid, $email, $hash]);

                try {
                    $db->prepare("INSERT INTO member_fields (mbrid, code, data) VALUES (?, 'Non socio', '')")
                       ->execute([$mbrid]);
                } catch (Throwable $e) {
                    // non blocchiamo
                }

                $db->commit();

                // Notifica staff
                require_once __DIR__ . '/../lib/EmailService.php';
                $mail    = new EmailService($cfg, dirname(__DIR__));
                $staffTo = trim((string)($cfg['mail']['staff_email'] ?? ''));
                if ($staffTo !== '') {
                    $adminLink = '';
                    if (!empty($cfg['app']['public_host'])) {
                        $adminLink = rtrim((string)$cfg['app']['public_host'], '/') .
                            rtrim((string)($cfg['app']['base_url'] ?? ''), '/') .
                            '/index.php?page=staff_users';
                    }
                    $mail->send($staffTo, 'Nuova registrazione utente - Biblioteca della Resistenza', 'staff/new_patron', [
                        'name'       => trim($firstName . ' ' . $lastName),
                        'email'      => $email,
                        'mbrid'      => (string)$mbrid,
                        'created_at' => date('Y-m-d H:i:s'),
                        'ip'         => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                        'adminLink'  => $adminLink,
                    ]);
                }

                $ok = 'Registrazione completata. Ora puoi accedere. Numero tessera: ' . $barcode . '.';

            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] register_fail email=' . $email . ' err=' . $e->getMessage() . "\n", FILE_APPEND);
                $err = 'Errore durante la registrazione. Riprova più tardi.';
            }
        }
    }
}
?>
<section class="card" style="margin-top:20px;max-width:600px;padding:18px;border:1px solid #eee;border-radius:10px;background:#fff">
  <h1 style="margin:0 0 12px 0">Registrazione Area Utente</h1>

  <?php if ($ok): ?>
    <p style="color:#0a7;margin:0 0 10px 0"><?= h($ok) ?></p>
    <a class="button" href="<?= h($base) ?>/index.php?page=patron_login">Vai al login</a>
  <?php else: ?>

  <?php if ($err): ?>
    <p style="color:#b00020;margin:0 0 10px 0"><?= h($err) ?></p>
  <?php endif; ?>

  <form method="post" autocomplete="on" style="margin:0">
    <?php if ($hasCsrf): ?>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <label style="display:block">
        <span style="display:block;margin:0 0 6px 0">Nome *</span>
        <input class="input" name="first_name" required value="<?= h((string)($_POST['first_name'] ?? '')) ?>">
      </label>
      <label style="display:block">
        <span style="display:block;margin:0 0 6px 0">Cognome *</span>
        <input class="input" name="last_name" required value="<?= h((string)($_POST['last_name'] ?? '')) ?>">
      </label>
    </div>

    <label style="display:block;margin:12px 0 0 0">
      <span style="display:block;margin:0 0 6px 0">Codice fiscale *</span>
      <input class="input" name="codice_fiscale" required maxlength="16"
             style="text-transform:uppercase"
             value="<?= h((string)($_POST['codice_fiscale'] ?? '')) ?>">
    </label>

    <label style="display:block;margin:12px 0 0 0">
      <span style="display:block;margin:0 0 6px 0">Email *</span>
      <input class="input" name="email" type="email" required autocomplete="email"
             value="<?= h((string)($_POST['email'] ?? '')) ?>">
    </label>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px">
      <label style="display:block">
        <span style="display:block;margin:0 0 6px 0">Cellulare</span>
        <input class="input" name="cel" value="<?= h((string)($_POST['cel'] ?? '')) ?>">
      </label>
      <label style="display:block">
        <span style="display:block;margin:0 0 6px 0">Data di nascita *</span>
        <input class="input" name="born_dt" type="date" required value="<?= h((string)($_POST['born_dt'] ?? '')) ?>">
      </label>
    </div>

    <div style="display:grid;grid-template-columns:3fr 1fr;gap:12px;margin-top:12px">
      <label style="display:block">
        <span style="display:block;margin:0 0 6px 0">Via/Piazza</span>
        <input class="input" name="indirizzo" value="<?= h((string)($_POST['indirizzo'] ?? '')) ?>">
      </label>
      <label style="display:block">
        <span style="display:block;margin:0 0 6px 0">Civico</span>
        <input class="input" name="civico" value="<?= h((string)($_POST['civico'] ?? '')) ?>">
      </label>
    </div>

    <div style="display:grid;grid-template-columns:1fr 2fr 1fr;gap:12px;margin-top:12px">
      <label style="display:block">
        <span style="display:block;margin:0 0 6px 0">CAP</span>
        <input class="input" name="cap" maxlength="5" value="<?= h((string)($_POST['cap'] ?? '')) ?>">
      </label>
      <label style="display:block">
        <span style="display:block;margin:0 0 6px 0">Città</span>
        <input class="input" name="citta" value="<?= h((string)($_POST['citta'] ?? '')) ?>">
      </label>
      <label style="display:block">
        <span style="display:block;margin:0 0 6px 0">Prov.</span>
        <input class="input" name="provincia" maxlength="2" style="text-transform:uppercase"
               value="<?= h((string)($_POST['provincia'] ?? '')) ?>">
      </label>
    </div>

    <label style="display:block;margin:12px 0 0 0">
      <span style="display:block;margin:0 0 6px 0">Note</span>
      <input class="input" name="other" value="<?= h((string)($_POST['other'] ?? '')) ?>">
    </label>

    <div style="margin:16px 0 4px 0;padding:10px 14px;background:#f8f9fa;border-left:3px solid #b00020;border-radius:4px;font-size:13px;color:#444;line-height:1.8">
      <strong>Requisiti password:</strong><br>
      · almeno 8 caratteri<br>
      · almeno una lettera maiuscola<br>
      · almeno un numero<br>
      · almeno un carattere speciale (es. ! @ # $ % &amp;)
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:4px">
      <label style="display:block">
        <span style="display:block;margin:0 0 6px 0">Password *</span>
        <input id="pw1" class="input" type="password" name="password" required autocomplete="new-password">
      </label>
      <label style="display:block">
        <span style="display:block;margin:0 0 6px 0">Conferma password *</span>
        <input id="pw2" class="input" type="password" name="password2" required autocomplete="new-password">
      </label>
    </div>

    <label style="display:inline-flex;align-items:center;gap:8px;margin-top:10px;font-size:14px;color:#475569">
      <input type="checkbox" id="pwToggle">
      Mostra password
    </label>

    <div style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <button type="submit"
              style="display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #d9d9d9;background:var(--btn-primary-bg,#e11e28);color:var(--btn-primary-text,#fff);cursor:pointer">
        Crea account
      </button>
      <a class="btn-secondary" href="<?= h($base) ?>/index.php?page=patron_login">
        Hai già un account? Accedi
      </a>
    </div>

    <p style="margin:12px 0 0 0;color:#64748b;font-size:14px">
      I campi contrassegnati con * sono obbligatori.
    </p>
  </form>
  <?php endif; ?>
</section>

<script>
(function () {
  var t  = document.getElementById('pwToggle');
  var p1 = document.getElementById('pw1');
  var p2 = document.getElementById('pw2');
  if (!t || !p1 || !p2) return;
  t.addEventListener('change', function () {
    var type = t.checked ? 'text' : 'password';
    p1.type = type;
    p2.type = type;
  });
})();
</script>
