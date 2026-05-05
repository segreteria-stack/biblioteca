<?php
declare(strict_types=1);

$title = 'Verifica Email';

global $db, $pdo, $cfg;
if (!($db instanceof PDO) && isset($pdo) && ($pdo instanceof PDO)) {
    $db = $pdo;
}

$err = '';
$ok  = '';
$base = rtrim((string)($cfg['app']['base_url'] ?? ''), '/');

if (!($db instanceof PDO)) {
    $err = 'Connessione al database non disponibile.';
} else {
    $token = trim((string)($_GET['token'] ?? ''));

    if ($token === '') {
        $err = 'Token di verifica mancante.';
    } else {
        try {
            $stmt = $db->prepare("
                SELECT pa.id, pa.mbrid, pa.reset_expires
                FROM patron_auth pa
                JOIN member m ON m.mbrid = pa.mbrid
                WHERE pa.reset_token = ?
                  AND pa.reset_expires > NOW()
                  AND m.is_active = 'N'
                LIMIT 1
            ");
            $stmt->execute([$token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $err = 'Il link di verifica non è valido o è scaduto. Registrati nuovamente oppure contatta la biblioteca.';
            } else {
                $mbrid = (int)$row['mbrid'];

                $db->beginTransaction();

                $db->prepare("UPDATE member SET is_active = 'Y' WHERE mbrid = ? LIMIT 1")
                   ->execute([$mbrid]);

                $db->prepare("UPDATE patron_auth SET reset_token = NULL, reset_expires = NULL WHERE mbrid = ? LIMIT 1")
                   ->execute([$mbrid]);

                $db->commit();

                // Log patron in automatically
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                session_regenerate_id(true);
                $_SESSION['patron_id']     = $mbrid;
                $_SESSION['_last_activity'] = time();

                $ok = 'Email verificata con successo! Il tuo account è ora attivo.';
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $err = 'Si è verificato un errore durante la verifica. Riprova più tardi.';
        }
    }
}
?>
<section class="card" style="margin-top:40px;max-width:500px;padding:24px;border:1px solid #eee;border-radius:10px;background:#fff">
  <h1 style="margin:0 0 16px 0">Verifica Email</h1>

  <?php if ($ok): ?>
    <p style="color:#0a7;margin:0 0 16px 0"><?= h($ok) ?></p>
    <a class="button" href="<?= h($base) ?>/index.php?page=patron_area">Vai alla tua area personale</a>
  <?php elseif ($err): ?>
    <p style="color:#b00020;margin:0 0 16px 0"><?= h($err) ?></p>
    <a class="btn-secondary" href="<?= h($base) ?>/index.php?page=user_register">Nuova registrazione</a>
  <?php else: ?>
    <p style="color:#666">Verifica in corso…</p>
  <?php endif; ?>
</section>
