<?php
declare(strict_types=1);

error_reporting(0);
ini_set('display_errors', '0');

define('ROOT', dirname(__DIR__));

// Carica config e librerie di base
$cfg = [];
require ROOT . '/config.php';
require ROOT . '/lib/DB.php';
require ROOT . '/lib/helpers.php';
require ROOT . '/lib/CoverService.php';
require ROOT . '/lib/StaffAuth.php';

// Cookie di sessione sicuri (prima di qualsiasi session_start)
$sessionLifetime = (int)($cfg['app']['session_lifetime'] ?? 7200); // 2 ore default
session_set_cookie_params([
    'lifetime' => 0,           // cookie di sessione (sparisce alla chiusura browser)
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

// Intestazioni di sicurezza HTTP
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

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

// Timeout sessione server-side: invalida sessioni inattive da troppo tempo
if (session_status() === PHP_SESSION_ACTIVE) {
    $lastActivity = (int)($_SESSION['_last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > $sessionLifetime) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['_last_activity'] = time();
}

// -------------------------------------------------------------------
// Controllo ruoli per pagine staff (autorizzazione centralizzata)
// -------------------------------------------------------------------
$staffRoles = [
    // requireLogin: qualsiasi staff autenticato
    'staff'        => 'login',
    'staff_search' => 'login',
    'staff_sbn'    => 'login',
    // requireCatalog
    'staff_catalog_edit'     => 'catalog',
    'staff_catalog_new'      => 'catalog',
    'staff_bulk_description' => 'catalog',
    'staff_sbn_import'       => 'catalog',
    'staff_sbn_enrich_title' => 'catalog',
    'staff_z3950'            => 'catalog',
    'staff_import_wizard'    => 'catalog',
    'staff_import_apply'     => 'catalog',
    'admin_import'           => 'catalog',
    'admin_import_marc'      => 'catalog',
    // requireCirc
    'admin_loans'      => 'circ',
    'admin_loans_ajax' => 'circ',
    // requirePatronMgmt
    'admin_patron'     => 'patron_mgmt',
    'admin_patron_new' => 'patron_mgmt',
    'admin_patrons'    => 'patron_mgmt',
    'admin_holdings'   => 'patron_mgmt',
    'admin_items'      => 'patron_mgmt',
    'admin_item_edit'  => 'patron_mgmt',
    // requireAdmin
    'admin_reports'    => 'admin',
    'staff_user_add'   => 'admin',
    'staff_user_list'  => 'admin',
    'mail_test'        => 'admin',
    'sys_check'        => 'admin',
];

if (isset($staffRoles[$page]) && session_status() === PHP_SESSION_ACTIVE) {
    $requiredRole = $staffRoles[$page];
    switch ($requiredRole) {
        case 'login':        StaffAuth::requireLogin($page); break;
        case 'catalog':      StaffAuth::requireCatalog(); break;
        case 'circ':         StaffAuth::requireCirc(); break;
        case 'patron_mgmt':  StaffAuth::requirePatronMgmt(); break;
        case 'admin':        StaffAuth::requireAdmin(); break;
    }
}

$title = 'Biblioteca della Resistenza';

require $templateDir . '/header.php';
require $pagesDir . '/' . $page . '.php';
require $templateDir . '/footer.php';
