<?php
// public/debug.php — mostra errori e prova connessione DB
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>Debug OPAC PHP</h1>";

try {
  $cfg = require __DIR__ . '/../config.php';
  echo "<p>✅ config.php caricato</p>";
} catch (Throwable $e) {
  echo "<p>❌ config.php: " . htmlspecialchars($e->getMessage(), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') . "</p>";
  exit;
}

try {
  require __DIR__ . '/../lib/DB.php';
  $db = DB::conn($cfg['db']);
  echo "<p>✅ Connessione DB OK</p>";
} catch (Throwable $e) {
  echo "<p>❌ Connessione DB: " . htmlspecialchars($e->getMessage(), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') . "</p>";
  exit;
}

$checks = [
  'PHP_VERSION' => PHP_VERSION,
  'pdo_mysql' => extension_loaded('pdo_mysql') ? 'OK' : 'MANCANTE',
  'mbstring' => extension_loaded('mbstring') ? 'OK' : 'MANCANTE',
  'default_charset' => ini_get('default_charset'),
];

echo "<h2>Info</h2><ul>";
foreach ($checks as $k=>$v) { echo "<li><strong>{$k}</strong>: " . htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') . "</li>"; }
echo "</ul>";

try {
  $t = $cfg['tables']['biblio'] ?? 'biblio';
  $stmt = $db->query("SELECT COUNT(*) AS n FROM {$t}");
  $row = $stmt->fetch();
  echo "<p>✅ Tabella {$t}: " . (int)$row['n'] . " record</p>";
} catch (Throwable $e) {
  echo "<p>❌ Query tabella biblio: " . htmlspecialchars($e->getMessage(), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') . "</p>";
}
