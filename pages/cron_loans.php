<?php
declare(strict_types=1);

/**
 * Cron prestiti (CLI o HTTP con token)
 *
 * Task disponibili:
 *   --task=notify_expiry   Invia email ai patron con prestiti in scadenza (1 gg) o scaduti (0)
 *   --task=clean_holds     Elimina prenotazioni scadute (oltre hold_max_days dalla data di prenotazione)
 *
 * Uso CLI:
 *   php pages/cron_loans.php --task=notify_expiry
 *   php pages/cron_loans.php --task=clean_holds --days=30
 *
 * Uso HTTP (protetto da token):
 *   GET /index.php?page=cron_loans&task=notify_expiry&token=XXX
 */

$isCli  = PHP_SAPI === 'cli';
$isHttp = !$isCli;

// Bootstrap
$root = $isCli ? dirname(__DIR__) : (defined('ROOT') ? ROOT : dirname(__DIR__));

if ($isCli) {
    $cfg = [];
    require_once $root . '/config.php';
    require_once $root . '/lib/DB.php';
    require_once $root . '/lib/helpers.php';
    require_once $root . '/lib/EmailService.php';
}

// Protezione HTTP
if ($isHttp) {
    $cronToken = (string)($cfg['cron_http_token'] ?? '');
    $reqToken  = (string)($_GET['token'] ?? '');
    if ($cronToken === '' || !hash_equals($cronToken, $reqToken)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

// Argomenti
if ($isCli) {
    $args = [];
    foreach (array_slice($argv, 1) as $a) {
        if (str_starts_with($a, '--')) {
            [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, true);
            $args[$k] = $v;
        }
    }
    $task = (string)($args['task'] ?? 'notify_expiry');
    $days = (int)($args['days'] ?? 30);
} else {
    $task = (string)($_GET['task'] ?? 'notify_expiry');
    $days = (int)($_GET['days'] ?? 30);
}

$task = in_array($task, ['notify_expiry', 'clean_holds'], true) ? $task : 'notify_expiry';
$days = max(1, min(365, $days));

function cronLog(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    if (PHP_SAPI === 'cli') {
        echo $line;
    } else {
        error_log('cron_loans: ' . $msg);
    }
}

try {
    $db = DB::conn();
} catch (Throwable $e) {
    cronLog('DB ERROR: ' . $e->getMessage());
    exit(1);
}

// ============================================================
// Task: notify_expiry
// Invia email ai patron con prestiti in scadenza oggi/domani
// o già scaduti (una sola notifica per prestito per evitare spam)
// ============================================================
if ($task === 'notify_expiry') {
    $mail    = new EmailService($cfg, $root);
    $baseUrl = rtrim((string)($cfg['app']['base_url'] ?? ''), '/');
    $opacUrl = $baseUrl . '/index.php?page=patron_area&tab=loans';

    if (!$mail->isEnabled()) {
        cronLog('Mail disabilitata, skip.');
        exit(0);
    }

    // Prestiti in scadenza entro 2 giorni O già scaduti da meno di 7 giorni
    $sql = "
        SELECT c.bibid, c.copyid, c.mbrid, c.due_back_dt,
               b.title, b.author,
               m.email, m.first_name, m.last_name,
               DATEDIFF(c.due_back_dt, CURDATE()) AS days_left
        FROM biblio_copy c
        JOIN biblio b ON b.bibid = c.bibid
        JOIN member m ON m.mbrid = c.mbrid
        WHERE c.status_cd = 'out'
          AND c.due_back_dt IS NOT NULL
          AND c.due_back_dt BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                                AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
          AND m.email IS NOT NULL AND m.email <> ''
          AND m.is_active = 'Y'
        ORDER BY c.due_back_dt ASC
    ";

    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $sent = 0;
    $skip = 0;

    foreach ($rows as $r) {
        $daysLeft = (int)$r['days_left'];
        $to       = (string)$r['email'];
        $name     = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));

        $subject = $daysLeft < 0
            ? 'Prestito scaduto — Biblioteca della Resistenza'
            : 'Prestito in scadenza — Biblioteca della Resistenza';

        $ok = $mail->send($to, $subject, 'patron/loan_expiry', [
            'patronName' => $name,
            'title'      => (string)$r['title'],
            'author'     => (string)($r['author'] ?? ''),
            'dueDate'    => (string)$r['due_back_dt'],
            'daysLeft'   => $daysLeft,
            'opacUrl'    => $opacUrl,
        ]);

        if ($ok) {
            $sent++;
            cronLog("Notifica inviata a {$to} per bibid={$r['bibid']} (days_left={$daysLeft})");
        } else {
            $skip++;
            cronLog("Invio fallito per {$to} bibid={$r['bibid']}");
        }
    }

    cronLog("notify_expiry: totale={$sent} inviati, {$skip} falliti su " . count($rows) . ' prestiti.');
    exit(0);
}

// ============================================================
// Task: clean_holds
// Elimina prenotazioni più vecchie di $days giorni
// ============================================================
if ($task === 'clean_holds') {
    try {
        $st = $db->prepare("
            DELETE FROM biblio_hold
            WHERE hold_begin_dt < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $st->execute([$days]);
        $deleted = $st->rowCount();
        cronLog("clean_holds: eliminate {$deleted} prenotazioni scadute (oltre {$days} giorni).");
    } catch (Throwable $e) {
        cronLog('ERROR clean_holds: ' . $e->getMessage());
        exit(1);
    }
    exit(0);
}
