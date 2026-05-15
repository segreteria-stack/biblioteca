<?php
declare(strict_types=1);

function h(?string $v): string
{
    return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string
{
    global $cfg;
    $base = '';
    if (is_array($cfg ?? null) && isset($cfg['app']['base_url'])) {
        $base = (string)$cfg['app']['base_url'];
    }
    if ($base === '') {
        $base = '';
    }
    return rtrim($base, '/');
}

/**
 * Restituisce il token CSRF di sessione (lo genera se non esiste).
 * Usa una chiave di sessione condivisa tra tutte le pagine staff.
 */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/**
 * Verifica il token CSRF con confronto a tempo costante.
 */
function csrf_verify(string $token): bool
{
    return $token !== '' && hash_equals($_SESSION['_csrf'] ?? '', $token);
}

/** Alias di csrf_verify() — usato nelle pagine pubbliche. */
function csrf_check(string $token): bool
{
    return csrf_verify($token);
}

function recaptcha_verify(string $token, string $secret, float $threshold = 0.5): bool
{
    if ($token === '') return false;
    $resp = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false,
        stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query(['secret' => $secret, 'response' => $token]),
            'timeout' => 5,
        ]])
    );
    if ($resp === false) return false;
    $data = json_decode($resp, true);
    return ($data['success'] ?? false) === true && ($data['score'] ?? 0.0) >= $threshold;
}

/**
 * Inserisce una copia in biblio_copy e assegna il barcode nel formato standard
 * str_pad(bibid,5,'0') . str_pad(copyid,2,'0') (7 chars).
 * Se $barcode è fornito e valido (non vuoto, <= 20 chars) viene usato al posto dell'auto-generato.
 * Restituisce [copyid, barcode].
 */
function biblio_copy_insert(\PDO $pdo, int $bibid, string $status = 'in', string $barcode = ''): array
{
    $pdo->prepare('INSERT INTO biblio_copy (bibid,create_dt,barcode_nmbr,status_cd,status_begin_dt,renewal_count) VALUES (?,NOW(),\'\',?,NOW(),0)')
        ->execute([$bibid, $status]);
    $copyid      = (int)$pdo->lastInsertId();
    $autoBarcode = str_pad((string)$bibid, 5, '0', STR_PAD_LEFT)
                 . str_pad((string)$copyid, 2, '0', STR_PAD_LEFT);
    $finalBarcode = ($barcode !== '' && strlen($barcode) <= 20) ? $barcode : $autoBarcode;
    $pdo->prepare('UPDATE biblio_copy SET barcode_nmbr=? WHERE bibid=? AND copyid=?')
        ->execute([$finalBarcode, $bibid, $copyid]);
    return [$copyid, $finalBarcode];
}

/**
 * Tokenizza una stringa di ricerca rispettando le virgolette doppie.
 *
 * Esempi:
 *   'resistenza "guerra partigiana" italiana'
 *   → [{phrase:true, value:'guerra partigiana'}, {phrase:false, value:'resistenza'}, ...]
 *
 * Le frasi tra virgolette vengono trattate come substring esatta (LIKE '%frase%').
 * Le parole singole vengono trattate come token individuali (comportamento esistente).
 *
 * @return array<array{phrase: bool, value: string}>
 */
function search_tokenize(string $q): array
{
    $tokens = [];
    // Estrae prima le frasi tra virgolette, poi le parole singole
    preg_match_all('/"([^"]+)"|(\S+)/', $q, $m, PREG_SET_ORDER);
    foreach ($m as $match) {
        if (isset($match[1]) && $match[1] !== '') {
            $val = trim($match[1]);
            if ($val !== '') {
                $tokens[] = ['phrase' => true, 'value' => $val];
            }
        } elseif (isset($match[2]) && $match[2] !== '') {
            // Rimuovi eventuali virgolette orfane
            $val = trim(trim($match[2], '"'));
            if ($val !== '') {
                $tokens[] = ['phrase' => false, 'value' => $val];
            }
        }
    }
    return $tokens;
}
