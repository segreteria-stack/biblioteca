<?php
error_reporting(E_ALL); ini_set('display_errors',1);
session_name('opac_sess'); session_start();
$cfg = require __DIR__.'/../config.php';
require __DIR__.'/../lib/DB.php';
$db = DB::conn($cfg['db']);

$q = trim($_GET['q'] ?? '');
echo "<h1>SQL probe</h1>";
echo "<form><input name='q' value='".htmlspecialchars($q,ENT_QUOTES)."'><button>Prova</button></form>";
if ($q==='') exit;

$sql = "SELECT COUNT(*) n FROM biblio b WHERE b.opac_flg='Y' AND (
  IFNULL(b.title,'')           LIKE :q1 OR
  IFNULL(b.title_remainder,'') LIKE :q2 OR
  IFNULL(b.author,'')          LIKE :q3 OR
  IFNULL(b.topic1,'')          LIKE :q4 OR
  IFNULL(b.topic2,'')          LIKE :q5 OR
  IFNULL(b.topic3,'')          LIKE :q6 OR
  IFNULL(b.topic4,'')          LIKE :q7 OR
  IFNULL(b.topic5,'')          LIKE :q8
)";
$params = [];
for ($i=1;$i<=8;$i++) { $params[":q$i"] = "%$q%"; }
$st = $db->prepare($sql);
$st->execute($params);
$n = (int)$st->fetch()['n'];
echo "<p>Match: <strong>$n</strong></p>";
