<?php
$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Cover.php';
$isbn = $_GET['isbn'] ?? '';
$size = $_GET['s'] ?? 'M';
$isbn = Cover::normIsbn($isbn);
if ($isbn === '') { http_response_code(400); exit; }
$path = Cover::cachePath($cfg, $isbn, $size);
if (!is_file($path) || filesize($path) < 256) { $path = Cover::fetchToCache($cfg, $isbn, $size); }
if (is_file($path)) { header('Content-Type: image/jpeg'); header('Cache-Control: public, max-age=864000'); readfile($path); }
else { header('Content-Type: image/png'); echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg=='); }
