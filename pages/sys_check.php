<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['staff_user_id'])) {
    http_response_code(403);
    echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><title>Accesso negato</title></head>'
       . '<body><p>Accesso riservato allo staff. <a href="index.php?page=login">Accedi</a></p></body></html>';
    exit;
}

$ok = []; $err = [];

try {
    $pdoVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $ok[] = 'Connessione MySQL OK (server ' . h((string)$pdoVersion) . ')';
} catch (Throwable $e) {
    $err[] = 'DB: ' . h($e->getMessage());
}

$checks = [
    'PHP version'     => PHP_VERSION,
    'mbstring'        => extension_loaded('mbstring')  ? 'OK' : 'MANCANTE',
    'pdo_mysql'       => extension_loaded('pdo_mysql') ? 'OK' : 'MANCANTE',
    'default_charset' => ini_get('default_charset') ?: '(non impostato)',
    'display_errors'  => ini_get('display_errors') ?: '0',
    'timezone'        => date_default_timezone_get(),
];
?>
<!doctype html>
<html lang="it">
<head><meta charset="utf-8"><title>System Check</title>
<style>
body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;max-width:900px;margin:40px auto;padding:0 16px}
.ok{color:#0a7f2e} .err{color:#b00020}
table{border-collapse:collapse;width:100%} th,td{padding:6px 10px;border:1px solid #ddd;text-align:left} th{background:#f5f5f5}
</style>
</head>
<body>
<h1>System Check</h1>

<h2>Stato</h2>
<ul>
    <?php foreach ($ok  as $m): ?><li class="ok">✅ <?= h($m) ?></li><?php endforeach; ?>
    <?php foreach ($err as $m): ?><li class="err">❌ <?= h($m) ?></li><?php endforeach; ?>
</ul>

<h2>Dettagli</h2>
<table>
    <?php foreach ($checks as $k => $v): ?>
    <tr><th><?= h($k) ?></th><td><?= h((string)$v) ?></td></tr>
    <?php endforeach; ?>
</table>

<h2>Query test</h2>
<?php if (empty($err)): ?>
    <?php try {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM biblio')->fetchColumn();
        echo '<p class="ok">Record in biblio: <strong>' . $n . '</strong></p>';
    } catch (Throwable $e) {
        echo '<p class="err">Errore query biblio: ' . h($e->getMessage()) . '</p>';
    } ?>
<?php else: ?>
    <p class="err">Correggi gli errori sopra e ricarica la pagina.</p>
<?php endif; ?>

<p style="margin-top:2rem;font-size:.85em;color:#888;">
    <a href="index.php?page=staff">← Dashboard staff</a>
</p>
</body>
</html>
