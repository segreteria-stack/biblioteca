<?php
declare(strict_types=1);

/**
 * Helper centralizzato per l'autenticazione e l'autorizzazione staff.
 *
 * Le variabili di sessione impostate da login.php sono:
 *   staff_user_id, staff_username, staff_fullname,
 *   staff_is_admin, staff_can_circ, staff_can_circ_m,
 *   staff_can_catalog, staff_can_reports
 */
final class StaffAuth
{
    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['staff_user_id']);
    }

    public static function userId(): int
    {
        return (int)($_SESSION['staff_user_id'] ?? 0);
    }

    public static function fullName(): string
    {
        return (string)($_SESSION['staff_fullname'] ?? '');
    }

    public static function isAdmin(): bool
    {
        return !empty($_SESSION['staff_is_admin']);
    }

    public static function canCirc(): bool
    {
        return self::isAdmin() || !empty($_SESSION['staff_can_circ']);
    }

    public static function canCatalog(): bool
    {
        return self::isAdmin() || !empty($_SESSION['staff_can_catalog']);
    }

    public static function canReports(): bool
    {
        return self::isAdmin() || !empty($_SESSION['staff_can_reports']);
    }

    public static function canManagePatrons(): bool
    {
        return self::isAdmin() || !empty($_SESSION['staff_can_circ_m']);
    }

    /**
     * Richiede che l'utente sia loggato come staff.
     * In caso contrario reindirizza al login ed esce.
     */
    public static function requireLogin(string $redirectPage = 'staff'): void
    {
        if (!self::isLoggedIn()) {
            $base = function_exists('base_url') ? base_url() : '';
            header('Location: ' . $base . '/index.php?page=login&redirect=' . urlencode($redirectPage));
            exit;
        }
    }

    /**
     * Richiede il ruolo admin. Mostra 403 se non autorizzato.
     */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            echo '<div style="padding:2rem;font-family:sans-serif"><h1>Accesso negato</h1>'
               . '<p>Questa sezione richiede privilegi di amministratore.</p>'
               . '<a href="index.php?page=staff">Torna all\'area staff</a></div>';
            exit;
        }
    }

    /**
     * Richiede il permesso di circolazione (prestiti/restituzioni).
     */
    public static function requireCirc(): void
    {
        self::requireLogin();
        if (!self::canCirc()) {
            http_response_code(403);
            echo '<div style="padding:2rem;font-family:sans-serif"><h1>Accesso negato</h1>'
               . '<p>Questa sezione richiede permessi di circolazione.</p>'
               . '<a href="index.php?page=staff">Torna all\'area staff</a></div>';
            exit;
        }
    }

    /**
     * Richiede il permesso di catalogazione.
     */
    public static function requireCatalog(): void
    {
        self::requireLogin();
        if (!self::canCatalog()) {
            http_response_code(403);
            echo '<div style="padding:2rem;font-family:sans-serif"><h1>Accesso negato</h1>'
               . '<p>Questa sezione richiede permessi di catalogazione.</p>'
               . '<a href="index.php?page=staff">Torna all\'area staff</a></div>';
            exit;
        }
    }

    /**
     * Richiede il permesso di gestione utenti.
     */
    public static function requirePatronMgmt(): void
    {
        self::requireLogin();
        if (!self::canManagePatrons()) {
            http_response_code(403);
            echo '<div style="padding:2rem;font-family:sans-serif"><h1>Accesso negato</h1>'
               . '<p>Questa sezione richiede permessi di gestione utenti.</p>'
               . '<a href="index.php?page=staff">Torna all\'area staff</a></div>';
            exit;
        }
    }
}
