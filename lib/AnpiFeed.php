<?php
declare(strict_types=1);

/**
 * Legge un feed RSS/Atom remoto con cache su file e restituisce una lista di item.
 * Richiede: estensione cURL abilitata (consigliata). In alternativa si può usare file_get_contents.
 */
function fetch_feed_items(string $feedUrl, int $limit = 3, int $cacheTtlSeconds = 1800): array
{
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $cacheKey  = 'feed_' . sha1($feedUrl) . '.xml';
    $cacheFile = $cacheDir . '/' . $cacheKey;

    $xmlRaw = null;

    // Usa cache se fresca
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtlSeconds)) {
        $xmlRaw = @file_get_contents($cacheFile);
    } else {
        $xmlRaw = http_get_xml($feedUrl, 4); // timeout 4s
        if (is_string($xmlRaw) && $xmlRaw !== '') {
            @file_put_contents($cacheFile, $xmlRaw);
        } elseif (is_file($cacheFile)) {
            // fallback: usa l’ultima cache disponibile
            $xmlRaw = @file_get_contents($cacheFile);
        }
    }

    if (!is_string($xmlRaw) || trim($xmlRaw) === '') {
        return [];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlRaw);
    if ($xml === false) {
        return [];
    }

    $items = [];

    // RSS 2.0 tipico: <rss><channel><item>...
    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $it) {
            $items[] = normalize_feed_item($it);
            if (count($items) >= $limit) break;
        }
        return $items;
    }

    // Atom: <feed><entry>...
    if (isset($xml->entry)) {
        foreach ($xml->entry as $entry) {
            $items[] = normalize_atom_entry($entry);
            if (count($items) >= $limit) break;
        }
        return $items;
    }

    return [];
}

function http_get_xml(string $url, int $timeoutSeconds = 4): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT      => 'OPAC-BibliotecaResistenza/1.0 (+https://bibliotecanew.anpiudine.org/)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $data = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($data !== false && $code >= 200 && $code < 300) {
            return (string)$data;
        }
        return null;
    }

    // fallback (se allow_url_fopen è attivo)
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeoutSeconds,
            'header'  => "User-Agent: OPAC-BibliotecaResistenza/1.0\r\n",
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    return is_string($data) ? $data : null;
}

function normalize_feed_item(\SimpleXMLElement $it): array
{
    $title = trim((string)($it->title ?? ''));
    $link  = trim((string)($it->link ?? ''));
    $date  = trim((string)($it->pubDate ?? ''));
    $desc  = (string)($it->description ?? '');

    $excerpt = feed_excerpt($desc, 220);

    return [
        'title'   => $title,
        'url'     => $link,
        'date'    => $date,
        'excerpt' => $excerpt,
    ];
}

function normalize_atom_entry(\SimpleXMLElement $entry): array
{
    $title = trim((string)($entry->title ?? ''));
    $url   = '';
    if (isset($entry->link)) {
        foreach ($entry->link as $lnk) {
            $attrs = $lnk->attributes();
            if (isset($attrs['href'])) {
                $url = (string)$attrs['href'];
                break;
            }
        }
    }

    $date = trim((string)($entry->updated ?? $entry->published ?? ''));
    $desc = (string)($entry->summary ?? $entry->content ?? '');
    $excerpt = feed_excerpt($desc, 220);

    return [
        'title'   => $title,
        'url'     => $url,
        'date'    => $date,
        'excerpt' => $excerpt,
    ];
}

function feed_excerpt(string $html, int $maxLen = 220): string
{
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = preg_replace('/\s+/u', ' ', $text) ?: $text;

    if (mb_strlen($text, 'UTF-8') > $maxLen) {
        $text = mb_substr($text, 0, $maxLen - 1, 'UTF-8') . '…';
    }
    return $text;
}
