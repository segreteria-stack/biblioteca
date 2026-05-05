<?php
declare(strict_types=1);

/**
 * CoverService
 *
 * Il server NON scarica copertine da fonti esterne perché il server hosting
 * non riesce a raggiungere Google Books API (503) né OpenLibrary (timeout).
 *
 * Logica di getCoverUrl():
 *   1. Cache locale (.jpg su disco) → URL locale  [già scaricato in passato]
 *   2. OpenLibrary → URL diretto, caricato dal browser (?default=false)
 *   3. Placeholder dinamico (cover_placeholder.php)
 *
 * Il browser gestisce il fallback con onerror:
 *   OpenLibrary 404 → JS tenta Google Books API → placeholder
 *
 * Quando il browser trova la cover, chiama cover_save.php che invoca
 * saveFromUrl() per salvare l'immagine sul server in modo permanente.
 *
 * PHP 8.3
 */
final class CoverService
{
    private const COVER_DIR       = '/assets/covers';
    private const MIN_IMAGE_BYTES = 1024;
    private const HTTP_TIMEOUT    = 8;

    // =========================================================================
    // API PUBBLICA
    // =========================================================================

    /**
     * Restituisce SEMPRE una URL immagine valida.
     * Non fa MAI chiamate esterne — legge solo cache locale o
     * restituisce URL che il browser caricherà direttamente.
     */
    public static function getCoverUrl(
        string $isbnRaw,
        string $title  = '',
        string $author = ''
    ): string {
        $isbn13 = self::toIsbn13($isbnRaw);

        // 1. Cache locale
        if ($isbn13 !== '' && self::hasLocalCover($isbn13)) {
            return self::localCoverUrl($isbn13);
        }

        // 2. OpenLibrary — URL diretto al browser
        if ($isbn13 !== '') {
            return 'https://covers.openlibrary.org/b/isbn/'
                . rawurlencode($isbn13)
                . '-M.jpg?default=false';
        }

        // 3. Placeholder
        return self::placeholderUrl($title);
    }

    /**
     * Restituisce l'ISBN originale normalizzato (10 o 13 cifre).
     * Utile per il JS.
     */
    public static function getIsbnForJs(string $isbnRaw): string
    {
        return self::normalizeIsbn($isbnRaw);
    }

    /**
     * Salva sul server un'immagine già trovata dal browser.
     *
     * Chiamato da cover_save.php dopo che il browser ha caricato
     * con successo una copertina da OpenLibrary o Google Books.
     *
     * @param string $isbnRaw  ISBN grezzo (chiave del file cache)
     * @param string $imageUrl URL dell'immagine trovata dal browser
     * @return bool true se salvata correttamente
     */
    public static function saveFromUrl(string $isbnRaw, string $imageUrl): bool
    {
        // Valida ISBN
        $isbn13 = self::toIsbn13($isbnRaw);
        if ($isbn13 === '') return false;

        // Se già in cache non riscaricare
        if (self::hasLocalCover($isbn13)) return true;

        // Valida URL — accetta solo domini fidati
        $allowed = ['covers.openlibrary.org', 'books.google.com', 'books.googleusercontent.com'];
        $host = parse_url($imageUrl, PHP_URL_HOST);
        if (!in_array($host, $allowed, true)) return false;

        // Scarica l'immagine
        $imageData = self::httpGet($imageUrl, true);
        if ($imageData === null) return false;

        self::ensureCoverDir();
        return self::saveImage($imageData, self::localCoverPath($isbn13));
    }

    /**
     * Scarica e salva la copertina da Google Books.
     * Da usare solo da cron o script staff — NON dalla UI.
     */
    public static function downloadAndCache(
        string $isbnRaw,
        string $title  = '',
        string $author = ''
    ): bool {
        $isbn13   = self::toIsbn13($isbnRaw);
        $isbnOrig = self::normalizeIsbn($isbnRaw);

        $cacheKey = $isbn13 !== '' ? $isbn13 : ($isbnOrig !== '' ? $isbnOrig : '');
        if ($cacheKey === '' && trim($title) === '') return false;
        if ($cacheKey === '') $cacheKey = md5(strtolower(trim($title)));

        if (self::hasLocalCover($cacheKey)) return true;

        $key = $GLOBALS['cfg']['google_books']['api_key'] ?? '';
        if ($key === '') return false;

        self::ensureCoverDir();

        $imageData = null;

        if ($isbnOrig !== '') {
            $imageData = self::fetchGoogleBooksByQuery('isbn:' . $isbnOrig, $key);
        }
        if ($imageData === null && $isbn13 !== '' && $isbn13 !== $isbnOrig) {
            $imageData = self::fetchGoogleBooksByQuery('isbn:' . $isbn13, $key);
        }
        if ($imageData === null && trim($title) !== '') {
            $q = 'intitle:' . rawurlencode(trim($title));
            if (trim($author) !== '') {
                $q .= '+inauthor:' . rawurlencode(trim($author));
            }
            $imageData = self::fetchGoogleBooksByQuery($q, $key);
        }

        if ($imageData === null) return false;

        return self::saveImage($imageData, self::localCoverPath($cacheKey));
    }

    // =========================================================================
    // NORMALIZZAZIONE ISBN
    // =========================================================================

    public static function normalizeIsbn(string $raw): string
    {
        $clean = preg_replace('/[^0-9X]/', '', strtoupper(trim($raw)));
        if (!is_string($clean)) return '';
        if (strlen($clean) === 10 || strlen($clean) === 13) return $clean;
        return '';
    }

    public static function isbn10To13(string $isbn10): string
    {
        if (strlen($isbn10) !== 10) return '';
        $base = '978' . substr($isbn10, 0, 9);
        $sum  = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int)$base[$i];
            $sum  += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        return $base . ((10 - ($sum % 10)) % 10);
    }

    public static function toIsbn13(string $raw): string
    {
        $isbn = self::normalizeIsbn($raw);
        if ($isbn === '') return '';
        if (strlen($isbn) === 10) return self::isbn10To13($isbn);
        return $isbn;
    }

    // =========================================================================
    // CACHE LOCALE
    // =========================================================================

    public static function hasLocalCover(string $isbn13): bool
    {
        $path = self::localCoverPath($isbn13);
        return is_file($path) && filesize($path) >= self::MIN_IMAGE_BYTES;
    }

    public static function localCoverUrl(string $isbn13): string
    {
        return base_url() . self::COVER_DIR . '/' . rawurlencode($isbn13) . '.jpg';
    }

    private static function localCoverPath(string $isbn13): string
    {
        return self::coverDirPath() . '/' . $isbn13 . '.jpg';
    }

    private static function coverDirPath(): string
    {
        return dirname(__DIR__) . '/public' . self::COVER_DIR;
    }

    private static function ensureCoverDir(): void
    {
        $dir = self::coverDirPath();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
    }

    // =========================================================================
    // PLACEHOLDER
    // =========================================================================

    public static function placeholderUrl(string $title): string
    {
        if (trim($title) === '') {
            return base_url() . '/assets/placeholder_nocover.png';
        }
        return base_url() . '/cover_placeholder.php?title=' . rawurlencode($title);
    }

    // =========================================================================
    // FETCH GOOGLE BOOKS (solo per downloadAndCache)
    // =========================================================================

    private static function fetchGoogleBooksByQuery(string $q, string $key): ?string
    {
        $apiUrl = 'https://www.googleapis.com/books/v1/volumes'
                . '?q=' . $q
                . '&maxResults=1'
                . '&fields=items(volumeInfo/imageLinks)'
                . '&key=' . rawurlencode($key);

        $json = self::httpGet($apiUrl);
        if ($json === null) return null;

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['items'][0]['volumeInfo']['imageLinks'])) {
            return null;
        }

        $links = $data['items'][0]['volumeInfo']['imageLinks'];

        foreach (['extraLarge', 'large', 'medium', 'thumbnail', 'smallThumbnail'] as $size) {
            if (empty($links[$size]) || !is_string($links[$size])) continue;
            $imgUrl  = preg_replace('~^http://~', 'https://', $links[$size]);
            $imgUrl  = preg_replace('/&zoom=\d/', '', $imgUrl);
            $imgData = self::httpGet($imgUrl, true);
            if ($imgData !== null && strlen($imgData) >= self::MIN_IMAGE_BYTES) {
                return $imgData;
            }
        }

        return null;
    }

    // =========================================================================
    // HTTP
    // =========================================================================

    private static function httpGet(string $url, bool $binary = false): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 4,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_USERAGENT      => 'BibliotecaResistenza-CoverService/2.0',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_ENCODING       => $binary ? '' : 'gzip',
            ]);
            $result = curl_exec($ch);
            $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($result === false || $code < 200 || $code >= 300) return null;
            return (string)$result;
        }

        $ctx = stream_context_create([
            'http'  => ['timeout' => self::HTTP_TIMEOUT, 'header' => "User-Agent: BibliotecaResistenza-CoverService/2.0\r\n"],
            'https' => ['timeout' => self::HTTP_TIMEOUT],
        ]);
        $result = @file_get_contents($url, false, $ctx);
        return $result !== false ? $result : null;
    }

    // =========================================================================
    // SALVATAGGIO
    // =========================================================================

    private static function saveImage(string $data, string $destPath): bool
    {
        if (strlen($data) < self::MIN_IMAGE_BYTES) return false;
        return (bool)@file_put_contents($destPath, $data);
    }
}