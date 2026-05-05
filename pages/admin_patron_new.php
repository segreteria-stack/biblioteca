<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    $baseUrl = $cfg['app']['base_url'] ?? '';
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=' . urlencode('admin_patrons'));
    exit;
}

$title   = 'Nuovo utente';
$baseUrl = $cfg['app']['base_url'] ?? '';

global $db;
if (!($db instanceof PDO)) {
    throw new RuntimeException('Connessione DB non disponibile.');
}

require_once __DIR__ . '/../lib/EmailService.php';

$staffId = (int)($_SESSION['staff_user_id'] ?? 0);
$err = '';
$ok  = '';

$csrfToken = static function (): string {
    if (empty($_SESSION['_csrf_staff'])) {
        $_SESSION['_csrf_staff'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['_csrf_staff'];
};
$csrfCheck = static function (?string $token) use ($csrfToken): bool {
    return hash_equals($csrfToken(), (string)$token);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$csrfCheck($_POST['csrf'] ?? null)) {
        $err = 'Token CSRF non valido.';
    } else {
        $firstName     = trim((string)($_POST['first_name'] ?? ''));
        $lastName      = trim((string)($_POST['last_name'] ?? ''));
        $email         = trim((string)($_POST['email'] ?? ''));
        $cel           = trim((string)($_POST['cel'] ?? ''));
        $bornDt        = trim((string)($_POST['born_dt'] ?? ''));
        $codiceFiscale = strtoupper(trim((string)($_POST['codice_fiscale'] ?? '')));
        $indirizzo     = trim((string)($_POST['indirizzo'] ?? ''));
        $civico        = trim((string)($_POST['civico'] ?? ''));
        $cap           = trim((string)($_POST['cap'] ?? ''));
        $citta         = trim((string)($_POST['citta'] ?? ''));
        $provincia     = strtoupper(trim((string)($_POST['provincia'] ?? '')));
        $other         = trim((string)($_POST['other'] ?? ''));
        $sendInvite    = isset($_POST['send_invite']);

        if ($firstName === '' || $lastName === '') {
            $err = 'Nome e cognome sono obbligatori.';
        } elseif ($bornDt === '') {
            $err = 'La data di nascita è obbligatoria.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bornDt)) {
            $err = 'Data di nascita non valida (formato YYYY-MM-DD).';
        } elseif ($codiceFiscale !== '' && !preg_match('/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/', $codiceFiscale)) {
            $err = 'Codice fiscale non valido.';
        } elseif ($email === '' && $sendInvite) {
            $err = 'Per inviare l\'invito è necessaria un\'email valida.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Email non valida.';
        } else {
            if ($email !== '') {
                $chk = $db->prepare('SELECT id FROM patron_auth WHERE email=? LIMIT 1');
                $chk->execute([$email]);
                if ($chk->fetch()) {
                    $err = 'Email già registrata nel sistema.';
                }
            }

            if ($err === '') {
                try {
                    $db->beginTransaction();

                    $tmpBarcode = 'TMP' . bin2hex(random_bytes(6));

                    $ins = $db->prepare("
                        INSERT INTO member
                            (barcode_nmbr, create_dt, last_change_dt, last_change_userid,
                             last_name, first_name, home_phone, work_phone, cel,
                             email, foto, pass_user, born_dt, other, classification, is_active, last_activity_dt,
                             codice_fiscale, indirizzo, civico, cap, citta, provincia)
                        VALUES
                            (?, NOW(), NOW(), ?,
                             ?, ?, '', '', ?,
                             ?, '', '', ?, ?, 1, 'Y', NOW(),
                             ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([
                        $tmpBarcode, $staffId,
                        $lastName, $firstName, $cel,
                        $email, $bornDt, $other,
                        $codiceFiscale, $indirizzo, $civico, $cap, $citta, $provincia,
                    ]);

                    $mbrid   = (int)$db->lastInsertId();
                    $barcode = (string)(1000 + $mbrid);

                    $db->prepare("UPDATE member SET barcode_nmbr=? WHERE mbrid=? LIMIT 1")
                       ->execute([$barcode, $mbrid]);

                    $activateLink = '';
                    if ($email !== '') {
                        $token   = bin2hex(random_bytes(32));
                        $expires = date('Y-m-d H:i:s', time() + 72 * 3600);

                        $db->prepare("
                            INSERT INTO patron_auth (mbrid, email, pass_hash, reset_token, reset_expires, created_at)
                            VALUES (?, ?, '', ?, ?, NOW())
                        ")->execute([$mbrid, $email, $token, $expires]);

                        $publicHost = rtrim((string)($cfg['app']['public_host'] ?? ''), '/');
                        $base       = rtrim((string)($cfg['app']['base_url'] ?? ''), '/');
                        if ($publicHost === '') {
                            $https      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                            $publicHost = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
                        }
                        $activateLink = $publicHost . $base . '/index.php?page=patron_activate&token=' . urlencode($token);

                        if ($sendInvite) {
                            $mail = new EmailService($cfg ?? [], dirname(__DIR__));
                            $mail->send(
                                $email,
                                'Benvenuto alla Biblioteca della Resistenza — Attiva il tuo account',
                                'patron/invite',
                                [
                                    'firstName'    => $firstName,
                                    'lastName'     => $lastName,
                                    'activateLink' => $activateLink,
                                    'expires'      => '72 ore',
                                    'barcode'      => $barcode,
                                ]
                            );
                        }
                    }

                    $db->commit();

                    $ok = 'Utente creato. Tessera: ' . $barcode . '.';
                    if ($activateLink !== '' && $sendInvite) {
                        $ok .= ' Email di invito inviata.';
                    } elseif ($activateLink !== '' && !$sendInvite) {
                        $ok .= ' Link attivazione: <a href="' . h($activateLink) . '">' . h($activateLink) . '</a>';
                    }

                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $err = 'Errore durante la creazione: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<section class="card" style="margin-top:20px;max-width:680px;padding:18px;border:1px solid #eee;border-radius:10px;background:#fff">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
    <h1 style="margin:0;font-size:22px">Nuovo utente</h1>
    <a class="button secondary" href="<?= h($baseUrl) ?>/index.php?page=admin_patrons">← Lista utenti</a>
  </div>

  <?php if ($ok): ?>
    <p style="color:#0a7;margin:0 0 12px 0"><?= $ok ?></p>
    <a class="button" href="<?= h($baseUrl) ?>/index.php?page=admin_patrons">Torna alla lista</a>
  <?php else: ?>

    <?php if ($err): ?>
      <p style="color:#b00020;margin:0 0 12px 0"><?= h($err) ?></p>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrfToken()) ?>">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <label class="apd-field">
          <span style="display:block;margin-bottom:5px;font-size:14px">Nome *</span>
          <input class="input" name="first_name" required value="<?= h((string)($_POST['first_name'] ?? '')) ?>">
        </label>
        <label class="apd-field">
          <span style="display:block;margin-bottom:5px;font-size:14px">Cognome *</span>
          <input class="input" name="last_name" required value="<?= h((string)($_POST['last_name'] ?? '')) ?>">
        </label>
        <label class="apd-field">
          <span style="display:block;margin-bottom:5px;font-size:14px">Codice fiscale</span>
          <input class="input" name="codice_fiscale" maxlength="16" style="text-transform:uppercase"
                 value="<?= h((string)($_POST['codice_fiscale'] ?? '')) ?>">
        </label>
        <label class="apd-field">
          <span style="display:block;margin-bottom:5px;font-size:14px">Data di nascita *</span>
          <input class="input" type="date" name="born_dt" required value="<?= h((string)($_POST['born_dt'] ?? '')) ?>">
        </label>
        <label class="apd-field">
          <span style="display:block;margin-bottom:5px;font-size:14px">Email</span>
          <input class="input" type="email" name="email" value="<?= h((string)($_POST['email'] ?? '')) ?>">
        </label>
        <label class="apd-field">
          <span style="display:block;margin-bottom:5px;font-size:14px">Cellulare</span>
          <input class="input" name="cel" value="<?= h((string)($_POST['cel'] ?? '')) ?>">
        </label>
      </div>

      <div style="display:grid;grid-template-columns:3fr 1fr;gap:12px;margin-top:12px">
        <label class="apd-field">
          <span style="display:block;margin-bottom:5px;font-size:14px">Via/Piazza</span>
          <input class="input" name="indirizzo" value="<?= h((string)($_POST['indirizzo'] ?? '')) ?>">
        </label>
        <label class="apd-field">
          <span style="display:block;margin-bottom:5px;font-size:14px">Civico</span>
          <input class="input" name="civico" value="<?= h((string)($_POST['civico'] ?? '')) ?>">
        </label>
      </div>

      <div style="display:grid;grid-template-columns:1fr 2fr 1fr;gap:12px;margin-top:12px">
        <label class="apd-field">
          <span style="display:block;margin-bottom:5px;font-size:14px">CAP</span>
          <input class="input" name="cap" maxlength="5" value="<?= h((string)($_POST['cap'] ?? '')) ?>">
        </label>
        <label class="apd-field">
          <span style="display:block;margin-bottom:5px;font-size:14px">Città</span>
          <input class="input" name="citta" value="<?= h((string)($_POST['citta'] ?? '')) ?>">
        </label>
        <label class="apd-field">
          <span style="display:block;margin-bottom:5px;font-size:14px">Prov.</span>
          <input class="input" name="provincia" maxlength="2" style="text-transform:uppercase"
                 value="<?= h((string)($_POST['provincia'] ?? '')) ?>">
        </label>
      </div>

      <div style="margin-top:12px">
        <label class="apd-field">
          <span style="display:block;margin-bottom:5px;font-size:14px">Note</span>
          <textarea class="input" name="other" rows="2"><?= h((string)($_POST['other'] ?? '')) ?></textarea>
        </label>
      </div>

      <div style="margin-top:16px;padding-top:14px;border-top:1px solid #eee">
        <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
          <input type="checkbox" name="send_invite" value="1" checked>
          Invia email di invito (l'utente riceverà un link per scegliere la password)
        </label>
        <p style="margin:6px 0 0 0;color:#64748b;font-size:13px">
          Il link di attivazione scade dopo 72 ore. Se non si ha un'email, l'utente potrà attivare l'account in un secondo momento.
        </p>
      </div>

      <div style="margin-top:16px;display:flex;gap:10px">
        <button class="button" type="submit">Crea utente</button>
        <a class="button secondary" href="<?= h($baseUrl) ?>/index.php?page=admin_patrons">Annulla</a>
      </div>
    </form>
  <?php endif; ?>
</section>
