<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$to = $_GET['to'] ?? '';
if ($to === '') {
  echo "Uso: mail_test.php?to=tuoindirizzo@example.com\n";
  exit;
}

$from = 'biblioteca@anpiudine.org';
$fromName = 'Biblioteca della Resistenza';

$subject = 'TEST mail() - Biblioteca';
$body = "Questo è un messaggio di test inviato con mail() da PHP.\nData: " . date('Y-m-d H:i:s') . "\n";

$headers = implode("\r\n", [
  'MIME-Version: 1.0',
  'Content-Type: text/plain; charset=UTF-8',
  'From: ' . $fromName . ' <' . $from . '>',
  'Reply-To: ' . $from,
  'X-Mailer: PHP/' . PHP_VERSION,
]);

$ok = @mail($to, $subject, $body, $headers, '-f ' . $from);

echo $ok ? "OK: mail() ha restituito TRUE\n" : "ERRORE: mail() ha restituito FALSE\n";
