<?php
// pages/sys_check.php — check rapido
if (!function_exists('h')) {
  function h(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
}

$ok = []; $err = [];

try {
  $cfg = require __DIR__ . '/../config.php';
  $ok[] = 'config.php caricato';
} catch (Throwable $e) {
  $err[] = 'config.php: ' . h($e->getMessage());
}

try {
  require __DIR__ . '/../lib/DB.php';
  $db = DB::conn($cfg['db']);
  $pdoVersion = $db->getAttribute(PDO::ATTR_SERVER_VERSION);
  $ok.append('Connessione MySQL OK (server ' . h($pdoVersion) . ')');
} catch (Throwable $e) {
  $err[] = 'DB: ' . h($e->getMessage());
}

$checks = [
  'PHP version' => PHP_VERSION,
  'mbstring' => extension_loaded('mbstring') ? 'OK' : 'MANCANTE',
  'pdo_mysql' => extension_loaded('pdo_mysql') ? 'OK' : 'MANCANTE',
  'default_charset' => ini_get('default_charset'),
];
?>
<!doctype html>
<html lang="it"><meta charset="utf-8"><title>System Check</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;max-width:900px;margin:40px auto;padding:0 16px} .ok{color:#0a7f2e} .err{color:#b00020}</style>
<h1>System Check</h1>
<h2>Stato</h2>
<ul>
  <?php foreach ($ok as $m): ?><li class="ok">✅ <?= $m ?></li><?php endforeach; ?>
  <?php foreach ($err as $m): ?><li class="err">❌ <?= $m ?></li><?php endforeach; ?>
</ul>

<h2>Dettagli</h2>
<table border="1" cellpadding="6" cellspacing="0">
  <?php foreach ($checks as $k=>$v): ?>
    <tr><th align="left"><?= h($k) ?></th><td><?= h((string)$v) ?></td></tr>
  <?php endforeach; ?>
</table>

<h2>Query test</h2>
<?php if (empty($err)): ?>
  <?php
    try {
      $stmt = $db->query("SELECT COUNT(*) AS n FROM " . $cfg['tables']['biblio']);
      $row = $stmt->fetch();
      echo '<p class="ok">Record in biblio: <strong>' . (int)$row['n'] . '</strong></p>';
    } catch (Throwable $e) {
      echo '<p class="err">Errore query biblio: ' . h($e->getMessage()) . '</p>';
    }
  ?>
<?php else: ?>
  <p class="err">Correggi gli errori sopra e ricarica la pagina.</p>
<?php endif; ?>
