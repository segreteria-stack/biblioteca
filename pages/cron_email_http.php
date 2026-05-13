<?php
declare(strict_types=1);

/**
 * Cron email via HTTP (Shellrent)
 *
 * Esempio:
 *   https://TUO_DOMINIO/public/pages/cron_email_http.php?key=TOKEN&task=send_queue&limit=20
 *
 * NOTE:
 * - Token richiesto (cfg['cron_http_token'])
 * - Task consentiti: send_queue, retry_failed
 */

header('Content-Type: text/plain; charset=UTF-8');

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
// Auth token
// -----------------------------------------------------------------------------
$given = isset($_GET['key']) ? (string)$_GET['key'] : '';
$expected = isset($cfg['cron_http_token']) ? (string)$cfg['cron_http_token'] : '';

if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

// -----------------------------------------------------------------------------
// Params
// -----------------------------------------------------------------------------
$task  = isset($_GET['task']) ? (string)$_GET['task'] : 'send_queue';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

$task = in_array($task, ['send_queue', 'retry_failed', 'overdue_reminders'], true) ? $task : 'send_queue';
$limit = max(1, min(200, $limit));

// -----------------------------------------------------------------------------
// DB
// -----------------------------------------------------------------------------
try {
    $db = DB::conn();
} catch (Throwable $e) {
    http_response_code(500);
    echo "DB ERROR\n";
    exit;
}

$queue = new EmailQueue($db);
$mail  = new EmailService($cfg, $root);

// -----------------------------------------------------------------------------
// Task: retry_failed
// -----------------------------------------------------------------------------
if ($task === 'retry_failed') {
    try {
        $st = $db->prepare("
            UPDATE email_queue
            SET status='queued', locked_at=NULL, updated_at=NOW()
            WHERE status='failed'
              AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
        ");
        $st->execute();
        echo "Retry reset. rows=" . (int)$st->rowCount() . "\n";
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo "ERROR\n";
        exit;
    }
}

// -----------------------------------------------------------------------------
// Task: overdue_reminders — accoda solleciti per prestiti scaduti o in scadenza oggi
// Configurare su Shellrent: giornaliero, mattina.
// Esempio URL: ...?key=TOKEN&task=overdue_reminders
// -----------------------------------------------------------------------------
if ($task === 'overdue_reminders') {
    try {
        $opacLink = '';
        if (!empty($cfg['app']['public_host'])) {
            $opacLink = rtrim((string)$cfg['app']['public_host'], '/') .
                rtrim((string)($cfg['app']['base_url'] ?? ''), '/') .
                '/index.php?page=patron_area';
        }

        // Prestiti scaduti o con scadenza oggi, raggruppati per utente
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
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // Raggruppa per mbrid
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
        echo "Overdue reminders enqueued={$enqueued}\n";
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo "ERROR: " . $e->getMessage() . "\n";
        exit;
    }
}

// -----------------------------------------------------------------------------
// Task: send_queue
// -----------------------------------------------------------------------------
if (!$mail->isEnabled()) {
    echo "Mail disabled\n";
    exit;
}

try {
    $items = $queue->claimBatch($limit, 600);
} catch (Throwable $e) {
    http_response_code(500);
    echo "CLAIM ERROR\n";
    exit;
}

if (!$items) {
    echo "No queued emails\n";
    exit;
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
            // silenzia in http
        }
        $failed++;
    }
}

echo "Done. sent={$sent} failed={$failed}\n";
