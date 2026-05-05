<?php
declare(strict_types=1);

/**
 * Simple DB-backed sliding-window rate limiter.
 *
 * Usage:
 *   if (!RateLimit::check($pdo, 'login', $ip, 10, 300)) {
 *       die('Troppi tentativi. Riprova tra qualche minuto.');
 *   }
 */
class RateLimit
{
    /**
     * Check whether the bucket is within the allowed limit and record the hit.
     *
     * @param PDO    $pdo      DB connection
     * @param string $action   Action key (e.g. 'login', 'register', 'forgot')
     * @param string $key      Discriminator (e.g. IP address or email)
     * @param int    $maxHits  Maximum allowed hits in the window
     * @param int    $windowSec Window size in seconds
     * @return bool  true = allowed, false = rate-limited
     */
    public static function check(PDO $pdo, string $action, string $key, int $maxHits, int $windowSec): bool
    {
        $bucket = substr($action . ':' . $key, 0, 120);
        $since  = date('Y-m-d H:i:s', time() - $windowSec);

        try {
            // Count hits in window
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM rate_limit WHERE bucket = ? AND hit_at >= ?'
            );
            $st->execute([$bucket, $since]);
            $count = (int)$st->fetchColumn();

            if ($count >= $maxHits) {
                return false;
            }

            // Record this hit
            $pdo->prepare('INSERT INTO rate_limit (bucket, hit_at) VALUES (?, NOW())')
                ->execute([$bucket]);

            // Periodic cleanup of old rows (1% chance per request to avoid overhead)
            if (random_int(1, 100) === 1) {
                $pdo->prepare('DELETE FROM rate_limit WHERE hit_at < ?')
                    ->execute([date('Y-m-d H:i:s', time() - max($windowSec * 2, 3600))]);
            }
        } catch (Throwable) {
            // If the table doesn't exist yet, fail open (don't block users)
            return true;
        }

        return true;
    }

    /**
     * Return the client IP, preferring X-Forwarded-For when behind a trusted proxy.
     */
    public static function clientIp(): string
    {
        $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $first = explode(',', $forwarded, 2)[0];
            $ip    = trim($first);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
