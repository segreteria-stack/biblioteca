<?php
if (!$auth->check() || !$auth->can('catalog')) { redirect(($cfg['app']['base_url'] ?? '') . '/index.php?page=login'); }
require_once __DIR__ . '/../lib/Validate.php';
$T = $cfg['tables'];
$bibid = (int)($_GET['bibid'] ?? 0);
$title = 'Copie';
$err = '';

$st = $db->prepare("SELECT bibid, TRIM(CONCAT_WS(' ', title, title_remainder)) AS title FROM {$T['biblio']} WHERE bibid=?");
$st->execute([$bibid]);
$book = $st->fetch();
if (!$book) { redirect(($cfg['app']['base_url'] ?? '') . '/index.php?page=admin_items'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { $err = 'Token CSRF non valido'; }
  else try {
    if (isset($_POST['delete'])) {
      $copyid = (int)$_POST['copyid'];
      $db->prepare("DELETE FROM {$T['biblio_copy']} WHERE bibid=? AND copyid=? LIMIT 1")->execute([$bibid, $copyid]);
    } else if (isset($_POST['update'])) {
      $copyid = (int)$_POST['copyid'];
      $desc = V::str($_POST['copy_desc'] ?? '', 255);
      $barcode = V::str($_POST['barcode_nmbr'] ?? '', 80);
      $status = V::str($_POST['status_cd'] ?? 'in', 8);
      $sql = "UPDATE {$T['biblio_copy']} SET copy_desc=?, barcode_nmbr=?, status_cd=?, status_begin_dt=NOW() WHERE bibid=? AND copyid=?";
      $db->prepare($sql)->execute([$desc,$barcode,$status,$bibid,$copyid]);
    } else if (isset($_POST['add'])) {
      $desc = V::str($_POST['copy_desc'] ?? '', 255);
      $barcode = V::str($_POST['barcode_nmbr'] ?? '', 80);
      $status = V::str($_POST['status_cd'] ?? 'in', 8);
      $db->beginTransaction();
      $next = $db->prepare("SELECT COALESCE(MAX(copyid)+1,1) AS next_id FROM {$T['biblio_copy']} WHERE bibid=?");
      $next->execute([$bibid]);
      $copyid = (int)($next->fetch()['next_id'] ?? 1);
      $ins = $db->prepare("INSERT INTO {$T['biblio_copy']} (bibid, copyid, copy_desc, barcode_nmbr, status_cd, create_dt, status_begin_dt) VALUES(?,?,?,?,?,NOW(),NOW())");
      $ins->execute([$bibid, $copyid, $desc, $barcode, $status]);
      $db->commit();
    }
    redirect(($cfg['app']['base_url'] ?? '') . '/index.php?page=admin_holdings&bibid=' . $bibid);
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $err = 'Errore DB: ' . h($e->getMessage());
  }
}

$hc = $db->prepare("SELECT copyid, barcode_nmbr, copy_desc, status_cd,
   NULLIF(due_back_dt,'0000-00-00') AS due_back_dt,
   NULLIF(status_begin_dt,'0000-00-00 00:00:00') AS status_begin_dt
  FROM {$T['biblio_copy']} WHERE bibid=? ORDER BY copyid");
$hc->execute([$bibid]);
$copies = $hc->fetchAll();
?>
<section class="card" style="margin-top:20px">
  <h1 style="margin:0 0 8px 0">Copie — #<?= (int)$book['bibid'] ?> · <?= h($book['title']) ?></h1>
  <?php if ($err): ?><p style="color:#b00020"><?= $err ?></p><?php endif; ?>

  <table style="width:100%;border-collapse:collapse">
    <thead>
      <tr style="border-bottom:1px solid #eee;text-align:left">
        <th style="padding:8px">Copia</th>
        <th style="padding:8px">Barcode</th>
        <th style="padding:8px">Descrizione</th>
        <th style="padding:8px">Stato</th>
        <th style="padding:8px;width:1%"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($copies as $c): ?>
      <tr style="border-top:1px solid #f1f1f1">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="copyid" value="<?= (int)$c['copyid'] ?>">
          <td style="padding:8px"><strong>#<?= (int)$c['copyid'] ?></strong></td>
          <td style="padding:8px"><input class="input" name="barcode_nmbr" value="<?= h($c['barcode_nmbr']) ?>"></td>
          <td style="padding:8px"><input class="input" name="copy_desc" value="<?= h($c['copy_desc']) ?>"></td>
          <td style="padding:8px">
            <select class="input" name="status_cd">
              <?php foreach (['in'=>'Disponibile','out'=>'Prestato','co'=>'Prestito','lst'=>'Smarrito'] as $k=>$v): ?>
                <option value="<?= h($k) ?>" <?= ($c['status_cd']===$k?'selected':'') ?>><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td style="padding:8px;white-space:nowrap">
            <button class="button" name="update" value="1">Salva</button>
            <button class="button secondary" name="delete" value="1" onclick="return confirm('Eliminare questa copia?');">Elimina</button>
          </td>
        </form>
      </tr>
    <?php endforeach; ?>
      <tr style="border-top:2px solid #eee;background:#fafafa">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <td style="padding:8px"><em>Nuova</em></td>
          <td style="padding:8px"><input class="input" name="barcode_nmbr"></td>
          <td style="padding:8px"><input class="input" name="copy_desc"></td>
          <td style="padding:8px">
            <select class="input" name="status_cd">
              <option value="in">Disponibile</option>
              <option value="out">Prestato</option>
              <option value="co">Prestito</option>
              <option value="lst">Smarrito</option>
            </select>
          </td>
          <td style="padding:8px"><button class="button" name="add" value="1">Aggiungi</button></td>
        </form>
      </tr>
    </tbody>
  </table>

  <p style="margin-top:16px"><a class="button secondary" href="<?= h($cfg['app']['base_url']) ?>/index.php?page=admin_items">← Torna ai titoli</a></p>
</section>
