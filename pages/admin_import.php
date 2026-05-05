<?php
// Redirect per retrocompatibilità: il file è stato spostato in staff_import_file.php
$baseUrl = function_exists('base_url') ? base_url() : '';
header('Location: ' . $baseUrl . '/index.php?page=staff_import_file');
exit;
