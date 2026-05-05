<?php
declare(strict_types=1);

header('Content-Type: image/svg+xml; charset=utf-8');

$title = $_GET['title'] ?? '';
$title = trim($title);

// Se non arriva nessun titolo, usiamo un fallback leggibile
if ($title === '') {
    $title = '[Senza titolo]';
}

// Limitiamo un po' la lunghezza
if (function_exists('mb_substr')) {
    $title = mb_substr($title, 0, 70, 'UTF-8');
} else {
    $title = substr($title, 0, 30);
}

// Wrap molto semplice per spezzare il titolo su più righe
$words   = preg_split('/\s+/', $title) ?: [$title];
$lines   = [];
$current = '';
$maxLen  = 16; // caratteri circa per riga (meno perché il font è più grande)

$strlen = function (string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
};

foreach ($words as $w) {
    $w = trim($w);
    if ($w === '') {
        continue;
    }
    if ($current === '') {
        $current = $w;
        continue;
    }
    if ($strlen($current . ' ' . $w) <= $maxLen) {
        $current .= ' ' . $w;
    } else {
        $lines[] = $current;
        $current = $w;
    }
}
if ($current !== '') {
    $lines[] = $current;
}

// Calcolo posizione verticale per centrare il blocco di testo
$lineHeight   = 38; // più alto perché il font è più grande
$lineCount    = max(1, count($lines));
$totalHeight  = $lineCount * $lineHeight;
$centerY      = 225; // metà di 450
$startY       = (int) round($centerY - $totalHeight / 2 + $lineHeight / 2);
?>
<svg xmlns="http://www.w3.org/2000/svg"
     width="300" height="450" viewBox="0 0 300 450">
  <defs>
    <style>
      .title-text {
        fill: #FFFFFF;
        font-size: 34px; /* più grande */
        font-weight: 700;
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI",
                     Roboto, "Helvetica Neue", Arial, sans-serif;
        text-anchor: middle;
      }
    </style>
  </defs>

  <!-- Sfondo bordeaux -->
  <rect x="0" y="0" width="300" height="450" rx="8" ry="8"
        fill="#e11e28" />

  <!-- leggera cornice -->
  <rect x="4" y="4" width="292" height="442" rx="8" ry="8"
        fill="none" stroke="#000000" stroke-opacity="0.15" />

  <!-- Titolo centrato -->
  <g>
    <?php
    $y = $startY;
    foreach ($lines as $line):
        $text = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ?>
      <text x="150" y="<?= $y ?>" class="title-text">
        <?= $text ?>
      </text>
    <?php
        $y += $lineHeight;
    endforeach;
    ?>
  </g>
</svg>
