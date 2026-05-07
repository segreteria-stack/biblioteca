<?php
/**
 * Area Staff – Gestione soggetti / tag
 *
 * Visualizza tutti i soggetti usati nei campi topic1-5 della tabella biblio,
 * con conteggio dei titoli collegati. Permette di rinominare un soggetto
 * (aggiorna topic1-5 su tutti i record che lo contengono).
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    /** @var array<string,mixed> $cfg */
    $baseUrl = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/');
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=staff_topics');
    exit;
}

/** @var array<string,mixed> $cfg */
$baseUrl = rtrim((string)($cfg['app']['base_url'] ?? '/public'), '/');
$pdo     = DB::conn();
$errors  = [];
$messages = [];

// =============================================================================
// POST – rinomina soggetto
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? '')) {
        $errors[] = 'Sessione scaduta o token non valido, riprova.';
    } else {
    $action  = trim((string)($_POST['action'] ?? ''));
    $oldName = trim((string)($_POST['old_name'] ?? ''));
    $newName = trim((string)($_POST['new_name'] ?? ''));

    if ($action === 'rename') {
        if ($oldName === '') {
            $errors[] = 'Il nome originale non può essere vuoto.';
        } elseif ($newName === '') {
            $errors[] = 'Il nuovo nome non può essere vuoto.';
        } elseif ($oldName === $newName) {
            $errors[] = 'Il nuovo nome è identico all\'originale.';
        } else {
            try {
                $total = 0;
                for ($i = 1; $i <= 5; $i++) {
                    $col = 'topic' . $i;
                    $stmt = $pdo->prepare("UPDATE biblio SET $col = :new WHERE $col = :old");
                    $stmt->execute([':new' => $newName, ':old' => $oldName]);
                    $total += $stmt->rowCount();
                }
                $messages[] = 'Soggetto rinominato: "' . $oldName . '" → "' . $newName . '" (' . $total . ' record aggiornati).';
            } catch (Throwable $e) {
                $errors[] = 'Errore durante la rinomina: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete') {
        $oldName = trim((string)($_POST['old_name'] ?? ''));
        if ($oldName === '') {
            $errors[] = 'Soggetto non specificato.';
        } else {
            try {
                $total = 0;
                for ($i = 1; $i <= 5; $i++) {
                    $col  = 'topic' . $i;
                    $stmt = $pdo->prepare("UPDATE biblio SET $col = '' WHERE $col = :old");
                    $stmt->execute([':old' => $oldName]);
                    $total += $stmt->rowCount();
                }
                $messages[] = 'Soggetto "' . $oldName . '" rimosso da ' . $total . ' record.';
            } catch (Throwable $e) {
                $errors[] = 'Errore durante la rimozione.';
            }
        }
    }
    } // end csrf_verify
}

// =============================================================================
// GET – carica elenco soggetti
// =============================================================================
$filter = trim((string)($_GET['q'] ?? ''));
$sort   = in_array($_GET['sort'] ?? '', ['cnt', 'name'], true) ? ($_GET['sort'] ?? 'name') : 'name';

$topics = [];
try {
    $filterPat = $filter !== '' ? '%' . $filter . '%' : '%';
    $stmt = $pdo->prepare("
        SELECT topic, COUNT(*) AS cnt
        FROM (
            SELECT topic1 AS topic FROM biblio WHERE topic1 IS NOT NULL AND topic1 <> '' AND topic1 LIKE :q
            UNION ALL
            SELECT topic2 FROM biblio WHERE topic2 IS NOT NULL AND topic2 <> '' AND topic2 LIKE :q
            UNION ALL
            SELECT topic3 FROM biblio WHERE topic3 IS NOT NULL AND topic3 <> '' AND topic3 LIKE :q
            UNION ALL
            SELECT topic4 FROM biblio WHERE topic4 IS NOT NULL AND topic4 <> '' AND topic4 LIKE :q
            UNION ALL
            SELECT topic5 FROM biblio WHERE topic5 IS NOT NULL AND topic5 <> '' AND topic5 LIKE :q
        ) t
        GROUP BY topic
        ORDER BY " . ($sort === 'cnt' ? 'cnt DESC, topic ASC' : 'topic ASC') . "
    ");
    $stmt->execute([':q' => $filterPat]);
    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $errors[] = 'Errore caricamento soggetti.';
}

$totalTopics = count($topics);
?>
<section class="page-section page-staff">
    <header class="staff-header">
        <div class="staff-header-top">
            <div class="staff-header-main">
                <h1>Gestione soggetti / tag</h1>
                <p class="staff-header-subtitle">Visualizza, rinomina o rimuovi i soggetti usati in "Esplora per tema".</p>
            </div>
        </div>
    </header>

    <?php if (!empty($messages)): ?>
    <div class="alert--success"><?php foreach ($messages as $m): ?><p><?= h($m) ?></p><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
    <div class="alert--error"><?php foreach ($errors as $m): ?><p><?= h($m) ?></p><?php endforeach; ?></div>
    <?php endif; ?>

    <!-- Filtro e ordinamento -->
    <div class="staff-block" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">
        <form method="get" action="<?= h($baseUrl) ?>/index.php" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end;flex:1 1 300px;">
            <input type="hidden" name="page" value="staff_topics">
            <div class="copy-inline-field" style="flex:1 1 200px;">
                <label for="q-topic">Filtra soggetto</label>
                <input type="text" id="q-topic" name="q" value="<?= h($filter) ?>" placeholder="Ricerca…">
            </div>
            <div class="copy-inline-field">
                <label for="sort-sel">Ordina per</label>
                <select id="sort-sel" name="sort">
                    <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Alfabetico</option>
                    <option value="cnt" <?= $sort === 'cnt'  ? 'selected' : '' ?>>Frequenza ↓</option>
                </select>
            </div>
            <button type="submit" class="btn-primary">Filtra</button>
            <?php if ($filter !== ''): ?>
            <a class="btn-secondary" href="<?= h($baseUrl) ?>/index.php?page=staff_topics&sort=<?= h($sort) ?>">Reset</a>
            <?php endif; ?>
        </form>
        <a class="btn-link" style="align-self:flex-end;" href="<?= h($baseUrl) ?>/index.php?page=staff">Dashboard</a>
    </div>

    <!-- Statistiche rapide -->
    <p style="font-size:0.88rem;color:#6b7280;margin:0.25rem 0 0.75rem;">
        <?= $totalTopics ?> soggett<?= $totalTopics === 1 ? 'o' : 'i' ?> unici<?= $filter !== '' ? ' (filtrati)' : '' ?>.
    </p>

    <!-- Elenco -->
    <?php if (empty($topics)): ?>
    <p style="color:#6b7280;">Nessun soggetto trovato.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="copy-table">
            <thead>
                <tr>
                    <th style="width:60%;">Soggetto</th>
                    <th style="width:8%;text-align:right;">Titoli</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($topics as $row):
                $topicVal = (string)$row['topic'];
                $cnt      = (int)$row['cnt'];
            ?>
            <tr>
                <td>
                    <span id="view-t-<?= h(urlencode($topicVal)) ?>"><?= h($topicVal) ?></span>
                    <form id="form-t-<?= h(urlencode($topicVal)) ?>" method="post"
                          action="<?= h($baseUrl) ?>/index.php?page=staff_topics<?= $filter !== '' ? '&q=' . urlencode($filter) : '' ?>&sort=<?= h($sort) ?>"
                          style="display:none;margin-top:0.35rem;">
                        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="rename">
                        <input type="hidden" name="old_name" value="<?= h($topicVal) ?>">
                        <div style="display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap;">
                            <input type="text" name="new_name" value="<?= h($topicVal) ?>"
                                   style="flex:1 1 180px;padding:0.3rem 0.45rem;font-size:0.88rem;"
                                   required maxlength="200">
                            <button type="submit" class="btn-primary" style="padding:0.3rem 0.6rem;font-size:0.82rem;">Salva</button>
                            <button type="button" class="btn-secondary" style="padding:0.3rem 0.6rem;font-size:0.82rem;"
                                    onclick="cancelEdit(this)">Annulla</button>
                        </div>
                    </form>
                </td>
                <td style="text-align:right;">
                    <a href="<?= h($baseUrl) ?>/index.php?page=search&subject=<?= urlencode($topicVal) ?>"
                       target="_blank" style="font-size:0.85rem;color:#374151;"><?= $cnt ?></a>
                </td>
                <td>
                    <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                        <button type="button" class="btn-link"
                                onclick="startEdit(this)"
                                data-key="<?= h(urlencode($topicVal)) ?>"
                                style="font-size:0.85rem;">Rinomina</button>
                        <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_topics<?= $filter !== '' ? '&q=' . urlencode($filter) : '' ?>&sort=<?= h($sort) ?>"
                              style="display:inline"
                              onsubmit="return confirm('Rimuovere il soggetto &quot;<?= h(addslashes($topicVal)) ?>&quot; da tutti i record? L\'operazione non può essere annullata.')">
                            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="old_name" value="<?= h($topicVal) ?>">
                            <button type="submit" class="btn-link--danger" style="font-size:0.82rem;">Rimuovi</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<script>
function startEdit(btn) {
    var key = btn.getAttribute('data-key');
    document.getElementById('view-t-' + key).style.display = 'none';
    btn.closest('td').querySelector('[data-key]').style.display = 'none';
    document.getElementById('form-t-' + key).style.display = 'block';
}
function cancelEdit(btn) {
    var form = btn.closest('form');
    var key  = form.id.replace('form-t-', '');
    form.style.display = 'none';
    document.getElementById('view-t-' + key).style.display = '';
    form.closest('tr').querySelector('[data-key]').style.display = '';
}
</script>
