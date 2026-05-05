<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$baseUrl = function_exists('base_url') ? base_url() : '';

if (empty($_SESSION['staff_user_id'])) {
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=staff_user_list');
    exit;
}

$isAdmin = !empty($_SESSION['staff_is_admin']) && $_SESSION['staff_is_admin'] === true;
if (!$isAdmin) {
    ?>
    <section class="page-section page-staff">
        <header class="staff-header"><h1>Elenco account staff</h1></header>
        <div class="generic-box"><p>Non hai i permessi necessari per gestire gli account staff.</p></div>
    </section>
    <?php
    return;
}

$pdo = DB::conn();

// Azione: sospendi/riattiva
$msg   = '';
$msgOk = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $uid    = (int)($_POST['userid'] ?? 0);
    $selfId = (int)($_SESSION['staff_user_id'] ?? 0);

    if ($uid > 0 && $uid !== $selfId) {
        try {
            if ($action === 'suspend') {
                $pdo->prepare("UPDATE staff SET suspended_flg='Y', last_change_dt=NOW(), last_change_userid=? WHERE userid=? LIMIT 1")
                    ->execute([$selfId, $uid]);
                $msg = 'Account sospeso.';
            } elseif ($action === 'activate') {
                $pdo->prepare("UPDATE staff SET suspended_flg='N', last_change_dt=NOW(), last_change_userid=? WHERE userid=? LIMIT 1")
                    ->execute([$selfId, $uid]);
                $msg = 'Account riattivato.';
            } elseif ($action === 'delete') {
                $pdo->prepare("DELETE FROM staff WHERE userid=? LIMIT 1")->execute([$uid]);
                $msg = 'Account eliminato.';
            }
        } catch (Throwable $e) {
            $msg   = 'Errore durante l\'operazione.';
            $msgOk = false;
        }
    } elseif ($uid === $selfId) {
        $msg   = 'Non puoi modificare il tuo stesso account da questa pagina.';
        $msgOk = false;
    }
}

// Elenco staff
$staff = [];
try {
    $staff = $pdo->query("
        SELECT userid, username, email, first_name, last_name,
               suspended_flg, admin_flg, circ_flg, circ_mbr_flg, catalog_flg, reports_flg,
               create_dt, last_change_dt
        FROM staff
        ORDER BY last_name, first_name, username
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $msg   = 'Errore nel recupero degli account staff.';
    $msgOk = false;
}

$selfId = (int)($_SESSION['staff_user_id'] ?? 0);

function flagBadge(string $val, string $label): string {
    $on = strtoupper($val) === 'Y';
    $style = $on
        ? 'background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7'
        : 'background:#f5f5f5;color:#aaa;border:1px solid #ddd';
    return '<span style="display:inline-block;padding:1px 7px;border-radius:10px;font-size:11px;' . $style . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span> ';
}
?>
<section class="page-section page-staff">
    <header class="staff-header">
        <div class="staff-header-top">
            <div class="staff-header-main">
                <h1>Elenco account staff</h1>
                <p class="staff-header-subtitle">Gestione operatori — <?= count($staff) ?> account</p>
            </div>
            <div>
                <a class="btn-primary" href="<?= h($baseUrl) ?>/index.php?page=staff_user_add">+ Nuovo account</a>
            </div>
        </div>
    </header>

    <?php if ($msg !== ''): ?>
        <div class="generic-box" style="border-left:4px solid <?= $msgOk ? '#0a7' : '#c00' ?>;background:<?= $msgOk ? '#f4f8f4' : '#fdf4f4' ?>;margin-bottom:1rem">
            <p><?= h($msg) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($staff === []): ?>
        <div class="generic-box"><p>Nessun account staff trovato.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:14px">
            <thead>
                <tr style="background:#f5f5f5;border-bottom:2px solid #ddd">
                    <th style="text-align:left;padding:8px 12px">Username</th>
                    <th style="text-align:left;padding:8px 12px">Nome</th>
                    <th style="text-align:left;padding:8px 12px">Email</th>
                    <th style="text-align:left;padding:8px 12px">Permessi</th>
                    <th style="text-align:left;padding:8px 12px">Stato</th>
                    <th style="text-align:left;padding:8px 12px">Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($staff as $s): ?>
                <?php $isSelf = ((int)$s['userid'] === $selfId); ?>
                <tr style="border-bottom:1px solid #eee;<?= strtoupper((string)$s['suspended_flg']) === 'Y' ? 'opacity:0.6' : '' ?>">
                    <td style="padding:8px 12px">
                        <strong><?= h((string)$s['username']) ?></strong>
                        <?php if ($isSelf): ?> <em style="color:#888;font-size:12px">(tu)</em><?php endif; ?>
                    </td>
                    <td style="padding:8px 12px"><?= h(trim((string)$s['first_name'] . ' ' . (string)$s['last_name'])) ?></td>
                    <td style="padding:8px 12px"><?= h((string)($s['email'] ?? '')) ?></td>
                    <td style="padding:8px 12px">
                        <?= flagBadge((string)$s['admin_flg'],    'admin') ?>
                        <?= flagBadge((string)$s['circ_flg'],     'circ') ?>
                        <?= flagBadge((string)$s['circ_mbr_flg'], 'utenti') ?>
                        <?= flagBadge((string)$s['catalog_flg'],  'cat.') ?>
                        <?= flagBadge((string)$s['reports_flg'],  'report') ?>
                    </td>
                    <td style="padding:8px 12px">
                        <?php if (strtoupper((string)$s['suspended_flg']) === 'Y'): ?>
                            <span style="color:#c00">Sospeso</span>
                        <?php else: ?>
                            <span style="color:#0a7">Attivo</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:8px 12px">
                        <?php if (!$isSelf): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="userid" value="<?= (int)$s['userid'] ?>">
                            <?php if (strtoupper((string)$s['suspended_flg']) === 'Y'): ?>
                                <button name="action" value="activate" class="btn-link" style="color:#0a7;margin-right:6px"
                                        onclick="return confirm('Riattivare questo account?')">Riattiva</button>
                            <?php else: ?>
                                <button name="action" value="suspend" class="btn-link" style="color:#e07000;margin-right:6px"
                                        onclick="return confirm('Sospendere questo account?')">Sospendi</button>
                            <?php endif; ?>
                            <button name="action" value="delete" class="btn-link" style="color:#c00"
                                    onclick="return confirm('Eliminare definitivamente l\'account <?= h((string)$s['username']) ?>?')">Elimina</button>
                        </form>
                        <?php else: ?>
                            <span style="color:#aaa;font-size:12px">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div style="margin-top:1.5rem">
        <a class="btn-link" href="<?= h($baseUrl) ?>/index.php?page=staff">← Dashboard</a>
    </div>
</section>
