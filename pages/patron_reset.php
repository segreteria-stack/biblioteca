<?php
$title = 'Reimposta password';

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

if ($token === '') {
    $err = 'Link non valido. Richiedi un nuovo link di recupero.';
} elseif (!($db instanceof PDO)) {
    $err = 'Errore di configurazione: database non disponibile.';
} else {
    // Verifica subito che il token esista e non sia scaduto (anche in GET)
    $stCheck = $db->prepare("SELECT mbrid, reset_expires FROM patron_auth WHERE reset_token=? LIMIT 1");
    $stCheck->execute([$token]);
    $rowCheck = $stCheck->fetch(PDO::FETCH_ASSOC);

    if (!$rowCheck) {
        $err = 'Link non valido o già utilizzato.';
    } else {
        $exp = (string)($rowCheck['reset_expires'] ?? '');
        // FIX: reset_expires deve esistere e non essere scaduto
        if ($exp === '' || strtotime($exp) === false || strtotime($exp) < time()) {
            $err = 'Il link è scaduto. Richiedi un nuovo link di recupero.';
            // Pulizia token scaduto
            $db->prepare("UPDATE patron_auth SET reset_token=NULL, reset_expires=NULL WHERE reset_token=?")
               ->execute([$token]);
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
                    $up->execute([$hash, (int)$rowCheck['mbrid'], $token]);

                    if ($up->rowCount() < 1) {
                        $err = 'Impossibile aggiornare la password. Richiedi un nuovo link.';
                    } else {
                        $ok    = 'Password aggiornata. Ora puoi accedere.';
                        $token = '';
                    }
                }
            }
        }
    }
}
?>
<section class="card" style="margin-top:20px;max-width:520px;padding:18px;border:1px solid #eee;border-radius:10px;background:#fff">
  <h1 style="margin:0 0 12px 0">Reimposta password</h1>

  <?php if ($ok): ?>
    <p style="color:#0a7"><?= h($ok) ?></p>
    <p style="margin-top:12px"><a class="button" href="<?= h($base) ?>/index.php?page=patron_login">Vai al login</a></p>

  <?php elseif ($err): ?>
    <p style="color:#b00020"><?= h($err) ?></p>
    <p style="margin-top:12px">
      <a href="<?= h($base) ?>/index.php?page=user_forgot">Richiedi un nuovo link</a>
    </p>

  <?php else: ?>
    <form method="post" style="margin:0">
      <?php if ($hasCsrf): ?>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <?php endif; ?>

      <div style="margin:0 0 16px 0;padding:10px 14px;background:#f8f9fa;border-left:3px solid #b00020;border-radius:4px;font-size:13px;color:#444;line-height:1.8">
        <strong>Requisiti password:</strong><br>
        · almeno 8 caratteri<br>
        · almeno una lettera maiuscola<br>
        · almeno un numero<br>
        · almeno un carattere speciale (es. ! @ # $ % &amp;)
      </div>

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
          Salva password
        </button>
      </div>
    </form>

    <p style="margin-top:12px;font-size:14px">
      <a href="<?= h($base) ?>/index.php?page=user_forgot">Richiedi un nuovo link</a>
    </p>
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
