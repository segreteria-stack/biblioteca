<?php
$title = 'Recupero password';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $db;
if (!($db instanceof PDO) && isset($pdo) && ($pdo instanceof PDO)) {
    $db = $pdo;
}

$info = '';
$err  = '';

$base    = rtrim((string)($cfg['app']['base_url'] ?? ''), '/');
$hasCsrf = function_exists('csrf_check') && function_exists('csrf_token');

$buildPublicBase = function () use ($cfg, $base): string {
    $publicHost = (string)($cfg['app']['public_host'] ?? '');
    if ($publicHost !== '') {
        return rtrim($publicHost, '/') . $base;
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') return $base;
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    return $scheme . '://' . $host . $base;
};

require_once __DIR__ . '/../lib/RateLimit.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($hasCsrf && !csrf_check($_POST['csrf'] ?? '')) {
        $err = 'Token CSRF non valido.';
    } elseif ($db instanceof PDO && !RateLimit::check($db, 'patron_forgot', RateLimit::clientIp(), 5, 600)) {
        $info = 'Se l\'email è registrata, riceverai a breve un link per reimpostare la password.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));

        if ($email === '') {
            $err = 'Inserisci un indirizzo email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Indirizzo email non valido.';
        } else {
            // Messaggio neutro sempre (anti-enumeration)
            $info = 'Se l\'email è registrata, riceverai a breve un link per reimpostare la password.';

            if ($db instanceof PDO) {
                $st = $db->prepare("SELECT mbrid FROM patron_auth WHERE email=? LIMIT 1");
                $st->execute([$email]);
                $row = $st->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $tok = bin2hex(random_bytes(32));
                    $exp = date('Y-m-d H:i:s', time() + 3600);

                    $db->prepare(
                        "UPDATE patron_auth SET reset_token=?, reset_expires=? WHERE email=?"
                    )->execute([$tok, $exp, $email]);

                    $publicBase = $buildPublicBase();
                    $resetLink  = $publicBase . '/index.php?page=patron_reset&token=' . urlencode($tok);

                    $root = dirname(__DIR__);
                    if (is_file($root . '/lib/EmailService.php')) {
                        require_once $root . '/lib/EmailService.php';
                        $mail = new EmailService($cfg ?? [], $root);
                        $mail->send(
                            $email,
                            'Reimposta la password - Biblioteca della Resistenza',
                            'patron/reset_password',
                            [
                                'resetLink' => $resetLink,
                                'expires'   => '1 ora',
                            ]
                        );
                    }

                    // Log diagnostico su file (mai a video)
                    $logFile = defined('ROOT') ? rtrim(ROOT, '/') . '/tmp/patron_mail_debug.log' : '/tmp/patron_mail_debug.log';
                    @file_put_contents(
                        $logFile,
                        sprintf("[%s] patron_forgot to=%s reset=%s\n", date('Y-m-d H:i:s'), $email, $resetLink),
                        FILE_APPEND
                    );
                }
            }
        }
    }
}
?>
<section class="card" style="margin-top:20px;max-width:520px;padding:18px;border:1px solid #eee;border-radius:10px;background:#fff">
  <h1 style="margin:0 0 12px 0">Recupero password</h1>

  <?php if ($info): ?>
    <p style="color:#0a7;margin:0 0 10px 0"><?= h($info) ?></p>
  <?php endif; ?>
  <?php if ($err): ?>
    <p style="color:#b00020;margin:0 0 10px 0"><?= h($err) ?></p>
  <?php endif; ?>

  <?php if (!$info): ?>
  <form method="post" style="margin:0">
    <?php if ($hasCsrf): ?>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php endif; ?>

    <label style="display:block;margin:0 0 14px 0">
      <span style="display:block;margin:0 0 6px 0">Email</span>
      <input class="input" type="email" name="email" required autocomplete="email"
             style="display:block;width:100%;padding:10px 12px;border:1px solid #d9d9d9;border-radius:10px">
    </label>

    <button type="submit"
            style="display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #d9d9d9;background:var(--btn-primary-bg,#e11e28);color:var(--btn-primary-text,#fff);cursor:pointer">
      Invia link
    </button>
  </form>
  <?php endif; ?>

  <p style="margin:12px 0 0 0;font-size:14px">
    <a href="<?= h($base) ?>/index.php?page=patron_login">Torna al login</a>
  </p>
</section>
