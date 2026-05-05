<?php
class Cover {
  public static function cacheDir(array $cfg): string {
    $base = __DIR__ . '/../public/assets/covers';
    if (!is_dir($base)) { @mkdir($base, 0775, true); }
    return $base;
  }
  public static function normIsbn(string $isbn): string {
    $s = preg_replace('/[^0-9Xx]/', '', $isbn);
    return strtoupper($s);
  }
  public static function cachePath(array $cfg, string $isbn, string $size='M'): string {
    $isbn = self::normIsbn($isbn);
    return self::cacheDir($cfg) . "/{$isbn}_{$size}.jpg";
  }
  public static function url(array $cfg, string $isbn, string $size='M'): string {
    $isbn = urlencode(self::normIsbn($isbn));
    $base = $cfg['app']['base_url'] ?? '';
    return "{$base}/cover.php?isbn={$isbn}&s={$size}";
  }
  public static function fetchToCache(array $cfg, string $isbn, string $size='M'): string {
    $isbn = self::normIsbn($isbn);
    if ($isbn === '') return '';
    $sizes = ['S'=>'S','M'=>'M','L'=>'L'];
    $s = $sizes[$size] ?? 'M';
    $dest = self::cachePath($cfg, $isbn, $s);
    if (is_file($dest) && filesize($dest) > 256) return $dest;
    $url = "https://covers.openlibrary.org/b/isbn/{$isbn}-{$s}.jpg";
    $img = self::httpGet($url);
    if ($img && strlen($img) > 256) { @file_put_contents($dest, $img); return $dest; }
    return '';
  }
  private static function httpGet(string $url): string {
    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'CoverFetcher/1.0'
      ]);
      $out = curl_exec($ch); curl_close($ch); return $out ?: '';
    } else {
      $ctx = stream_context_create(['http'=>['timeout'=>8,'header'=>"User-Agent: CoverFetcher/1.0\r\n"]]);
      $out = @file_get_contents($url, false, $ctx); return $out ?: '';
    }
  }
}
