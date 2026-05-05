<?php
declare(strict_types=1);

/**
 * Servizio staff: lookup dati bibliografici tramite ISBN
 *
 * Fonti (in ordine di priorità):
 *   1) OPAC SBN (API JSON opacmobilegw)
 *   2) OpenLibrary
 *   3) Google Books (fallback finale)
 *
 * Le fonti vengono interrogate in sequenza: se SBN restituisce
 * titolo + autore + editore + anno, OpenLibrary e Google Books
 * non vengono chiamati (risparmio di tempo e chiamate API).
 *
 * Esempio chiamata:
 *   GET /public/staff_isbn_lookup.php?isbn=9788830104929
 *
 * Output JSON:
 * {
 *   "ok": true,
 *   "source": "sbn_json",
 *   "sbn_available": true,
 *   "isbn_norm": "9788830104929",
 *   "title": "...",
 *   "subtitle": "...",
 *   "author": "...",
 *   "authors": ["..."],
 *   "publisher": "...",
 *   "pub_year": "2024",
 *   "pages": 320,
 *   "description": "...",
 *   "subjects": ["..."]
 * }
 */

/* ---------------------------------------------
 * Normalizzazione ISBN
 * --------------------------------------------- */
function normalizeIsbnForSearch(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $clean = preg_replace('/[^0-9Xx]/', '', $raw);
    if (!is_string($clean)) {
        return '';
    }

    return strtoupper($clean);
}

/* ---------------------------------------------
 * Helper: risposta JSON e uscita
 * --------------------------------------------- */
function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Ritorna la prima stringa non vuota dall'elenco.
 */
function firstNonEmptyString(...$values): string
{
    foreach ($values as $v) {
        if ($v === null) {
            continue;
        }
        $v = trim((string)$v);
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

/**
 * PATCH 1 — Pulizia editore da stringa SBN.
 * Rimuove artefatti tipo [s.n.], [S.l.], parentesi quadre residue.
 */
function cleanPublisher(string $raw): string
{
    $raw = trim($raw, " \t\n\r\0\x0B[]");
    // Se è "[s.n.]" o varianti → editore sconosciuto, restituiamo stringa vuota
    if (preg_match('/^\[?s\.?\s*n\.?\]?$/i', $raw)) {
        return '';
    }
    return $raw;
}

/* ---------------------------------------------
 * Lettura parametro ISBN
 * --------------------------------------------- */
$isbnInput = $_GET['isbn'] ?? '';
$isbnInput = trim((string)$isbnInput);

if ($isbnInput === '') {
    jsonResponse([
        'ok'    => false,
        'error' => 'Parametro "isbn" mancante.',
    ], 400);
}

$isbnNorm = normalizeIsbnForSearch($isbnInput);
if ($isbnNorm === '') {
    jsonResponse([
        'ok'    => false,
        'error' => 'ISBN non valido.',
    ], 400);
}

/* ---------------------------------------------
 * Google Books API
 * --------------------------------------------- */
function fetchFromGoogleBooks(string $isbnNorm): ?array
{
    $apiUrl = 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . rawurlencode($isbnNorm);

    $context = stream_context_create([
        'http'  => ['timeout' => 3],
        'https' => ['timeout' => 3],
    ]);

    $json = @file_get_contents($apiUrl, false, $context);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['items']) || !is_array($data['items'])) {
        return null;
    }

    $item = $data['items'][0] ?? null;
    if (!is_array($item)) {
        return null;
    }

    $vi = $item['volumeInfo'] ?? null;
    if (!is_array($vi)) {
        return null;
    }

    $title      = trim((string)($vi['title'] ?? ''));
    $subtitle   = trim((string)($vi['subtitle'] ?? ''));
    $authorsArr = isset($vi['authors']) && is_array($vi['authors']) ? $vi['authors'] : [];
    $publisher  = trim((string)($vi['publisher'] ?? ''));
    $pubDate    = trim((string)($vi['publishedDate'] ?? ''));
    $pages      = isset($vi['pageCount']) ? (int)$vi['pageCount'] : 0;
    $desc       = trim((string)($vi['description'] ?? ''));

    // PATCH 1 — limite soggetti Google Books a 10
    $rawCategories = isset($vi['categories']) && is_array($vi['categories']) ? $vi['categories'] : [];
    $categories = array_slice($rawCategories, 0, 10);

    $pubYear = '';
    if ($pubDate !== '' && preg_match('/(\d{4})/', $pubDate, $m)) {
        $pubYear = $m[1];
    }

    $authorMain = $authorsArr !== [] ? trim((string)$authorsArr[0]) : '';

    return [
        'title'       => $title,
        'subtitle'    => $subtitle,
        'author'      => $authorMain,
        'authors'     => $authorsArr,
        'publisher'   => $publisher,
        'pub_year'    => $pubYear,
        'pages'       => $pages,
        'description' => $desc,
        'subjects'    => $categories,
    ];
}

/* ---------------------------------------------
 * OpenLibrary (search.json)
 * --------------------------------------------- */
function fetchFromOpenLibrary(string $isbnNorm): ?array
{
    $apiUrl = 'https://openlibrary.org/search.json?isbn=' . rawurlencode($isbnNorm) . '&limit=1';

    $context = stream_context_create([
        'http'  => ['timeout' => 3],
        'https' => ['timeout' => 3],
    ]);

    $json = @file_get_contents($apiUrl, false, $context);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['docs']) || !is_array($data['docs'])) {
        return null;
    }

    $doc = $data['docs'][0] ?? null;
    if (!is_array($doc)) {
        return null;
    }

    $title    = trim((string)($doc['title'] ?? ''));
    $subtitle = trim((string)($doc['subtitle'] ?? ''));

    $authorsArr = [];
    if (isset($doc['author_name']) && is_array($doc['author_name'])) {
        foreach ($doc['author_name'] as $a) {
            $a = trim((string)$a);
            if ($a !== '') {
                $authorsArr[] = $a;
            }
        }
    }

    $authorMain = $authorsArr !== [] ? $authorsArr[0] : '';

    $publisher = '';
    if (isset($doc['publisher']) && is_array($doc['publisher']) && $doc['publisher'] !== []) {
        $publisher = trim((string)$doc['publisher'][0]);
    }

    $pubYear = '';
    if (isset($doc['first_publish_year']) && $doc['first_publish_year']) {
        $pubYear = (string)$doc['first_publish_year'];
    } elseif (isset($doc['publish_year']) && is_array($doc['publish_year']) && $doc['publish_year'] !== []) {
        $y = $doc['publish_year'][0];
        if (is_int($y) || is_string($y)) {
            if (preg_match('/(\d{4})/', (string)$y, $m)) {
                $pubYear = $m[1];
            }
        }
    }

    $pages = 0;
    if (isset($doc['number_of_pages_median'])) {
        $pages = (int)$doc['number_of_pages_median'];
    } elseif (isset($doc['number_of_pages'])) {
        $pages = (int)$doc['number_of_pages'];
    }

    // PATCH 1 — limite soggetti OpenLibrary a 10 (erano potenzialmente decine)
    $subjects = [];
    if (isset($doc['subject']) && is_array($doc['subject'])) {
        foreach ($doc['subject'] as $s) {
            $s = trim((string)$s);
            if ($s !== '') {
                $subjects[] = $s;
            }
        }
    }
    $subjects = array_slice($subjects, 0, 10);

    return [
        'title'       => $title,
        'subtitle'    => $subtitle,
        'author'      => $authorMain,
        'authors'     => $authorsArr,
        'publisher'   => $publisher,
        'pub_year'    => $pubYear,
        'pages'       => $pages,
        'description' => '',
        'subjects'    => $subjects,
    ];
}

/* ---------------------------------------------
 * OPAC SBN JSON (opacmobilegw)
 * --------------------------------------------- */
function fetchFromSbnJson(string $isbnNorm): ?array
{
    $base   = 'http://opac.sbn.it/opacmobilegw';
    $search = $base . '/search.json?isbn=' . rawurlencode($isbnNorm);

    $context = stream_context_create([
        'http' => ['timeout' => 3],
    ]);

    $json = @file_get_contents($search, false, $context);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['briefRecords']) || !is_array($data['briefRecords'])) {
        return null;
    }

    $first = $data['briefRecords'][0] ?? null;
    if (!is_array($first)) {
        return null;
    }

    $bid              = trim((string)($first['codiceIdentificativo'] ?? ''));
    $titolo           = trim((string)($first['titolo'] ?? ''));
    $autorePrincipale = trim((string)($first['autorePrincipale'] ?? ''));
    $pubblicazione    = trim((string)($first['pubblicazione'] ?? ''));

    // Soggetti dalle facet
    $subjects = [];
    if (isset($data['facetRecords']) && is_array($data['facetRecords'])) {
        foreach ($data['facetRecords'] as $facet) {
            if (!is_array($facet)) {
                continue;
            }
            if (($facet['facetName'] ?? '') === 'soggettof'
                && isset($facet['facetValues'])
                && is_array($facet['facetValues'])
            ) {
                foreach ($facet['facetValues'] as $fv) {
                    if (is_array($fv) && isset($fv[0])) {
                        $label = trim((string)$fv[0]);
                        if ($label !== '') {
                            $subjects[] = $label;
                        }
                    }
                }
            }
        }
    }

    // Anno dalla stringa "pubblicazione"
    $pubYear = '';
    if ($pubblicazione !== '' && preg_match('/(\d{4})/', $pubblicazione, $m)) {
        $pubYear = $m[1];
    }

    // PATCH 1 — parser editore SBN più robusto con pulizia artefatti
    $publisher = '';
    if ($pubblicazione !== '') {
        if (preg_match('/:\s*([^,;\[]+?)[,;]\s*\d{4}/', $pubblicazione, $m2)) {
            $publisher = cleanPublisher($m2[1]);
        }
        // Secondo tentativo: "Luogo : Editore" senza anno in fondo
        if ($publisher === '' && preg_match('/:\s*(.+)$/', $pubblicazione, $m3)) {
            $candidate = preg_replace('/,?\s*\d{4}.*$/', '', $m3[1]);
            $publisher = cleanPublisher($candidate);
        }
    }

    // Dettagli da full.json (descrizione fisica, riassunto, note)
    $descrizioneFisica = '';
    $noteGenerali      = '';
    $riassunto         = '';

    if ($bid !== '') {
        $fullUrl  = $base . '/full.json?bid=' . rawurlencode($bid);
        $jsonFull = @file_get_contents($fullUrl, false, $context);
        if ($jsonFull !== false) {
            $full = json_decode($jsonFull, true);
            if (is_array($full)) {
                $descrizioneFisica = trim((string)($full['descrizioneFisica'] ?? ''));

                if (isset($full['note']) && is_array($full['note'])) {
                    foreach ($full['note'] as $note) {
                        if (is_string($note)) {
                            $val = trim($note);
                            if ($val === '') {
                                continue;
                            }
                            $noteGenerali .= ($noteGenerali === '' ? '' : "\n") . $val;
                        } elseif (is_array($note)) {
                            $tipo = (string)($note['tipoNota'] ?? '');
                            $val  = trim((string)($note['valore'] ?? ''));
                            if ($val === '') {
                                continue;
                            }
                            if (stripos($tipo, 'riassunto') !== false || stripos($tipo, 'abstract') !== false) {
                                if ($riassunto === '') {
                                    $riassunto = $val;
                                }
                            } else {
                                $noteGenerali .= ($noteGenerali === '' ? '' : "\n") . $val;
                            }
                        }
                    }
                }
            }
        }
    }

    $pages = 0;
    if ($descrizioneFisica !== '' && preg_match('/(\d+)\s*p/i', $descrizioneFisica, $m3)) {
        $pages = (int)$m3[1];
    }

    return [
        'bid'                => $bid,
        'raw_title'          => $titolo,
        'title'              => $titolo,
        'subtitle'           => '',
        'author'             => $autorePrincipale,
        'authors'            => $autorePrincipale !== '' ? [$autorePrincipale] : [],
        'publisher'          => $publisher,
        'pub_year'           => $pubYear,
        'pages'              => $pages,
        'description'        => $riassunto !== '' ? $riassunto : $noteGenerali,
        'subjects'           => $subjects,
        'pubblicazione'      => $pubblicazione,
        'descrizione_fisica' => $descrizioneFisica,
    ];
}

/* ---------------------------------------------
 * PATCH 2 — Chiamate sequenziali con early exit
 *
 * Se SBN restituisce titolo + autore + editore + anno,
 * OpenLibrary e Google Books non vengono chiamati.
 * Se SBN è incompleto ma OpenLibrary copre i campi mancanti,
 * Google Books non viene chiamato.
 * Google Books viene usato solo come ultimo fallback.
 * --------------------------------------------- */
$sbn = fetchFromSbnJson($isbnNorm);
$ol  = null;
$gb  = null;

// SBN è "completo" se ha i 4 campi principali
$sbnComplete = $sbn !== null
    && $sbn['title']     !== ''
    && $sbn['author']    !== ''
    && $sbn['publisher'] !== ''
    && $sbn['pub_year']  !== '';

if (!$sbnComplete) {
    $ol = fetchFromOpenLibrary($isbnNorm);
}

// Controlliamo se la combinazione SBN + OpenLibrary copre i campi principali
$combinedTitle     = firstNonEmptyString($sbn['title'] ?? null,     $ol['title'] ?? null);
$combinedAuthor    = firstNonEmptyString($sbn['author'] ?? null,    $ol['author'] ?? null);
$combinedPublisher = firstNonEmptyString($sbn['publisher'] ?? null, $ol['publisher'] ?? null);
$combinedYear      = firstNonEmptyString($sbn['pub_year'] ?? null,  $ol['pub_year'] ?? null);

$combinedComplete = $combinedTitle !== ''
    && $combinedAuthor    !== ''
    && $combinedPublisher !== ''
    && $combinedYear      !== '';

if (!$combinedComplete) {
    $gb = fetchFromGoogleBooks($isbnNorm);
}

// Nessuna fonte ha risposto
if ($sbn === null && $ol === null && $gb === null) {
    jsonResponse([
        'ok'            => false,
        'error'         => 'Nessun dato trovato per questo ISBN (SBN, OpenLibrary, Google Books).',
        'isbn_norm'     => $isbnNorm,
        'sbn_available' => false,
    ], 404);
}

/* ---------------------------------------------
 * Costruzione stringa fonte
 * --------------------------------------------- */
$sourceParts = [];
if ($sbn !== null) {
    $sourceParts[] = 'sbn_json';
}
if ($ol !== null) {
    $sourceParts[] = 'openlibrary';
}
if ($gb !== null) {
    $sourceParts[] = 'google_books';
}
$source = implode('+', $sourceParts);

/* ---------------------------------------------
 * Merge campi principali: SBN → OpenLibrary → Google Books
 * --------------------------------------------- */
$title     = firstNonEmptyString($sbn['title'] ?? null,     $ol['title'] ?? null,     $gb['title'] ?? null);
$subtitle  = firstNonEmptyString($sbn['subtitle'] ?? null,  $ol['subtitle'] ?? null,  $gb['subtitle'] ?? null);
$author    = firstNonEmptyString($sbn['author'] ?? null,    $ol['author'] ?? null,    $gb['author'] ?? null);
$publisher = firstNonEmptyString($sbn['publisher'] ?? null, $ol['publisher'] ?? null, $gb['publisher'] ?? null);
$pubYear   = firstNonEmptyString($sbn['pub_year'] ?? null,  $ol['pub_year'] ?? null,  $gb['pub_year'] ?? null);

$pages = 0;
foreach ([$sbn['pages'] ?? 0, $ol['pages'] ?? 0, $gb['pages'] ?? 0] as $p) {
    $p = (int)$p;
    if ($p > 0) {
        $pages = $p;
        break;
    }
}

$desc = firstNonEmptyString($sbn['description'] ?? null, $ol['description'] ?? null, $gb['description'] ?? null);

/* ---------------------------------------------
 * Merge autori (deduplicati)
 * --------------------------------------------- */
$authors = [];
$addAuthors = function (?array $src) use (&$authors): void {
    if (!is_array($src)) {
        return;
    }
    foreach ($src as $name) {
        $name = trim((string)$name);
        if ($name !== '' && !in_array($name, $authors, true)) {
            $authors[] = $name;
        }
    }
};

$addAuthors($sbn['authors'] ?? null);
$addAuthors($ol['authors']  ?? null);
$addAuthors($gb['authors']  ?? null);

if ($author === '' && $authors !== []) {
    $author = $authors[0];
}

/* ---------------------------------------------
 * Merge soggetti (SBN prima — più specifici e in italiano)
 * --------------------------------------------- */
$subjects = [];
$addSubjects = function (?array $src) use (&$subjects): void {
    if (!is_array($src)) {
        return;
    }
    foreach ($src as $s) {
        $s = trim((string)$s);
        if ($s !== '' && !in_array($s, $subjects, true)) {
            $subjects[] = $s;
        }
    }
};

// SBN per primo: soggetti in italiano e più pertinenti
$addSubjects($sbn['subjects'] ?? null);
$addSubjects($ol['subjects']  ?? null);
$addSubjects($gb['subjects']  ?? null);

/* ---------------------------------------------
 * Risposta finale
 * --------------------------------------------- */
jsonResponse([
    'ok'            => true,
    'source'        => $source,
    'sbn_available' => $sbn !== null,   // PATCH 1 — flag monitoraggio SBN
    'isbn_input'    => $isbnInput,
    'isbn_norm'     => $isbnNorm,
    'title'         => $title,
    'subtitle'      => $subtitle,
    'author'        => $author,
    'authors'       => $authors,
    'publisher'     => $publisher,
    'pub_year'      => $pubYear,
    'pages'         => $pages,
    'description'   => $desc,
    'subjects'      => $subjects,
    'sbn_bid'       => $sbn['bid'] ?? null,
]);