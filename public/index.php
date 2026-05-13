<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

// Carica config e librerie di base
$cfg = [];
require ROOT . '/config.php';

if (!empty($cfg['app']['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
require ROOT . '/lib/DB.php';
require ROOT . '/lib/helpers.php';
require ROOT . '/lib/CoverService.php';

// Prova connessione DB per intercettare subito eventuali errori
try {
    $pdo = DB::conn();
    
    // Alias legacy/compatibilità: molte pages usano $db
    $db = $pdo;
    $GLOBALS['db'] = $db;
    
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Errore di connessione al database</h1>';
    echo '<pre>' . h($e->getMessage()) . '</pre>';
    exit;
}

$templateDir = ROOT . '/templates';
$pagesDir    = ROOT . '/pages';

// Routing flessibile ma sicuro
$page = $_GET['page'] ?? 'home';

// Garantiamo che sia una stringa non vuota
if (!is_string($page) || $page === '') {
    $page = 'home';
}

// Permettiamo solo caratteri sicuri (lettere, numeri, underscore)
if (!preg_match('/^[a-z0-9_]+$/', $page)) {
    $page = 'home';
}

// Se il file della pagina non esiste, torniamo alla home
if (!file_exists($pagesDir . '/' . $page . '.php')) {
    $page = 'home';
}

$title = 'Biblioteca della Resistenza';

// Pagine standalone: emettono il proprio HTML completo (no header/footer)
$standalonePages = ['staff_barcodes'];
if (in_array($page, $standalonePages, true)) {
    require $pagesDir . '/' . $page . '.php';
    exit;
}

require $templateDir . '/header.php';
require $pagesDir . '/' . $page . '.php';
require $templateDir . '/footer.php';
