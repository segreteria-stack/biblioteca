<?php
declare(strict_types=1);

/**
 * Logout staff (e opzionalmente utenti lettori).
 *
 * Viene richiamato da:
 * - header (link "Esci" per lo staff) -> index.php?page=logout
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Cancella tutte le variabili di sessione staff
unset(
    $_SESSION['staff_user_id'],
    $_SESSION['staff_username'],
    $_SESSION['staff_fullname'],
    $_SESSION['staff_is_admin'],
    $_SESSION['staff_can_circ'],
    $_SESSION['staff_can_circ_m'],
    $_SESSION['staff_can_catalog'],
    $_SESSION['staff_can_reports']
);

// Se vuoi che il logout staff chiuda anche l'area utente, togli il commento qui
/*
unset(
    $_SESSION['patron_user_id'],
    $_SESSION['patron_username'],
    $_SESSION['patron_fullname']
);
*/

session_regenerate_id(true);

$baseUrl = function_exists('base_url') ? base_url() : '';
header('Location: ' . $baseUrl . '/index.php?page=login');
exit;
