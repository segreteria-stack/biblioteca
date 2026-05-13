<?php
declare(strict_types=1);

/**
 * SbnClient
 *
 * Client per le API ICCU/SBNIntegrato.
 * Endpoint: https://api.iccu.sbn.it/sbn/1.0.0/
 * Auth: OAuth2 Client Credentials
 */

final class SbnClient
{
    private const TOKEN_URL  = 'https://api.iccu.sbn.it/oauth2/token';
    private const API_BASE   = 'https://api.iccu.sbn.it/sbn/1.0.0';
    private const TOKEN_TTL  = 3600;
    private const TIMEOUT_TOKEN = 20;
    private const TIMEOUT_API   = 30;

    private string $consumerKey;
    private string $consumerSecret;
    private ?string $token = null;
    private int $tokenExpiry = 0;

    public function __construct(string $consumerKey, string $consumerSecret)
    {
        $this->consumerKey    = $consumerKey;
        $this->consumerSecret = $consumerSecret;
    }

    /**
     * Test rapido della connessione (ottiene token).
     */
    public function testConnection(): array
    {
        $token = $this->ensureToken();
        return ['token' => substr($token, 0, 15) . '...', 'valid' => true];
    }

    private function ensureToken(): string
    {
        if ($this->token !== null && time() < $this->tokenExpiry - 60) {
            return $this->token;
        }

        $auth = base64_encode("{$this->consumerKey}:{$this->consumerSecret}");

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER     => [
                "Authorization: Basic {$auth}",
                "Content-Type: application/x-www-form-urlencoded",
                "Accept: application/json",
                "User-Agent: ANPI-OPAC/1.0",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_TOKEN,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $res === '' || $res === null) {
            throw new RuntimeException("SBN OAuth: empty response (HTTP {$code}, curl: {$err})");
        }

        if ($code !== 200) {
            throw new RuntimeException("SBN OAuth: HTTP {$code}: " . substr((string)$res, 0, 500));
        }

        $data = json_decode((string)$res, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('SBN OAuth: invalid response: ' . substr((string)$res, 0, 200));
        }

        $this->token       = $data['access_token'];
        $this->tokenExpiry = time() + (int)($data['expires_in'] ?? self::TOKEN_TTL);

        return $this->token;
    }

    private function call(string $endpoint, array $params = []): ?array
    {
        $token = $this->ensureToken();

        $url = self::API_BASE . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_API,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                "Accept: application/json",
                "User-Agent: ANPI-OPAC/1.0",
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);

        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Log di debug (rimuovi in produzione se necessario)
        if ($code !== 200 || empty($res)) {
            error_log("SBN DEBUG: HTTP={$code} | CURL_ERR=" . ($err ?: 'none') .
                      " | URL=" . substr($url, 0, 120) .
                      " | RES_LEN=" . strlen((string)$res));
        }

        if ($res === false || $res === '' || $res === null) {
            throw new RuntimeException("SBN: empty response (HTTP {$code}, curl: {$err})");
        }

        if ($code === 401) {
            $this->token = null; // forza refresh token al prossimo tentativo
            throw new RuntimeException("SBN: authentication failed (HTTP 401)");
        }

        if ($code === 429) {
            throw new RuntimeException("SBN: rate limited (HTTP 429)");
        }

        if ($code !== 200) {
            throw new RuntimeException("SBN: HTTP {$code}: " . substr((string)$res, 0, 500));
        }

        $data = json_decode((string)$res, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("SBN: invalid JSON: " . json_last_error_msg() .
                                       " | snippet: " . substr((string)$res, 0, 200));
        }

        return $data;
    }

    /* ================================================================
     * Ricerche
     * ================================================================ */
    public function searchByIsbn(string $isbn): ?array
    {
        $clean = preg_replace('/[^0-9X]/i', '', strtoupper($isbn));
        if ($clean === '') return null;

        return $this->call('/search', [
            'isbn'       => $clean,
            'page-size'  => 1,
            'format'     => 'json',
            'detail'     => 'full',
        ]);
    }

    public function searchByTitle(string $title, int $pageSize = 10): ?array
    {
        return $this->call('/search', [
            'titolo'     => $title,
            'page-size'  => $pageSize,
            'format'     => 'json',
            'detail'     => 'full',
        ]);
    }

    public function searchByAuthor(string $author, int $pageSize = 10): ?array
    {
        return $this->call('/search', [
            'nome'       => $author,
            'page-size'  => $pageSize,
            'format'     => 'json',
            'detail'     => 'full',
        ]);
    }

    public function advancedSearch(array $params): ?array
    {
        $defaults = [
            'page-size' => 20,
            'format'    => 'json',
            'detail'    => 'full',
        ];
        return $this->call('/search', array_merge($defaults, $params));
    }

    public function getByBid(string $bid): ?array
    {
        // FIX: Normalizza BID per API ICCU — rimuove IT\ICCU\ e backslash
        $simpleBid = str_replace(['IT\\ICCU\\', '\\'], '', $bid);
        return $this->call('/id/' . rawurlencode($simpleBid), [
            'format' => 'json',
            'detail' => 'full',
        ]);
    }

    /* ================================================================
     * Estrazione dati completi
     * ================================================================ */
    public function extractFirstDoc(?array $response): ?array
    {
        if (!is_array($response)) return null;
        $docs = $response['docs'] ?? ($response['response']['docs'] ?? []);
        return $docs[0] ?? null;
    }

    public function parseEditore(string $publish): string
    {
        if (str_contains($publish, ' : ')) {
            $parts = explode(' : ', $publish, 2);
            $editore = $parts[1] ?? '';
            if (str_contains($editore, ',')) {
                $editore = explode(',', $editore)[0];
            }
            return trim($editore);
        }
        return trim($publish);
    }

    public function parseAnno(string $publish): ?string
    {
        if (preg_match('/\[?(\d{4})\D?/', $publish, $m)) {
            return $m[1];
        }
        return null;
    }

    public function parseLuogo(string $publish): ?string
    {
        if (str_contains($publish, ' : ')) {
            $parts = explode(' : ', $publish, 2);
            return trim($parts[0] ?? '');
        }
        return null;
    }

    public function parseIsbn(mixed $isbnField): string
    {
        $raw = is_array($isbnField) ? ($isbnField[0] ?? '') : (string)$isbnField;
        return preg_replace('/[^0-9X]/i', '', strtoupper($raw));
    }

    /* ================================================================
     * Estrazione campi avanzati da doc SBN
     * ================================================================ */
    public function extractFullData(?array $doc): array
    {
        if (!$doc) return [];

        $publish = $doc['publish'] ?? '';
        $unimarc = $doc['unimarc'] ?? [];
        $fields  = $unimarc['fields'] ?? [];

        // Estrazione campi da UNIMARC
        $dewey       = $this->extractUnimarcSubfield($fields, '676', 'a');
        $deweyDesc   = $this->extractUnimarcSubfield($fields, '676', 'c');
        $collezione  = $this->extractUnimarcSubfield($fields, '410', 'a');
        $note        = $this->extractUnimarcSubfield($fields, '300', 'a');  // descrizione fisica
        $abstract    = $this->extractUnimarcSubfield($fields, '320', 'a'); // abstract (se presente)
        $indice      = $this->extractUnimarcSubfield($fields, '327', 'a');  // indice
        $bibliografia= $this->extractUnimarcSubfield($fields, '328', 'a');  // bibliografia
        $titoloUniforme = $this->extractUnimarcSubfield($fields, '500', 'a');

        // Descrizione fisica dettagliata (215 $a = pagine, $d = dimensioni)
        $dimA = $this->extractUnimarcSubfield($fields, '215', 'a');
        $dimD = $this->extractUnimarcSubfield($fields, '215', 'd');
        $dimensioni = $dimA || $dimD ? trim(($dimA ?: '') . ($dimA && $dimD ? ' ; ' : '') . ($dimD ?: '')) : null;

        // Paese da paese_mus o da 102 $a
        $paese = $doc['paese_mus'][0] ?? $this->extractUnimarcSubfield($fields, '102', 'a') ?? null;

        // Lingua: l'API restituisce un array (es. ["ita"]); prendiamo il primo elemento
        $linguaRaw = $doc['lingua'] ?? null;
        $lingua = is_array($linguaRaw) ? ($linguaRaw[0] ?? null) : ($linguaRaw ?: null);

        return [
            'bid_sbn'         => $doc['id'] ?? null,
            'titolo'          => $this->extractTitolo($doc),
            'autore'          => $doc['autore'] ?? null,
            'editore'         => !empty($publish) ? $this->parseEditore($publish) : null,
            'luogo'           => !empty($publish) ? $this->parseLuogo($publish) : null,
            'anno'            => !empty($publish) ? $this->parseAnno($publish) : null,
            'isbn_sbn'        => $this->parseIsbn($doc['isbn'] ?? []),

            'soggetti'        => $this->extractSoggetti($doc),
            'dewey_code'      => $dewey,
            'dewey_des'       => $deweyDesc,
            'lingua'          => $lingua,
            'paese'           => $paese,
            'collezione'      => $collezione,
            'titolo_uniforme' => $titoloUniforme,
            'note'            => $note,
            'abstract'        => $abstract,
            'indice'          => $indice,
            'bibliografia'    => $bibliografia,
            'dimensioni'      => $dimensioni,
            'illustrazioni'   => null, // non presente in questo formato UNIMARC
        ];
    }

    /**
     * Estrae un sottocampo UNIMARC dal blocco fields.
     * Gestisce ripetizioni (restituisce il primo valore trovato).
     */
    private function extractUnimarcSubfield(array $fields, string $tag, string $code): ?string
    {
        foreach ($fields as $field) {
            if (!isset($field[$tag])) continue;
            $subfields = $field[$tag]['subfields'] ?? [];
            foreach ($subfields as $sub) {
                if (isset($sub[$code]) && $sub[$code] !== '') {
                    return trim($sub[$code]);
                }
            }
        }
        return null;
    }

    private function extractTitolo(array $doc): ?string
    {
        if (!empty($doc['isbd'])) {
            $parts = explode(' / ', $doc['isbd']);
            $pre = $doc['pre_titolo'] ?? '';
            $titolo = $parts[0] ?? '';
            // FIX: spazio tra pre_titolo e titolo
            return trim($pre . ($pre && $titolo ? ' ' : '') . $titolo);
        }
        return $doc['titolo'] ?? null;
    }

    private function extractSoggetti(array $doc): array
    {
        // API SBN usa 'soggettof' (plurale, con 'f' finale)
        $raw = $doc['soggettof'] ?? ($doc['soggetto'] ?? []);
        if (!is_array($raw)) {
            $raw = $raw ? [$raw] : [];
        }

        $result = [];
        foreach ($raw as $item) {
            $item = trim((string)$item);
            if ($item === '') continue;
            // Se contiene separatori comuni ("; " o " -- "), splitta in soggetti distinti
            if (str_contains($item, '; ') || str_contains($item, ' -- ')) {
                $parts = preg_split('/\s*;\s*|\s+--\s+/', $item);
                foreach ($parts as $p) {
                    $p = trim($p);
                    if ($p !== '') $result[] = $p;
                }
            } else {
                $result[] = $item;
            }
        }
        return $result;
    }

    /* ================================================================
     * Arricchimento record locale
     * ================================================================ */
    public function enrich(array $record): array
    {
        $isbn = $record['isbn'] ?? '';
        if (!$isbn) return $record;

        try {
            $res = $this->searchByIsbn($isbn);
        } catch (RuntimeException $e) {
            return array_merge($record, [
                '_sbn_enriched' => false,
                '_sbn_error'    => $e->getMessage(),
            ]);
        }

        $doc = $this->extractFirstDoc($res);

        if (!$doc) {
            return array_merge($record, [
                '_sbn_enriched' => false,
                '_sbn_error'    => 'not_found',
            ]);
        }

        $data = $this->extractFullData($doc);

        return array_merge($record, $data, [
            '_sbn_enriched' => true,
            '_sbn_source'   => $data['bid_sbn'],
        ]);
    }

    /* ================================================================
     * Link OPAC — HTTPS
     * ================================================================ */
    public static function opacLink(string $bid): string
    {
        // FIX: Normalizza BID per URL OPAC — rimuove IT\ICCU\ e backslash
        $simpleBid = str_replace(['IT\\ICCU\\', '\\'], '', $bid);
        // FIX: HTTPS invece di HTTP (sicurezza + compatibilità con id.sbn.it)
        return 'https://id.sbn.it/bid/' . rawurlencode($simpleBid);
    }
}