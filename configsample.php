<?php
declare(strict_types=1);
/**
 * ESEMPIO di config.php per l'OPAC (non contiene credenziali reali).
 * Scegli UNO dei tre metodi di connessione e commenta gli altri.
 */

// === A) Consigliato: DSN tramite array $cfg ===
// $cfg['db'] = [
//   'dsn'  => 'mysql:host=localhost;dbname=anpiudine-or1d94_2;charset=utf8mb4',
//   'user' => 'anpiudine-or1d94',
//   'pass' => 'Fi.Oem-4FG1W.2-J',
// ];

// === B) In alternativa: costanti DSN ===
// define('DB_DSN', 'mysql:host=127.0.0.1;dbname=biblioteca;charset=utf8mb4');
// define('DB_USER', 'utente_db');
// define('DB_PASS', 'password_db');

// === C) In alternativa: host + nome DB ===
// define('DB_HOST', '127.0.0.1');
// define('DB_NAME', 'biblioteca');
// define('DB_USER', 'utente_db');
// define('DB_PASS', 'password_db');

// Guard per costanti UI
if (!defined('PAGE_SIZE')) define('PAGE_SIZE', 30);
if (!defined('ADV_PAGE_SIZE')) define('ADV_PAGE_SIZE', 20);
if (!defined('SUGGEST_LIMIT')) define('SUGGEST_LIMIT', 10);

// (opzionale) base_url se l'app vive sotto /public (in molti hosting non serve)
// $cfg['app']['base_url'] = '/public';
