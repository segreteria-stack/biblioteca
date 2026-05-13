<?php
declare(strict_types=1);

/**
 * Cron email (CLI)
 *
 * Uso:
 *   php public/pages/cron_email.php --task=send_queue --limit=20
 *   php public/pages/cron_email.php --task=retry_failed
 *
 * NOTE:
 * - Connessione DB centralizzata in lib/DB.php (DB::conn()) che usa $cfg['db'] da config.php
 * - Invio email tramite EmailQueue + EmailService
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

/**
 * Se questo file sta in public/pages/, la root progetto è due livelli sopra.
 * public/pages -> public -> ROOT
 */
$root = dirname(__DIR__);

// -----------------------------------------------------------------------------
// Bootstrap minimo
// -----------------------------------------------------------------------------
$cfg = $cfg ?? [];
require_once $root . '/config.php';
require_once $root . '/lib/DB.php';
require_once $root . '/lib/EmailQueue.php';
require_once $root . '/lib/EmailService.php';

// -----------------------------------------------------------------------------
// Args
// -----------------------------------------------------------------------------
function parseArgs(array $argv): array
{
    $out = [];
    foreach ($argv as $i => $a) {
        if ($i === 0) continue;
        if (strpos($a, '--') !== 0) continue;

        $eq = strpos($a, '=');
        if ($eq !== false) {
            $k = substr($a, 2, $eq - 2);
            $v = substr($a, $eq + 1);
            $out[$k] = $v;
        } else {
            $k = substr($a, 2);
            $out[$k] = true;
        }
    }
    return $out;
}

$args  = parseArgs($argv);
$task  = isset($args['task']) ? (string)$args['task'] : 'send_queue';
$limit = isset($args['limit']) ? (int)$args['limit'] : 20;

$task = in_array($task, ['send_queue', 'retry_failed', 'overdue_reminders'], true) ? $task : 'send_queue';
$limit = max(1, min(200, $limit));

// -----------------------------------------------------------------------------
// DB
// -----------------------------------------------------------------------------
try {
    $db = DB::conn();
} catch (Throwable $e) {
    fwrite(STDERR, "DB ERROR: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$queue = new EmailQueue($db);
$mail  = new EmailService($cfg, $root);

// -----------------------------------------------------------------------------
// Task: retry_failed (reset a queued)
// -----------------------------------------------------------------------------
if ($task === 'retry_failed') {
    try {
        // reset solo i failed non “recentemente locked”
        $st = $db->prepare("
            UPDATE email_queue
            SET status='queued', locked_at=NULL, updated_at=NOW()
            WHERE status='failed'
              AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
        ");
        $st->execute();
        echo "Retry reset. rows=" . (int)$st->rowCount() . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

// -----------------------------------------------------------------------------
// Task: overdue_reminders
// Uso: php cron_email.php --task=overdue_reminders
// -----------------------------------------------------------------------------
if ($task === 'overdue_reminders') {
    try {
        $opacLink = '';
        if (!empty($cfg['app']['public_host'])) {
            $opacLink = rtrim((string)$cfg['app']['public_host'], '/') .
                rtrim((string)($cfg['app']['base_url'] ?? ''), '/') .
                '/index.php?page=patron_area';
        }

        $st = $db->query("
            SELECT m.mbrid, m.email, m.first_name, m.last_name,
                   bi.title, bi.author,
                   c.due_back_dt
            FROM biblio_copy c
            JOIN member m ON m.mbrid = c.mbrid
            JOIN biblio bi ON bi.bibid = c.bibid
            WHERE c.status_cd IN ('out', 'ln')
              AND c.due_back_dt IS NOT NULL
              AND c.due_back_dt <= CURDATE()
              AND m.email IS NOT NULL AND m.email <> ''
            ORDER BY m.mbrid ASC, c.due_back_dt ASC
        ");
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        $byMember = [];
        foreach ($rows as $r) {
            $mid = (int)$r['mbrid'];
            $byMember[$mid]['email']      = (string)$r['email'];
            $byMember[$mid]['first_name'] = (string)($r['first_name'] ?? '');
            $byMember[$mid]['last_name']  = (string)($r['last_name'] ?? '');
            $byMember[$mid]['loans'][]    = [
                'title'        => (string)($r['title'] ?? ''),
                'due_date'     => $r['due_back_dt'] !== null
                                    ? date('d/m/Y', strtotime((string)$r['due_back_dt']))
                                    : '',
                'days_overdue' => (int)max(0, (int)floor((time() - strtotime((string)$r['due_back_dt'])) / 86400)),
            ];
        }

        $enqueued = 0;
        foreach ($byMember as $mid => $data) {
            $to = trim($data['email']);
            if ($to === '') continue;
            $name = trim($data['first_name'] . ' ' . $data['last_name']);
            $queue->enqueue(
                $to,
                'Sollecito restituzione — Biblioteca della Resistenza',
                'patron/overdue_reminder',
                [
                    'name'     => $name,
                    'loans'    => $data['loans'],
                    'opacLink' => $opacLink,
                ],
                priority: 3
            );
            $enqueued++;
        }
        echo "Overdue reminders enqueued={$enqueued}" . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

// -----------------------------------------------------------------------------
// Task: send_queue
// -----------------------------------------------------------------------------
if (!$mail->isEnabled()) {
    echo "Mail disabled (cfg['mail']['enabled']=false)\n";
    exit(0);
}

try {
    $items = $queue->claimBatch($limit, 600);
} catch (Throwable $e) {
    fwrite(STDERR, "CLAIM ERROR: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!$items) {
    echo "No queued emails\n";
    exit(0);
}

$sent = 0;
$failed = 0;

foreach ($items as $row) {
    $id          = (int)($row['id'] ?? 0);
    $to          = (string)($row['to_email'] ?? '');
    $subject     = (string)($row['subject'] ?? '');
    $template    = (string)($row['template'] ?? '');
    $dataJson    = (string)($row['data_json'] ?? '');
    $attempts    = (int)($row['attempts'] ?? 0);
    $maxAttempts = (int)($row['max_attempts'] ?? 5);

    // data
    $data = [];
    if ($dataJson !== '') {
        $tmp = json_decode($dataJson, true);
        if (is_array($tmp)) $data = $tmp;
    }

    try {
        $ok = $mail->send($to, $subject, $template, $data);
        if ($ok) {
            $queue->markSent($id);
            $queue->logResult($id, $to, $subject, $template, 'sent', null);
            $sent++;
        } else {
            $attempts++;
            $err = 'send() returned false';
            $queue->markFailed($id, $attempts, $maxAttempts, $err);
            $queue->logResult($id, $to, $subject, $template, 'failed', $err);
            $failed++;
        }
    } catch (Throwable $e) {
        $attempts++;
        $err = $e->getMessage();
        try {
            $queue->markFailed($id, $attempts, $maxAttempts, $err);
            $queue->logResult($id, $to, $subject, $template, 'failed', $err);
        } catch (Throwable $e2) {
            fwrite(STDERR, "MARK FAILED ERROR (id={$id}): " . $e2->getMessage() . PHP_EOL);
        }
        $failed++;
    }
}

echo "Done. sent={$sent} failed={$failed}\n";
exit(0);
