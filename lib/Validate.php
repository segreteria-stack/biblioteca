<?php
// lib/Validate.php
class V {
  public static function str(?string $v, int $max = 255): string {
    $v = trim($v ?? '');
    if (mb_strlen($v) > $max) { $v = mb_substr($v, 0, $max); }
    return $v;
  }
  public static function yn(?string $v): string { return ($v === 'Y') ? 'Y' : 'N'; }
  public static function intOrNull($v) { $v = trim((string)($v ?? '')); return ($v === '') ? null : (int)$v; }
}
