<?php
declare(strict_types=1);

/* =========================
   App
   ========================= */
$cfg = $cfg ?? [];

$cfg['app']['base_url']    = '/public';              // URL base delle pagine pubbliche
$cfg['app']['public_host'] = 'https://biblioteca.anpiudine.org'; // hostname pubblico (senza slash finale)
$cfg['app']['debug']       = false;                  // true solo in ambiente di sviluppo locale

if (!defined('PAGE_SIZE')) define('PAGE_SIZE', 30);
if (!defined('APP_NAME'))  define('APP_NAME', 'OPAC Biblioteca');

// Fuso orario consigliato
date_default_timezone_set('Europe/Rome');

/* =========================
   Database (usa questa configurazione)
   ========================= */

$cfg['db'] = [
  'dsn'  => 'mysql:host=localhost;dbname=anpiudine-or1d94_2;charset=utf8mb4',
  'user' => 'anpiudine-or1d94',
  'pass' => 'Fi.Oem-4FG1W.2-J',
];

/* =========================
   Email
   ========================= */

$cfg['mail'] = [
  'enabled' => true,

  'driver'  => 'smtp',
  'host'    => 'smtp.gmail.com',
  'port'    => 587,
  'secure'  => 'tls',

  'username' => 'segreteria@anpiudine.org',
  'password' => 'sils ergm hqgb habw',

  'from_email' => 'biblioteca@anpiudine.org',
  'from_name'  => 'Biblioteca della Resistenza',

  'staff_email'=> 'biblioteca@anpiudine.org',
];

/* =========================
   Google Books API
   ========================= */

$cfg['google_books'] = [
  'enabled' => true,
  'api_key' => 'AIzaSyCXeOXjPKX4pAIPWX8mSd3CpkScUEMrCjE',
];

/* =========================
   Cron HTTP (Shellrent)
   ========================= */

// Token di protezione per cron_email_http.php
$cfg['cron_http_token'] = 'a9F3d2K7LxQ8R4WmC5YpH6ZsT0VbN1eU';

/* =========================
   SBN / ICCU API
   ========================= */

$cfg['sbn'] = [
    'enabled'         => true,
    'consumer_key'    => 'Z5U4tDjlFGOpCkTMGYFMWtESd1wa',
    'consumer_secret' => 'tQuSXfpFFfoI_iGlzuzYDjn4ZF8a',
];
