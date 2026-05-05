<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$to = $_GET['to'] ?? '';
if ($to === '') {
  echo "Uso: mail_reset_like_test.php?to=you@gmail.com\n";
  exit;
}

$base = 'https://biblioteca.anpiudine.org/public';
$link = $base . '/index.php?page=patron_reset&token=TESTTOKEN';

$mailFrom = 'biblioteca@anpiudine.org';
$mailFromName = 'Biblioteca della Resistenza';
$fromHeader = $mailFromName . ' <' . $mailFrom . '>';

$subject = 'Reimposta la password - Biblioteca della Resistenza';
$body =
  "Hai richiesto la reimpostazione della password.\n\n" .
  "Apri questo link per impostare una nuova password:\n" .
  $link . "\n\n" .
  "Il link è valido per 1 ora.\n" .
  "Se non sei stato tu, ignora questa email.\n";

$headers = implode("\r\n", [
  'MIME-Version: 1.0',
  'Content-Type: text/plain; charset=UTF-8',
  'From: ' . $fromHeader,
  'Reply-To: ' . $mailFrom,
  'Date: ' . date('r'),
  'Message-ID: <' . bin2hex(random_bytes(16)) . '@anpiudine.org>',
  'X-Mailer: PHP/' . PHP_VERSION,
]);

$sent = @mail($to, $subject, $body, $headers, '-f ' . $mailFrom);

echo $sent ? "mail(): TRUE\n" : "mail(): FALSE\n";
