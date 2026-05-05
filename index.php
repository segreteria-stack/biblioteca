<?php
// index.php (root) — ponte sicuro verso /public/index.php

// calcola il path tenendo conto di eventuale sottocartella
$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$target = $base . '/public/index.php';

// reindirizza (302)
header('Location: ' . $target, true, 302);
exit;
