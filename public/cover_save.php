<?php
declare(strict_types=1);

/**
 * cover_save.php
 *
 * Endpoint POST chiamato dal browser quando ha caricato con successo
 * una copertina da OpenLibrary o Google Books.
 * Salva l'immagine sul server tramite CoverService::saveFromUrl().
 *
 * Input JSON: { "isbn": "9788832153682", "url": "https://..." }
 * Output JSON: { "ok": true } oppure { "ok": false }
 */

define('ROOT', dirname(__DIR__));

require ROOT . '/config.php';
require ROOT . '/lib/CoverService.php';

header('Content-Type: application/json');

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$body = file_get_contents('php://input');
$data = json_decode($body ?: '', true);

$isbn = trim((string)($data['isbn'] ?? ''));
$url  = trim((string)($data['url'] ?? ''));

if ($isbn === '' || $url === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing isbn or url']);
    exit;
}

$ok = CoverService::saveFromUrl($isbn, $url);
echo json_encode(['ok' => $ok]);