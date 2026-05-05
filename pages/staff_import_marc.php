<?php
$baseUrl = function_exists('base_url') ? base_url() : '';
header('Location: ' . $baseUrl . '/index.php?page=staff_catalog_new&tab=marcxml');
exit;
