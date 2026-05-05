<?php
// pages/admin_import_marc.php
// Import MARCXML (no librerie esterne). Mappa campi base su tabella `biblio` di Espabiblio.
// Permessi: staff con ruolo 'catalog' o 'admin'.
if (!$auth->check() || !$auth->can('catalog')) { redirect(($cfg['app']['base_url'] ?? '') . '/index.php?page=login'); }
$title = 'Import MARCXML';
$T = $cfg['tables'] + ['biblio'=>'biblio','biblio_copy'=>'biblio_copy'];
$err = ''; $ok = [];

function marc_subfields(SimpleXMLElement $record, string $tag, string $code): array {
  $vals = [];
  foreach ($record->datafield as $df) {
    $attrTag = (string) $df['tag'];
    if ($attrTag === $tag) {
      foreach ($df->subfield as $sf) {
        if ((string)$sf['code'] === $code) {
          $vals[] = trim((string)$sf);
        }
      }
    }
  }
  return $vals;
}
function marc_field(SimpleXMLElement $record, string $tag, string $code): ?string {
  $vals = marc_subfields($record, $tag, $code);
  return $vals ? $vals[0] : null;
}
function marc_topics(SimpleXMLElement $record, int $max=5): array {
  $topics = [];
  foreach ($record->datafield as $df) {
    if ((string)$df['tag'] === '650') {
      foreach ($df->subfield as $sf) {
        if ((string)$sf['code'] === 'a') {
          $v = trim((string)$sf);
          if ($v !== '') { $topics[] = $v; }
          if (count($topics) >= $max) { return $topics; }
        }
      }
    }
  }
  return $topics;
}
function marc_isbn(SimpleXMLElement $record): ?string {
  $v = marc_field($record, '020', 'a');
  if (!$v) return null;
  // Pulisci: tieni cifre, X/x
  $v = strtoupper(preg_replace('/[^0-9Xx]/', '', $v));
  return $v ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) { $err = 'Token CSRF non valido'; }
  elseif (!isset($_FILES['marcxml']) || $_FILES['marcxml']['error'] !== UPLOAD_ERR_OK) {
    $err = 'Caricamento file non riuscito.';
  } else {
    $createCopy = isset($_POST['create_copy']);
    $xml = @file_get_contents($_FILES['marcxml']['tmp_name']);
    if ($xml === false) { $err = 'Impossibile leggere il file.'; }
    else {
      try {
        $sx = new SimpleXMLElement($xml);
        // accetta sia <collection> che singolo <record>
        $records = [];
        if ($sx->getName() === 'collection') {
          foreach ($sx->record as $r) { $records[] = $r; }
        } elseif ($sx->getName() === 'record') {
          $records[] = $sx;
        } else {
          throw new Exception('Formato XML non riconosciuto (atteso <collection> o <record>)');
        }

        $insBiblio = $db->prepare("INSERT INTO {$T['biblio']}
          (title, title_remainder, author, topic1, topic2, topic3, topic4, topic5, isbn, opac_flg, create_dt, update_dt)
          VALUES (?,?,?,?,?,?,?,?,?,'Y',NOW(),NOW())");

        $insCopy = $db->prepare("INSERT INTO {$T['biblio_copy']}
          (bibid, copyid, copy_desc, barcode_nmbr, status_cd, create_dt, status_begin_dt)
          VALUES (?,?,?,?, 'in', NOW(), NOW())");

        $db->beginTransaction();
        $count = 0;
        foreach ($records as $rec) {
          // 245 a/b: titolo e sottotitolo
          $title_main = marc_field($rec, '245', 'a') ?? '';
          $title_rem  = marc_field($rec, '245', 'b') ?? '';
          // 100 a (o 110/111): autore/ente
          $author = marc_field($rec, '100', 'a') ?? (marc_field($rec, '110', 'a') ?? (marc_field($rec, '111', 'a') ?? ''));
          // 650 a: soggetti (max 5)
          $topics = marc_topics($rec, 5);
          $t = array_pad($topics, 5, null);
          // 020 a: ISBN
          $isbn = marc_isbn($rec);

          $insBiblio->execute([
            $title_main, $title_rem, $author, $t[0], $t[1], $t[2], $t[3], $t[4], $isbn
          ]);
          $bibid = (int)$db->lastInsertId();

          if ($createCopy) {
            // Calcola prossimo copyid per quel bibid
            $next = $db->prepare("SELECT COALESCE(MAX(copyid)+1,1) AS next_id FROM {$T['biblio_copy']} WHERE bibid=?");
            $next->execute([$bibid]);
            $copyid = (int)($next->fetch()['next_id'] ?? 1);
            $insCopy->execute([$bibid, $copyid, 'Copia importata', null]);
          }

          $count++;
        }
        $db->commit();
        $ok[] = "Import completato: {$count} record.";
      } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $err = 'Errore import: ' . h($e->getMessage());
      }
    }
  }
}
?>
<section class="card" style="margin-top:20px;max-width:720px">
  <h1 style="margin-top:0">Import MARCXML</h1>
  <p>Carica un file in formato <strong>MARCXML</strong> (singolo &lt;record&gt; o &lt;collection&gt;). Verranno valorizzati i campi base: <code>245$a</code> (titolo), <code>245$b</code> (sottotitolo), <code>100$a</code> (autore), <code>650$a</code> (soggetti, max 5), <code>020$a</code> (ISBN).</p>
  <?php if ($err): ?><p style="color:#b00020"><strong><?= $err ?></strong></p><?php endif; ?>
  <?php foreach ($ok as $msg): ?><p style="color:#0a7f2e"><strong><?= h($msg) ?></strong></p><?php endforeach; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>File MARCXML (.xml)<br>
      <input class="input" type="file" name="marcxml" accept=".xml" required>
    </label>
    <label style="display:flex;align-items:center;gap:8px;margin-top:8px">
      <input type="checkbox" name="create_copy" value="1">
      Crea anche una copia #1 disponibile per ogni record
    </label>
    <div style="margin-top:12px">
      <button class="button" type="submit">Importa</button>
    </div>
  </form>

  <details style="margin-top:16px">
    <summary>Note di mapping</summary>
    <ul>
      <li><code>245$a</code> → <code>biblio.title</code></li>
      <li><code>245$b</code> → <code>biblio.title_remainder</code></li>
      <li><code>100$a</code> (o <code>110$a</code>/<code>111$a</code>) → <code>biblio.author</code></li>
      <li>Fino a 5 × <code>650$a</code> → <code>biblio.topic1..topic5</code></li>
      <li><code>020$a</code> (ripulito) → <code>biblio.isbn</code></li>
      <li><em>Opzionale</em>: crea copia #1 con <code>status_cd='in'</code></li>
    </ul>
  </details>
</section>
