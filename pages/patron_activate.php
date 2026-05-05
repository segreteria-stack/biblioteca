<?php
$title = 'Attiva il tuo account';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $db;
if (!($db instanceof PDO) && isset($pdo) && ($pdo instanceof PDO)) {
    $db = $pdo;
}

require_once __DIR__ . '/../lib/PatronAuth.php';

$base    = rtrim((string)($cfg['app']['base_url'] ?? ''), '/');
$hasCsrf = function_exists('csrf_check') && function_exists('csrf_token');

$token = trim((string)($_GET['token'] ?? ''));
$err   = '';
$ok    = '';
$memberName = '';

if ($token === '') {
    $err = 'Link non valido.';
} elseif (!($db instanceof PDO)) {
    $err = 'Errore di configurazione: database non disponibile.';
} else {
    // Verifica token: deve esistere, non essere scaduto, e pass_hash deve essere vuoto (non ancora attivato)
    $st = $db->prepare("
        SELECT pa.mbrid, pa.reset_expires, pa.pass_hash,
               m.first_name, m.last_name
        FROM patron_auth pa
        JOIN member m ON m.mbrid = pa.mbrid
        WHERE pa.reset_token = ?
        LIMIT 1
    ");
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $err = 'Link non valido o già utilizzato.';
    } else {
        $exp = (string)($row['reset_expires'] ?? '');
        if ($exp === '' || strtotime($exp) === false || strtotime($exp) < time()) {
            $err = 'Il link è scaduto. Contatta la biblioteca per ricevere un nuovo invito.';
            $db->prepare("UPDATE patron_auth SET reset_token=NULL, reset_expires=NULL WHERE reset_token=?")
               ->execute([$token]);
        } elseif (!empty($row['pass_hash'])) {
            $err = 'Questo account è già stato attivato. Puoi accedere normalmente.';
        } else {
            $memberName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        }
    }

    if ($err === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($hasCsrf && !csrf_check($_POST['csrf'] ?? '')) {
            $err = 'Token CSRF non valido.';
        } else {
            $p1 = (string)($_POST['password'] ?? '');
            $p2 = (string)($_POST['password2'] ?? '');

            if ($p1 === '' || $p2 === '') {
                $err = 'Compila entrambi i campi.';
            } elseif ($p1 !== $p2) {
                $err = 'Le password non coincidono.';
            } else {
                $pwErr = PatronAuth::validatePassword($p1);
                if ($pwErr !== null) {
                    $err = $pwErr;
                } else {
                    $hash = password_hash($p1, PASSWORD_DEFAULT);
                    $up   = $db->prepare("
                        UPDATE patron_auth
                        SET pass_hash=?, reset_token=NULL, reset_expires=NULL
                        WHERE mbrid=? AND reset_token=?
                    ");
                    $up->execute([$hash, (int)$row['mbrid'], $token]);

                    if ($up->rowCount() < 1) {
                        $err = 'Errore durante l\'attivazione. Contatta la biblioteca.';
                    } else {
                        $ok    = 'Account attivato. Ora puoi accedere.';
                        $token = '';
                    }
                }
            }
        }
    }
}
?>
<section class="card" style="margin-top:20px;max-width:520px;padding:18px;border:1px solid #eee;border-radius:10px;background:#fff">
  <h1 style="margin:0 0 12px 0">Attiva il tuo account</h1>

  <?php if ($ok): ?>
    <p style="color:#0a7"><?= h($ok) ?></p>
    <p style="margin-top:12px">
      <a class="button" href="<?= h($base) ?>/index.php?page=patron_login">Vai al login</a>
    </p>

  <?php elseif ($err): ?>
    <p style="color:#b00020"><?= h($err) ?></p>
    <p style="margin-top:12px;font-size:14px">
      Per assistenza contatta la biblioteca.
    </p>

  <?php else: ?>
    <?php if ($memberName !== ''): ?>
      <p style="margin:0 0 14px 0;color:#444">Benvenuto, <strong><?= h($memberName) ?></strong>! Scegli una password per accedere all'Area Utente.</p>
    <?php endif; ?>

    <div style="margin:0 0 16px 0;padding:10px 14px;background:#f8f9fa;border-left:3px solid #b00020;border-radius:4px;font-size:13px;color:#444;line-height:1.8">
      <strong>Requisiti password:</strong><br>
      · almeno 8 caratteri<br>
      · almeno una lettera maiuscola<br>
      · almeno un numero<br>
      · almeno un carattere speciale (es. ! @ # $ % &amp;)
    </div>

    <?php if ($err): ?>
      <p style="color:#b00020;margin:0 0 10px 0"><?= h($err) ?></p>
    <?php endif; ?>

    <form method="post" style="margin:0">
      <?php if ($hasCsrf): ?>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <?php endif; ?>

      <label style="display:block;margin:0 0 12px 0">
        <span style="display:block;margin:0 0 6px 0">Nuova password</span>
        <input id="pw1" type="password" name="password" required autocomplete="new-password"
               style="display:block;width:100%;padding:10px 12px;border:1px solid #d9d9d9;border-radius:10px">
      </label>

      <label style="display:block;margin:0 0 14px 0">
        <span style="display:block;margin:0 0 6px 0">Conferma password</span>
        <input id="pw2" type="password" name="password2" required autocomplete="new-password"
               style="display:block;width:100%;padding:10px 12px;border:1px solid #d9d9d9;border-radius:10px">
      </label>

      <label style="display:inline-flex;align-items:center;gap:8px;margin-bottom:14px;font-size:14px;color:#475569">
        <input type="checkbox" id="pwToggle">
        Mostra password
      </label>

      <div>
        <button type="submit"
                style="display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #d9d9d9;background:var(--btn-primary-bg,#e11e28);color:var(--btn-primary-text,#fff);cursor:pointer">
          Attiva account
        </button>
      </div>
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
