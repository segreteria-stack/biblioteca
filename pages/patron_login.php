<?php
declare(strict_types=1);
$title = 'Accesso Patron';

// DB: nel bootstrap la connessione è $pdo; qui usiamo $db per compatibilità
global $db;
if (!($db instanceof PDO) && isset($pdo) && ($pdo instanceof PDO)) {
  $db = $pdo;
}

// $cfg['tables'] può non esistere: in quel caso usiamo array vuoto
$Tcfg = (isset($cfg['tables']) && is_array($cfg['tables'])) ? $cfg['tables'] : [];

$T = $Tcfg + [
  'member'      => 'member',
  'biblio'      => 'biblio',
  'biblio_copy' => 'biblio_copy',
  'patron_auth' => 'patron_auth',
];

require_once __DIR__ . '/../lib/PatronAuth.php';

$err = '';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$hasCsrf = function_exists('csrf_check') && function_exists('csrf_token');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($hasCsrf && !csrf_check($_POST['csrf'] ?? '')) {
    $err = 'Token CSRF non valido';
  } elseif (!recaptcha_verify($_POST['g-recaptcha-response'] ?? '', $cfg['recaptcha']['secret'], $cfg['recaptcha']['threshold'])) {
    $err = 'Verifica antibot non superata. Riprova.';
  } else {
    $ok = PatronAuth::login($db, $T, trim($_POST['login'] ?? ''), trim($_POST['password'] ?? ''));
    if ($ok) {
      $base = rtrim((string)($cfg['app']['base_url'] ?? ''), '/');
      header('Location: ' . $base . '/index.php?page=patron_area');
      exit;
    } else {
      $err = 'Credenziali non valide';
    }
  }
}

$base = rtrim((string)($cfg['app']['base_url'] ?? ''), '/');

// Link registrazione (file già esistente)
$registerUrl = $base . '/index.php?page=user_register';
?>

<section class="card" style="margin-top:20px;max-width:520px;padding:18px;border:1px solid #eee;border-radius:10px;background:#fff">
  <h1 style="margin:0 0 12px 0">Area Patron</h1>

  <?php if ($err): ?>
    <p style="color:#b00020;margin:0 0 10px 0"><?= h($err) ?></p>
  <?php endif; ?>

  <script src="https://www.google.com/recaptcha/api.js?render=<?= h($cfg['recaptcha']['sitekey']) ?>"></script>
  <form id="loginForm" method="post" autocomplete="on" style="margin:0">
    <?php if ($hasCsrf): ?>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php endif; ?>
    <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response-login">

    <label style="display:block;margin:0 0 12px 0">
      <span style="display:block;margin:0 0 6px 0">Barcode o Email</span>
      <input name="login" required autocomplete="username"
             style="display:block;width:100%;padding:10px 12px;border:1px solid #d9d9d9;border-radius:10px">
    </label>

    <label style="display:block;margin:0 0 14px 0">
      <span style="display:block;margin:0 0 6px 0">Password</span>
      <input type="password" name="password" required autocomplete="current-password"
             style="display:block;width:100%;padding:10px 12px;border:1px solid #d9d9d9;border-radius:10px">
    </label>

    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <button type="submit"
              style="display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #d9d9d9;background:var(--btn-primary-bg,#e11e28);color:var(--btn-primary-text,#fff);cursor:pointer">
        Entra
      </button>

      <a class="btn-secondary" href="<?= h($registerUrl) ?>"
         style="display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #d9d9d9;background:#fff;color:#111;text-decoration:none">
        Registrati
      </a>
    </div>

    <p style="margin:12px 0 0 0;font-size:14px">
      <a href="<?= h($base) ?>/index.php?page=user_forgot">Hai perso la password?</a>
    </p>
  </form>

<script>
(function () {
  var form = document.getElementById('loginForm');
  if (!form) return;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    grecaptcha.ready(function () {
      grecaptcha.execute('<?= h($cfg['recaptcha']['sitekey']) ?>', {action: 'login'}).then(function (token) {
        document.getElementById('g-recaptcha-response-login').value = token;
        form.submit();
      });
    });
  });
})();
</script>

  <div style="margin-top:12px;padding-top:12px;border-top:1px solid #eee">
    <p style="margin:0 0 6px 0;color:#64748b;font-size:14px">
      Se non hai ancora un account, puoi registrarti inserendo <strong>numero tessera (mbrid)</strong>, email e password.
    </p>
    <p style="margin:0;color:#64748b;font-size:14px">
      In caso di problemi, chiedi in biblioteca l’attivazione/verifica della tessera.
    </p>
  </div>
</section>
