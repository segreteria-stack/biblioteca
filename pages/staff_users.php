<?php
/**
 * Area Staff – Elenco account staff
 *
 * Mostra tutti gli operatori registrati con i loro permessi.
 * Permette di sospendere / riattivare un account e di generare
 * un link di reset password. Solo gli amministratori possono accedere.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    /** @var array<string,mixed> $cfg */
    $baseUrl = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/');
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=staff_users');
    exit;
}

/** @var array<string,mixed> $cfg */
$baseUrl = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/');
$pdo     = DB::conn();
$errors  = [];
$messages = [];
$resetLink = '';

$isAdmin    = !empty($_SESSION['staff_is_admin']) && $_SESSION['staff_is_admin'] === true;
$currentUid = (int)($_SESSION['staff_user_id'] ?? 0);

if (!$isAdmin) {
    ?>
    <section class="page-section page-staff">
        <header class="staff-header"><h1>Elenco account staff</h1></header>
        <div class="alert--error"><p>Accesso riservato agli amministratori.</p></div>
    </section>
    <?php
    return;
}

// =============================================================================
// POST
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? '')) {
        $errors[] = 'Sessione scaduta o token non valido, riprova.';
    } else {
    $action  = trim((string)($_POST['action'] ?? ''));
    $uid     = (int)($_POST['userid'] ?? 0);

    if ($action === 'toggle_suspend' && $uid > 0) {
        try {
            $stmt = $pdo->prepare('SELECT suspended_flg FROM staff WHERE userid = :id');
            $stmt->execute([':id' => $uid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $newFlag = ($row['suspended_flg'] === 'Y') ? 'N' : 'Y';
                $pdo->prepare('UPDATE staff SET suspended_flg = :f, last_change_dt = NOW() WHERE userid = :id LIMIT 1')
                    ->execute([':f' => $newFlag, ':id' => $uid]);
                $messages[] = 'Account ' . ($newFlag === 'Y' ? 'sospeso' : 'riattivato') . '.';
            }
        } catch (Throwable) { $errors[] = 'Errore aggiornamento.'; }
    }

    if ($action === 'update_perms' && $uid > 0 && $uid !== $currentUid) {
        try {
            $pdo->prepare('
                UPDATE staff
                SET admin_flg = :a, circ_flg = :c, circ_mbr_flg = :cm,
                    catalog_flg = :cat, reports_flg = :r, last_change_dt = NOW()
                WHERE userid = :id LIMIT 1
            ')->execute([
                ':a'   => (isset($_POST['admin_flg'])    && $_POST['admin_flg']    === 'Y') ? 'Y' : 'N',
                ':c'   => (isset($_POST['circ_flg'])     && $_POST['circ_flg']     === 'Y') ? 'Y' : 'N',
                ':cm'  => (isset($_POST['circ_mbr_flg']) && $_POST['circ_mbr_flg'] === 'Y') ? 'Y' : 'N',
                ':cat' => (isset($_POST['catalog_flg'])  && $_POST['catalog_flg']  === 'Y') ? 'Y' : 'N',
                ':r'   => (isset($_POST['reports_flg'])  && $_POST['reports_flg']  === 'Y') ? 'Y' : 'N',
                ':id'  => $uid,
            ]);
            $messages[] = 'Permessi aggiornati.';
        } catch (Throwable) { $errors[] = 'Errore aggiornamento permessi.'; }
    }

    if ($action === 'gen_reset' && $uid > 0) {
        try {
            $pdo->prepare('DELETE FROM staff_password_reset WHERE userid = :id')->execute([':id' => $uid]);
            $rawToken  = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $now       = date('Y-m-d H:i:s');
            $expires   = date('Y-m-d H:i:s', time() + 86400);
            $pdo->prepare('INSERT INTO staff_password_reset (userid, token_hash, created_at, expires_at, used) VALUES (:u, :th, :ca, :ea, 0)')
                ->execute([':u' => $uid, ':th' => $tokenHash, ':ca' => $now, ':ea' => $expires]);
            $resetLink = rtrim($baseUrl, '/') . '/index.php?page=staff_reset&token=' . urlencode($rawToken);
            $messages[] = 'Link reset generato (valido 24 ore).';
        } catch (Throwable) { $errors[] = 'Errore generazione link reset.'; }
    }

    if ($action === 'update_info' && $uid > 0) {
        $fn    = trim((string)($_POST['first_name'] ?? ''));
        $ln    = trim((string)($_POST['last_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        if ($ln === '') {
            $errors[] = 'Il cognome è obbligatorio.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email non valida.';
        } else {
            try {
                $pdo->prepare('UPDATE staff SET first_name = :fn, last_name = :ln, email = :em, last_change_dt = NOW() WHERE userid = :id LIMIT 1')
                    ->execute([':fn' => $fn !== '' ? $fn : null, ':ln' => $ln, ':em' => $email !== '' ? $email : null, ':id' => $uid]);
                $messages[] = 'Dati anagrafici aggiornati.';
            } catch (Throwable) { $errors[] = 'Errore aggiornamento dati.'; }
        }
    }
    } // end csrf_verify
}

// =============================================================================
// Carica lista staff
// =============================================================================
$staffList = [];
try {
    $staffList = $pdo->query('
        SELECT userid, username, first_name, last_name, email,
               suspended_flg, admin_flg, circ_flg, circ_mbr_flg, catalog_flg, reports_flg,
               create_dt, last_change_dt
        FROM staff
        ORDER BY last_name, first_name, username
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException) { $errors[] = 'Errore caricamento lista staff.'; }

$editUid = (int)($_GET['edit'] ?? 0);
?>
<section class="page-section page-staff">
    <header class="staff-header">
        <div class="staff-header-top">
            <div class="staff-header-main">
                <h1>Elenco account staff</h1>
                <p class="staff-header-subtitle">Vista completa degli operatori registrati. Solo gli amministratori possono modificare permessi e sospendere account.</p>
            </div>
        </div>
    </header>

    <?php if (!empty($messages)): ?>
    <div class="alert--success"><?php foreach ($messages as $m): ?><p><?= h($m) ?></p><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
    <div class="alert--error"><?php foreach ($errors as $m): ?><p><?= h($m) ?></p><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if ($resetLink !== ''): ?>
    <div style="margin-top:0.75rem;padding:0.75rem;background:#f0f9ff;border:1px solid #7dd3fc;border-radius:6px;">
        <p style="margin:0 0 0.4rem;font-size:0.88rem;font-weight:600;">Link reset password (valido 24 ore):</p>
        <input type="text" readonly value="<?= h($resetLink) ?>" onclick="this.select()"
               style="width:100%;font-size:0.85rem;font-family:monospace;padding:0.35rem 0.5rem;border:1px solid #bae6fd;border-radius:4px;">
    </div>
    <?php endif; ?>

    <div style="margin:0.75rem 0;display:flex;gap:0.75rem;align-items:center;">
        <a class="btn-primary" href="<?= h($baseUrl) ?>/index.php?page=staff_user_add">+ Nuovo account staff</a>
        <a class="btn-link" href="<?= h($baseUrl) ?>/index.php?page=staff">Dashboard</a>
    </div>

    <div style="overflow-x:auto;">
        <table class="copy-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Nome</th>
                    <th>Email</th>
                    <th style="text-align:center;">Amm.</th>
                    <th style="text-align:center;">Circ.</th>
                    <th style="text-align:center;">Utenti</th>
                    <th style="text-align:center;">Cat.</th>
                    <th style="text-align:center;">Rep.</th>
                    <th>Stato</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($staffList as $su):
                $sid       = (int)$su['userid'];
                $fullName  = trim(($su['first_name'] ?? '') . ' ' . ($su['last_name'] ?? ''));
                $isSelf    = ($sid === $currentUid);
                $suspended = ($su['suspended_flg'] === 'Y');
                $isEditing = ($editUid === $sid);
            ?>
            <tr style="<?= $suspended ? 'opacity:.55;' : '' ?>">
                <td style="font-family:monospace;font-weight:600;"><?= h($su['username']) ?><?= $isSelf ? ' <small style="color:#16a34a;">(tu)</small>' : '' ?></td>
                <td><?= h($fullName) ?></td>
                <td style="font-size:0.85rem;"><?= h($su['email'] ?? '') ?></td>
                <?php
                $flags = [
                    'admin_flg' => $su['admin_flg'],
                    'circ_flg'  => $su['circ_flg'],
                    'circ_mbr_flg' => $su['circ_mbr_flg'],
                    'catalog_flg'  => $su['catalog_flg'],
                    'reports_flg'  => $su['reports_flg'],
                ];
                foreach ($flags as $f):
                ?>
                <td style="text-align:center;"><?= $f === 'Y' ? '<span style="color:#16a34a;font-size:1rem;">✓</span>' : '<span style="color:#d1d5db;">–</span>' ?></td>
                <?php endforeach; ?>
                <td>
                    <?php if ($suspended): ?>
                    <span style="color:#dc2626;font-size:0.82rem;font-weight:600;">Sospeso</span>
                    <?php else: ?>
                    <span style="color:#16a34a;font-size:0.82rem;">Attivo</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                        <?php if (!$isSelf): ?>
                        <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_users" style="display:inline"
                              onsubmit="return confirm('<?= $suspended ? 'Riattivare' : 'Sospendere' ?> questo account?')">
                            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="toggle_suspend">
                            <input type="hidden" name="userid" value="<?= $sid ?>">
                            <button type="submit" class="btn-link<?= $suspended ? '' : '--danger' ?>" style="font-size:0.82rem;">
                                <?= $suspended ? 'Riattiva' : 'Sospendi' ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <a class="btn-link" style="font-size:0.82rem;" href="<?= h($baseUrl) ?>/index.php?page=staff_users&edit=<?= $sid ?>#u<?= $sid ?>">
                            Modifica
                        </a>
                        <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_users" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="gen_reset">
                            <input type="hidden" name="userid" value="<?= $sid ?>">
                            <button type="submit" class="btn-link" style="font-size:0.82rem;">Reset pwd</button>
                        </form>
                    </div>
                </td>
            </tr>

            <!-- Riga modifica -->
            <?php if ($isEditing): ?>
            <tr id="u<?= $sid ?>" style="background:#f9fafb;">
                <td colspan="10" style="padding:1rem;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <!-- Dati anagrafici -->
                        <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_users#u<?= $sid ?>">
                            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_info">
                            <input type="hidden" name="userid" value="<?= $sid ?>">
                            <h4 style="margin:0 0 0.5rem;">Dati anagrafici</h4>
                            <div class="search-row"><label>Nome</label><input type="text" name="first_name" value="<?= h($su['first_name'] ?? '') ?>"></div>
                            <div class="search-row"><label>Cognome *</label><input type="text" name="last_name" value="<?= h($su['last_name'] ?? '') ?>" required></div>
                            <div class="search-row"><label>Email</label><input type="email" name="email" value="<?= h($su['email'] ?? '') ?>"></div>
                            <div class="search-actions" style="margin-top:0.5rem;">
                                <button type="submit" class="btn-primary" style="padding:0.35rem 0.7rem;font-size:0.85rem;">Salva</button>
                                <a class="btn-secondary" style="font-size:0.85rem;" href="<?= h($baseUrl) ?>/index.php?page=staff_users">Chiudi</a>
                            </div>
                        </form>

                        <!-- Permessi (non modificabili su sé stessi) -->
                        <?php if (!$isSelf): ?>
                        <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_users#u<?= $sid ?>">
                            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_perms">
                            <input type="hidden" name="userid" value="<?= $sid ?>">
                            <h4 style="margin:0 0 0.5rem;">Permessi</h4>
                            <?php
                            $permLabels = [
                                'admin_flg'    => 'Amministratore',
                                'circ_flg'     => 'Circolazione',
                                'circ_mbr_flg' => 'Gestione lettori',
                                'catalog_flg'  => 'Catalogazione',
                                'reports_flg'  => 'Report e statistiche',
                            ];
                            foreach ($permLabels as $fname => $flabel):
                            ?>
                            <div class="search-row" style="margin-bottom:0.3rem;">
                                <label style="display:flex;align-items:center;gap:0.4rem;font-weight:normal;">
                                    <input type="checkbox" name="<?= $fname ?>" value="Y"
                                           <?= ($su[$fname] ?? 'N') === 'Y' ? 'checked' : '' ?>>
                                    <?= h($flabel) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                            <div class="search-actions" style="margin-top:0.5rem;">
                                <button type="submit" class="btn-primary" style="padding:0.35rem 0.7rem;font-size:0.85rem;">Salva permessi</button>
                            </div>
                        </form>
                        <?php else: ?>
                        <div style="padding:0.5rem;color:#6b7280;font-size:0.88rem;">
                            Non puoi modificare i tuoi permessi. Chiedi a un altro amministratore.
                        </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
