<?php
declare(strict_types=1);

final class DB
{
    private static ?\PDO $pdo = null;

    public static function conn(): \PDO
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }

        if (!class_exists('PDO')) {
            throw new \RuntimeException('PDO non disponibile in questa installazione di PHP.');
        }

        // Recupera configurazione
        global $cfg;

        $dsn  = null;
        $user = null;
        $pass = null;

        // 1) Config array $cfg['db']
        if (is_array($cfg ?? null) && isset($cfg['db']['dsn'])) {
            $dsn  = (string)$cfg['db']['dsn'];
            $user = (string)($cfg['db']['user'] ?? '');
            $pass = (string)($cfg['db']['pass'] ?? '');
        }
        // 2) Costanti DB_DSN / DB_USER / DB_PASS
        elseif (defined('DB_DSN')) {
            $dsn  = (string)DB_DSN;
            $user = defined('DB_USER') ? (string)DB_USER : '';
            $pass = defined('DB_PASS') ? (string)DB_PASS : '';
        }
        // 3) Quartetto DB_HOST/DB_NAME/DB_USER/DB_PASS
        elseif (defined('DB_HOST') && defined('DB_NAME')) {
            $host = (string)DB_HOST;
            $name = (string)DB_NAME;
            $dsn  = "mysql:host={$host};dbname={$name};charset=utf8mb4";
            $user = defined('DB_USER') ? (string)DB_USER : '';
            $pass = defined('DB_PASS') ? (string)DB_PASS : '';
        }

        if ($dsn === null) {
            throw new \RuntimeException('Database DSN non configurato: controlla config.php.');
        }

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        self::$pdo = new \PDO($dsn, $user, $pass, $options);
        return self::$pdo;
    }
}
