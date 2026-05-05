<?php
if (!$auth->check() || !$auth->can('catalog')) { redirect(($cfg['app']['base_url'] ?? '') . '/index.php?page=login'); }
require_once __DIR__ . '/../lib/Validate.php';
$T = $cfg['tables'];
$bibid = (int)($_GET['bibid'] ?? 0);
$editing = $bibid > 0;
$title = $editing ? 'Modifica titolo' : 'Nuovo titolo';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { $err = 'Token CSRF non valido'; }
  else {
    $data = [
      'title' => V::str($_POST['title'] ?? '', 255),
      'title_remainder' => V::str($_POST['title_remainder'] ?? '', 255),
      'author' => V::str($_POST['author'] ?? '', 255),
      'topic1' => V::str($_POST['topic1'] ?? '', 255),
      'topic2' => V::str($_POST['topic2'] ?? '', 255),
      'topic3' => V::str($_POST['topic3'] ?? '', 255),
      'topic4' => V::str($_POST['topic4'] ?? '', 255),
      'topic5' => V::str($_POST['topic5'] ?? '', 255),
      'isbn'   => V::str($_POST['isbn'] ?? '', 32),
      'opac_flg' => V::yn($_POST['opac_flg'] ?? 'Y'),
      'summary' => V::str($_POST['summary'] ?? '', 2000),
    ];

    try {
      if (isset($_POST['delete']) && $editing) {
        $db->beginTransaction();
        $db->prepare("DELETE FROM {$T['biblio']} WHERE bibid=? LIMIT 1")->execute([$bibid]);
        $db->commit();
        redirect(($cfg['app']['base_url'] ?? '') . '/index.php?page=admin_items');
      }

      if ($editing) {
        $sql = "UPDATE {$T['biblio']} SET title=?, title_remainder=?, author=?, topic1=?, topic2=?, topic3=?, topic4=?, topic5=?, isbn=?, opac_flg=?, update_dt=NOW() WHERE bibid=?";
        $db->prepare($sql)->execute([
          $data['title'],$data['title_remainder'],$data['author'],$data['topic1'],$data['topic2'],$data['topic3'],$data['topic4'],$data['topic5'],$data['isbn'],$data['opac_flg'],$bibid
        ]);
      } else {
        $sql = "INSERT INTO {$T['biblio']}(title, title_remainder, author, topic1, topic2, topic3, topic4, topic5, isbn, opac_flg, create_dt, update_dt) VALUES(?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";
        $db->prepare($sql)->execute([
          $data['title'],$data['title_remainder'],$data['author'],$data['topic1'],$data['topic2'],$data['topic3'],$data['topic4'],$data['topic5'],$data['isbn'],$data['opac_flg']
        ]);
        $bibid = (int)$db->lastInsertId();
        $editing = true;
      }
      redirect(($cfg['app']['base_url'] ?? '') . '/index.php?page=admin_items');
    } catch (Throwable $e) {
      if ($db->inTransaction()) $db->rollBack();
      $err = 'Errore DB: ' . h($e->getMessage());
    }
  }
}

$rec = [
  'title' => '', 'title_remainder' => '', 'author' => '', 'topic1' => '', 'topic2' => '', 'topic3' => '', 'topic4' => '', 'topic5' => '', 'isbn' => '', 'opac_flg' => 'Y', 'summary' => ''
];
if ($editing) {
  $st = $db->prepare("SELECT * FROM {$T['biblio']} WHERE bibid=? LIMIT 1");
  $st->execute([$bibid]);
  $got = $st->fetch();
  if ($got) { $rec = array_merge($rec, $got); }
}
?>
<section class="card" style="margin-top:20px">
  <?php if ($err): ?><p style="color:#b00020"><?= $err ?></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <label>Titolo<br><input class="input" name="title" required value="<?= h($rec['title']) ?>"></label>
      <label>Sottotitolo<br><input class="input" name="title_remainder" value="<?= h($rec['title_remainder']) ?>"></label>
      <label>Autore<br><input class="input" name="author" value="<?= h($rec['author']) ?>"></label>
      <label>ISBN<br><input class="input" name="isbn" value="<?= h($rec['isbn']) ?>"></label>
      <label>OPAC visibile<br>
        <select class="input" name="opac_flg">
          <option value="Y" <?= ($rec['opac_flg']==='Y'?'selected':'') ?>>Sì</option>
          <option value="N" <?= ($rec['opac_flg']==='N'?'selected':'') ?>>No</option>
        </select>
      </label>
      <div></div>
      <label>Soggetto 1<br><input class="input" name="topic1" value="<?= h($rec['topic1']) ?>"></label>
      <label>Soggetto 2<br><input class="input" name="topic2" value="<?= h($rec['topic2']) ?>"></label>
      <label>Soggetto 3<br><input class="input" name="topic3" value="<?= h($rec['topic3']) ?>"></label>
      <label>Soggetto 4<br><input class="input" name="topic4" value="<?= h($rec['topic4']) ?>"></label>
      <label>Soggetto 5<br><input class="input" name="topic5" value="<?= h($rec['topic5']) ?>"></label>
      <label>Riassunto<br><textarea class="input" name="summary" rows="4"><?= h($rec['summary']) ?></textarea></label>
    </div>
    <div style="margin-top:12px;display:flex;gap:8px">
      <button class="button" type="submit">Salva</button>
      <?php if ($editing): ?>
        <button class="button secondary" type="submit" name="delete" value="1" onclick="return confirm('Eliminare definitivamente il titolo? Verranno eliminate anche le copie collegate.');">Elimina</button>
      <?php endif; ?>
      <a class="button secondary" href="<?= h($cfg['app']['base_url']) ?>/index.php?page=admin_items">Annulla</a>
    </div>
  </form>
</section>
