<?php
declare(strict_types=1);

/**
 * Staff - Test invio email
 *
 * Requisiti:
 * - staff autenticato (SESSION staff_user_id)
 * - EmailService disponibile in /lib/EmailService.php
 * - templates/email/system/test.php presente
 * - config.php valorizza $cfg['mail']
 */

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Protezione staff
if (empty($_SESSION['staff_user_id'])) {
  http_response_code(403);
  exit('Forbidden');
}

// Bootstrap/config: se nel tuo progetto config.php è già incluso globalmente,
// questa parte non crea problemi; serve solo a garantire $cfg.
if (!isset($cfg) || !is_array($cfg)) {
  $cfg = [];
}

// Prova a includere config se non presente (adatta il path se necessario)
$root = dirname(__DIR__);
if (empty($cfg['db']) && is_file($root . '/config.php')) {
  require_once $root . '/config.php';
}

require_once $root . '/lib/EmailService.php';

$info = '';
$err  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $to = trim((string)($_POST['to'] ?? ''));

  if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $err = 'Inserisci un indirizzo email valido.';
  } else {
    $mail = new EmailService($cfg, $root);

    $ok = $mail->send(
      $to,
      'Test email OPAC',
      'system/test',
      ['ts' => date('Y-m-d H:i:s')]
    );

    if ($ok) {
      $info = 'Email inviata a: ' . htmlspecialchars($to, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    } else {
      $err = 'Invio fallito. Possibili cause: mail() disabilitata sul server, From non accettato, configurazione mail mancante.';
    }
  }
}

// Helper h() se non esiste (per coerenza con il resto del progetto)
if (!function_exists('h')) {
  function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

$title = 'Test invio email (Staff)';
?>
<section class="card" style="max-width:720px;margin:20px auto">
  <h1><?= h($title) ?></h1>

  <?php if ($info !== ''): ?>
    <p style="color:#0a7"><?= h($info) ?></p>
  <?php endif; ?>

  <?php if ($err !== ''): ?>
    <p style="color:#b00"><?= h($err) ?></p>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <label>
      Destinatario (email)<br>
      <input class="input" type="email" name="to" required style="width:100%" placeholder="tuoindirizzo@...">
    </label>

    <div style="margin-top:14px">
      <button class="button" type="submit">Invia test</button>
    </div>
  </form>

  <hr style="margin:18px 0">

  <p style="font-size:0.95rem;color:#555">
    Se l’invio fallisce, nel micro-step successivo passiamo a SMTP (PHPMailer) mantenendo invariata la chiamata
    <code>$mail->send(...)</code>.
  </p>
</section>
