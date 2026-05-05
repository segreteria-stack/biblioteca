<?php
if (!$auth->check() || !$auth->can('catalog')) { redirect(($cfg['app']['base_url'] ?? '') . '/index.php?page=login'); }
$title = 'Titoli';
$q = trim($_GET['q'] ?? '');
$T = $cfg['tables'];

$sql = "SELECT bibid, TRIM(CONCAT_WS(' ', title, title_remainder)) AS title, author, opac_flg
        FROM {$T['biblio']} ";
$params = [];
if ($q !== '') {
  $sql .= "WHERE title LIKE :q OR title_remainder LIKE :q OR author LIKE :q ";
  $params[':q'] = "%$q%";
}
$sql .= "ORDER BY bibid DESC LIMIT 100";
$st = $db->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();
?>
<section class="card" style="margin-top:20px">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
    <h1 style="margin:0">Titoli</h1>
    <a class="button" href="<?= h($cfg['app']['base_url']) ?>/index.php?page=admin_item_edit">+ Nuovo titolo</a>
  </div>
  <form method="get" style="margin-top:10px">
    <input type="hidden" name="page" value="admin_items">
    <input class="input" type="text" name="q" value="<?= h($q) ?>" placeholder="Cerca per titolo o autore">
  </form>

  <ul style="list-style:none;padding:0;margin:16px 0 0">
    <?php if (!$rows): ?><li>Nessun titolo</li><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <li style="padding:10px 0;border-top:1px solid #eee;display:flex;justify-content:space-between;align-items:center">
        <div>
          <strong>#<?= (int)$r['bibid'] ?></strong> — <?= h($r['title']) ?>
          <div style="color:#555"><?= h($r['author'] ?? '') ?></div>
        </div>
        <div style="display:flex;gap:8px">
          <a class="button secondary" href="<?= h($cfg['app']['base_url']) ?>/index.php?page=admin_item_edit&bibid=<?= (int)$r['bibid'] ?>">Modifica</a>
          <a class="button secondary" href="<?= h($cfg['app']['base_url']) ?>/index.php?page=admin_holdings&bibid=<?= (int)$r['bibid'] ?>">Copie</a>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
