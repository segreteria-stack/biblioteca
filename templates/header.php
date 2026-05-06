<?php
declare(strict_types=1);

/**
 * Header pubblico OPAC – Biblioteca della Resistenza (ANPI Udine).
 *
 * - Link Staff discreto.
 * - Patron: "Accedi / Registrati" se non autenticato; "Il mio account" + "Esci" se autenticato.
 */

/** @var array<string, mixed> $cfg */
if (!isset($cfg) || !is_array($cfg)) {
    $cfg = [];
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$base   = (string)($cfg['app']['base_url'] ?? '/public');
$pageId = (string)($page ?? '');

// --- Patron status (affidabile) ---
require_once __DIR__ . '/../lib/PatronAuth.php';
$patronUser = PatronAuth::user(); // null se non loggato

$patronName = '';
if (is_array($patronUser)) {
    $patronName = trim((string)($patronUser['name'] ?? ''));
    if ($patronName === '') {
        $fn = trim((string)($patronUser['first_name'] ?? ''));
        $ln = trim((string)($patronUser['last_name'] ?? ''));
        $patronName = trim($fn . ' ' . $ln);
    }
}

// --- Staff status (lasciato com'è, discreto) ---
$staff = $_SESSION['staff'] ?? ($_SESSION['staff_user_id'] ?? null);

// Titolo HTML di default
$titleHtml = (string)($title ?? 'OPAC – Biblioteca della Resistenza');
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="icon" type="image/png" href="<?= h($base) ?>/assets/favicon.png">
    <title><?= h($titleHtml) ?></title>

    <meta name="description"
          content="Catalogo online della Biblioteca della Resistenza del Comitato Provinciale ANPI di Udine.">

    <link rel="stylesheet" href="<?= h($base) ?>/css/style.css?v=20251114">

    <?php if ($pageId === 'item'): ?>
        <link rel="stylesheet" href="<?= h($base) ?>/css/item.css?v=20251125">
    <?php endif; ?>

    <?php if ($pageId === 'donazioni'): ?>
        <link rel="stylesheet" href="<?= h($base) ?>/css/donazioni.css?v=20251210">
    <?php endif; ?>

<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-GDP18WS03L"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-GDP18WS03L');
</script>

</head>
<body style="overflow-x:hidden">
<header class="site-header">

    <!-- Barra utility (accessi riservati) -->
    <div class="utility-login-bar" aria-label="Accesso riservato">
        <div class="container utility-login-inner">
            <div class="header-actions">
                <div class="user-links">

                    <!-- STAFF (discreto) -->
                    <?php if ($staff) : ?>
                        <a class="nav-link nav-link--ghost" href="<?= h($base) ?>/index.php?page=staff">Admin</a>
                        <a class="nav-link nav-link--ghost" href="<?= h($base) ?>/index.php?page=staff_logout">Esci</a>
                    <?php else : ?>
                        <a class="nav-link nav-link--ghost" href="<?= h($base) ?>/index.php?page=login">Admin</a>
                    <?php endif; ?>

                    <!-- separatore morbido (solo testo, non cambia layout) -->
                    <span class="header-mini-label" aria-hidden="true">|</span>

                    <!-- PATRON -->
                    <?php if ($patronUser) : ?>
                        <span class="header-mini-label">
                            Connesso<?= $patronName !== '' ? (': ' . h($patronName)) : '' ?>
                        </span>
                        <a class="nav-link nav-link--pill" href="<?= h($base) ?>/index.php?page=patron_area">
                            Il mio account
                        </a>
                        <a class="nav-link nav-link--ghost" href="<?= h($base) ?>/index.php?page=patron_logout">
                            Esci
                        </a>
                    <?php else : ?>
                        <a class="nav-link nav-link--pill" href="<?= h($base) ?>/index.php?page=patron_login">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="vertical-align:-.1em;margin-right:.3em;"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>Accedi / Registrati
                        </a>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <!-- Blocco principale: logo + menu pubblico -->
    <div class="container header-inner">
        <div class="brand-and-nav">
            <a class="brand" href="<?= h($base) ?>/index.php" aria-label="Home OPAC Biblioteca della Resistenza">
                <?php
                $logoPathFs = __DIR__ . '/../public/assets/logo.png';
                ?>
                <?php if (is_file($logoPathFs)) : ?>
                    <img
                        src="<?= h($base) ?>/assets/logo.png"
                        alt="ANPI - Biblioteca della Resistenza"
                        class="brand-mark"
                        loading="lazy"
                    >
                <?php else : ?>
                    <span class="brand-placeholder" aria-hidden="true">ANPI</span>
                <?php endif; ?>

                <span class="brand-text">
                    <span class="brand-title">Biblioteca della Resistenza</span>
                    <span class="brand-subtitle">Comitato Provinciale ANPI di Udine</span>
                </span>
            </a>

            <nav class="main-nav" aria-label="Navigazione principale">
                <a class="nav-link<?= ($pageId === 'home' || $pageId === '') ? ' is-active' : '' ?>"
                   href="<?= h($base) ?>/index.php">Home</a>

                <a class="nav-link<?= $pageId === 'search' ? ' is-active' : '' ?>"
                   href="<?= h($base) ?>/index.php?page=search">Catalogo</a>

                <a class="nav-link<?= $pageId === 'search_advanced' ? ' is-active' : '' ?>"
                   href="<?= h($base) ?>/index.php?page=search_advanced">Ricerca avanzata</a>

                <a class="nav-link<?= $pageId === 'contatti' ? ' is-active' : '' ?>"
                   href="<?= h($base) ?>/index.php?page=contatti">Contatti</a>
            </nav>
        </div>
    </div>
</header>

<main class="site-main container">