<?php
declare(strict_types=1);

/**
 * Bibliotecario virtuale — endpoint AJAX per la chat con Gemini.
 *
 * POST JSON: { message: string, history: [{role, text}, ...] }
 * Response JSON: { ok: bool, reply: string } | { ok: false, error: string }
 */

header('Content-Type: application/json; charset=UTF-8');

define('ROOT', dirname(__DIR__));
require ROOT . '/config.php';
require ROOT . '/lib/DB.php';
require ROOT . '/lib/helpers.php';

// ── Sicurezza base ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Payload non valido.']);
    exit;
}

$message = trim((string)($body['message'] ?? ''));
$history = is_array($body['history'] ?? null) ? $body['history'] : [];

if ($message === '' || mb_strlen($message) > 1000) {
    echo json_encode(['ok' => false, 'error' => 'Messaggio non valido.']);
    exit;
}

// Rate limit per sessione: max 30 messaggi
session_start();
$_SESSION['agente_count'] = ($_SESSION['agente_count'] ?? 0) + 1;
if ($_SESSION['agente_count'] > 30) {
    echo json_encode(['ok' => false, 'error' => 'Limite messaggi raggiunti per questa sessione. Ricarica la pagina per ricominciare.']);
    exit;
}

// ── Ricerca nel catalogo ────────────────────────────────────────────────────

$catalogContext = '';
try {
    $pdo = DB::conn();
    $words = preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $message));
    $words = array_values(array_filter($words, fn($w) => mb_strlen($w) >= 4));

    if (!empty($words)) {
        $conditions = [];
        $params     = [];
        foreach (array_slice($words, 0, 5) as $i => $word) {
            $escaped       = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word);
            $conditions[]  = '(b.title LIKE ? OR b.author LIKE ? OR b.topic1 LIKE ? OR b.topic2 LIKE ?)';
            $params        = array_merge($params, array_fill(0, 4, '%' . $escaped . '%'));
        }
        $sql = 'SELECT b.bibid, b.title, b.author, b.topic1, b.topic2,
                       (SELECT COUNT(*) FROM biblio_copy c
                        WHERE c.bibid = b.bibid AND c.status_cd = \'in\') AS disponibili
                FROM biblio b
                WHERE b.opac_flg = \'Y\' AND (' . implode(' OR ', $conditions) . ')
                ORDER BY b.bibid DESC LIMIT 6';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $results = $st->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($results)) {
            $catalogContext = "\n\nRISULTATI DAL CATALOGO (usa questi per rispondere):\n";
            foreach ($results as $r) {
                $disp = (int)$r['disponibili'] > 0 ? 'disponibile' : 'non disponibile';
                $catalogContext .= sprintf(
                    "- \"%s\"%s — %s [bibid:%d]\n",
                    $r['title'],
                    $r['author'] ? ' di ' . $r['author'] : '',
                    $disp,
                    $r['bibid']
                );
            }
        }
    }
} catch (Throwable) {
    // Il catalogo non è disponibile: continua senza contesto DB
}

// ── Costruzione prompt ──────────────────────────────────────────────────────

$baseUrl     = rtrim((string)($cfg['app']['base_url'] ?? ''), '/');
$systemText  = <<<PROMPT
Sei Biblio, il bibliotecario virtuale della Biblioteca della Resistenza del Comitato Provinciale ANPI di Udine.
Sei un esperto di storia della Resistenza italiana, dell'antifascismo, della Seconda Guerra Mondiale e della storia locale del Friuli Venezia Giulia.

Puoi aiutare gli utenti a:
- Trovare libri nel catalogo su temi di storia, Resistenza, memoria democratica, Friuli
- Rispondere a domande sulla Resistenza italiana e la storia locale
- Spiegare come registrarsi alla biblioteca ({$baseUrl}/index.php?page=user_register) e come prendere libri in prestito (registrati, poi vai sulla scheda del libro e clicca "Prenota")
- Orientarsi nelle sezioni della biblioteca

Regole:
- Rispondi SEMPRE in italiano
- Sii cordiale, preciso e conciso (max 3-4 paragrafi)
- Se citi libri del catalogo, usa SOLO quelli forniti nel contesto — non inventare titoli
- Se un libro è "disponibile" puoi dire che si può prenotare; se "non disponibile" dì che è in prestito
- Per i libri del catalogo includi il link: {$baseUrl}/index.php?page=item&bibid=BIBID (sostituisci BIBID)
- Se non sai rispondere su un argomento, dillo onestamente
- Non discutere di argomenti non pertinenti alla biblioteca o alla storia
PROMPT;

// ── Chiamata a Gemini ───────────────────────────────────────────────────────

$apiKey = $cfg['gemini']['api_key'] ?? '';
$model  = $cfg['gemini']['model']  ?? 'gemini-2.0-flash';
$url    = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

// Costruisce la conversation history (max ultimi 10 turni)
$contents = [];
foreach (array_slice($history, -10) as $turn) {
    $role = ($turn['role'] ?? '') === 'model' ? 'model' : 'user';
    $text = trim((string)($turn['text'] ?? ''));
    if ($text !== '') {
        $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
    }
}
// Messaggio corrente con eventuale contesto catalogo
$contents[] = [
    'role'  => 'user',
    'parts' => [['text' => $message . $catalogContext]],
];

$payload = [
    'system_instruction' => ['parts' => [['text' => $systemText]]],
    'contents'           => $contents,
    'generationConfig'   => [
        'maxOutputTokens' => 512,
        'temperature'     => 0.4,
    ],
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 15,
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false || $httpCode !== 200) {
    echo json_encode(['ok' => false, 'error' => 'Servizio temporaneamente non disponibile. Riprova tra poco.']);
    exit;
}

$data  = json_decode($resp, true);
$reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

if ($reply === null) {
    echo json_encode(['ok' => false, 'error' => 'Nessuna risposta ricevuta.']);
    exit;
}

echo json_encode(['ok' => true, 'reply' => trim($reply)]);
