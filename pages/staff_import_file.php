<?php
declare(strict_types=1);

/**
 * Importazione dati da file (STEP 1)
 *
 * - Upload di file MARC21 (.mrc, .marc, .iso, .iso2709) o EndNote (.txt, .enw)
 * - Salva il file in /uploads/imports (fuori da /public)
 * - Memorizza info in $_SESSION['import_last_file']
 * - Mostra anteprima:
 *      * per MARC21: LDR + campi TAG con sottocampi ($a, $b, ...)
 *      * per EndNote/Altro: testo normalizzato
 *
 * Lo STEP 2 è gestito da pages/staff_import_wizard.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------------------
// Protezione area staff
// -----------------------------------------------------------------------------
if (empty($_SESSION['staff_user_id'])) {
    $baseUrl  = function_exists('base_url') ? base_url() : '';
    $redirect = 'staff_import_file';
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=' . urlencode($redirect));
    exit;
}

require_once __DIR__ . '/../lib/helpers.php';

$baseUrl   = function_exists('base_url') ? base_url() : '';
$rootPath  = dirname(__DIR__);
$importDir = $rootPath . '/uploads/imports';

// Assicuriamoci che la cartella esista
if (!is_dir($importDir)) {
    @mkdir($importDir, 0775, true);
}

// -----------------------------------------------------------------------------
// Funzioni di supporto
// -----------------------------------------------------------------------------

function format_bytes_for_human(int $bytes): string
{
    if ($bytes >= 1048576) {
        return sprintf('%.1f MB', $bytes / 1048576);
    }
    if ($bytes >= 1024) {
        return sprintf('%.1f KB', $bytes / 1024);
    }
    return $bytes . ' byte';
}

/**
 * Parsing semplice del primo record MARC21 ISO2709.
 * Ritorna ['leader' => string, 'fields' => [tag => [fieldData, ...]]]
 */
function admin_marc_parse_first_record(string $raw): array
{
    if ($raw === '' || strlen($raw) < 24) {
        return ['leader' => '', 'fields' => []];
    }

    $leader = substr($raw, 0, 24);
    if (!ctype_digit(substr($leader, 0, 5))) {
        return ['leader' => $leader, 'fields' => []];
    }

    $recordLen = (int)substr($leader, 0, 5);
    if ($recordLen <= 0 || $recordLen > strlen($raw)) {
        $rtPos  = strpos($raw, "\x1D");
        $record = $rtPos !== false ? substr($raw, 0, $rtPos + 1) : $raw;
    } else {
        $record = substr($raw, 0, $recordLen);
    }

    $leader   = substr($record, 0, 24);
    $baseAddr = (int)substr($leader, 12, 5);
    if ($baseAddr <= 0 || $baseAddr > strlen($record)) {
        return ['leader' => $leader, 'fields' => []];
    }

    $directoryLen = $baseAddr - 24;
    if ($directoryLen <= 0) {
        return ['leader' => $leader, 'fields' => []];
    }

    $directory = substr($record, 24, $directoryLen);
    $fieldData = substr($record, $baseAddr);

    $fields = [];
    $dirLen = strlen($directory);

    for ($pos = 0; $pos + 12 <= $dirLen; $pos += 12) {
        $entry = substr($directory, $pos, 12);
        if ($entry === '' || trim($entry) === '') {
            break;
        }
        $tag   = substr($entry, 0, 3);
        $len   = (int)substr($entry, 3, 4);
        $start = (int)substr($entry, 7, 5);

        if ($len <= 0 || $start < 0 || ($start + $len) > strlen($fieldData)) {
            continue;
        }

        $data = substr($fieldData, $start, $len);
        $data = rtrim($data, "\x1E");

        $fields[$tag][] = $data;
    }

    return ['leader' => $leader, 'fields' => $fields];
}

/**
 * Parsing di un singolo campo dati (indicatori + subfield).
 */
function admin_marc_parse_field(string $fieldData): array
{
    if ($fieldData === '') {
        return ['ind1' => ' ', 'ind2' => ' ', 'sub' => []];
    }

    $ind1    = $fieldData[0] ?? ' ';
    $ind2    = $fieldData[1] ?? ' ';
    $subData = substr($fieldData, 2);

    $result = [];
    $parts  = explode("\x1F", $subData);

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $code = $part[0] ?? '';
        if ($code === '') {
            continue;
        }
        $value = substr($part, 1);
        $value = trim($value, " \t\n\r\f\v");
        if ($value === '') {
            continue;
        }
        if (!isset($result[$code])) {
            $result[$code] = [];
        }
        $result[$code][] = $value;
    }

    return ['ind1' => $ind1, 'ind2' => $ind2, 'sub' => $result];
}

/**
 * Normalizza testo per anteprima: sostituisce caratteri di controllo.
 */
function admin_normalize_preview(string $raw): string
{
    $out = '';
    $len = strlen($raw);
    for ($i = 0; $i < $len; $i++) {
        $ch  = $raw[$i];
        $ord = ord($ch);
        if ($ord < 0x20 || $ord === 0x7F) {
            $out .= ' ';
        } else {
            $out .= $ch;
        }
    }
    return $out;
}

/**
 * Costruisce un'anteprima leggibile del record MARC:
 *  - riga LDR ...
 *  - ogni campo: TAG ind1ind2 $a ... | $b ...
 */
function admin_marc_build_pretty_preview(array $parsed, int $maxLines = 40): string
{
    $leader = $parsed['leader'] ?? '';
    $fields = $parsed['fields'] ?? [];

    if ($leader === '' && empty($fields)) {
        return '';
    }

    $lines = [];
    if ($leader !== '') {
        $lines[] = 'LDR ' . $leader;
    }

    $tags = array_keys($fields);
    sort($tags, SORT_STRING);

    foreach ($tags as $tag) {
        foreach ($fields[$tag] as $fieldData) {
            $f     = admin_marc_parse_field($fieldData);
            $ind1  = $f['ind1'] ?? ' ';
            $ind2  = $f['ind2'] ?? ' ';
            $subs  = $f['sub'] ?? [];
            $parts = [];

            foreach ($subs as $code => $values) {
                foreach ($values as $val) {
                    $parts[] = '$' . $code . ' ' . $val;
                }
            }

            $line = sprintf('%3s %s%s ', $tag, $ind1, $ind2);
            if (!empty($parts)) {
                $line .= implode(' | ', $parts);
            }
            $lines[] = $line;

            if (count($lines) >= $maxLines) {
                $lines[] = '[...]';
                break 2;
            }
        }
    }

    return implode("\n", $lines);
}

// -----------------------------------------------------------------------------
// Gestione upload
// -----------------------------------------------------------------------------

$errorMsg      = '';
$fileUploaded  = false;
$originalName  = '';
$storedName    = '';
$sizeBytes     = 0;
$fileType      = 'unknown'; // marc21 | endnote | unknown
$previewText   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'Errore durante il caricamento del file (codice ' . (int)$file['error'] . ').';
    } else {
        $sizeBytes = (int)$file['size'];
        if ($sizeBytes <= 0) {
            $errorMsg = 'Il file è vuoto.';
        } elseif ($sizeBytes > 5 * 1024 * 1024) {
            $errorMsg = 'Il file supera la dimensione massima consentita (5 MB).';
        } else {
            $originalName = (string)$file['name'];
            $sanitized    = preg_replace('~[^A-Za-z0-9_.-]+~', '_', basename($originalName));
            if ($sanitized === '') {
                $sanitized = 'import.dat';
            }

            $storedName = date('Ymd_His') . '_' . $sanitized;
            $targetPath = $importDir . DIRECTORY_SEPARATOR . $storedName;

            if (!is_uploaded_file($file['tmp_name'])) {
                $errorMsg = 'Caricamento non valido.';
            } elseif (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                $errorMsg = 'Impossibile salvare il file sul server.';
            } else {
                // Riconoscimento tipo
                $ext = strtolower((string)pathinfo($storedName, PATHINFO_EXTENSION));
                if (in_array($ext, ['mrc', 'marc', 'iso', 'iso2709'], true)) {
                    $fileType = 'marc21';
                } elseif (in_array($ext, ['txt', 'enw'], true)) {
                    $fileType = 'endnote';
                } else {
                    $fileType = 'unknown';
                }

                // Salviamo info in sessione per lo STEP 2
                $_SESSION['import_last_file'] = [
                    'original'   => $originalName,
                    'stored'     => $storedName,
                    'size_bytes' => $sizeBytes,
                    'type'       => $fileType,
                ];

                // Carichiamo il contenuto per l’anteprima
                $raw = file_get_contents($targetPath);
                if ($raw === false) {
                    $raw = '';
                }

                if ($fileType === 'marc21') {
                    $parsed = admin_marc_parse_first_record($raw);
                    $previewText = admin_marc_build_pretty_preview($parsed, 40);
                    if ($previewText === '') {
                        $previewText = mb_substr(admin_normalize_preview($raw), 0, 2000);
                    }
                } else {
                    // EndNote o tipo sconosciuto: normalizziamo e tagliamo
                    $previewText = mb_substr(admin_normalize_preview($raw), 0, 2000);
                }

                $fileUploaded = true;
            }
        }
    }
}

?>
<section class="page-section">
    <h1>Importazione dati da file</h1>

    <p>
        Carica un file di dati in formato <strong>MARC21</strong> (ad esempio <code>.mrc</code>, <code>.iso</code>)
        oppure <strong>EndNote</strong> (ad esempio <code>.txt</code>, <code>.enw</code>)
        per avviare una procedura di importazione nel catalogo.
    </p>

    <?php if ($errorMsg !== ''): ?>
        <div class="generic-box" style="margin-top:0.75rem;">
            <p><?= h($errorMsg) ?></p>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" style="margin-top:1rem;">
        <div class="search-row">
            <label for="import-file">Seleziona file da importare</label>
            <input type="file" id="import-file" name="import_file" required>
        </div>
        <p class="search-help">
            Formati supportati: MARC21 (<code>.mrc</code>, <code>.marc</code>, <code>.iso</code>, <code>.iso2709</code>)
            e EndNote (<code>.txt</code>, <code>.enw</code>). Dimensione massima: 5&nbsp;MB.
        </p>
        <div class="search-actions">
            <button type="submit" class="btn-primary">Carica file</button>
            <a class="btn-link" href="<?= h($baseUrl) ?>/index.php?page=staff">
                Torna all’Area staff
            </a>
        </div>
    </form>

    <?php if ($fileUploaded): ?>
        <hr style="margin:1.75rem 0 1.25rem;border:none;border-top:1px solid #e5e7eb;">

        <h2>File caricato</h2>
        <ul>
            <li><strong>Nome originale</strong>: <?= h($originalName) ?></li>
            <li><strong>Salvato come</strong>: <?= h($storedName) ?></li>
            <li><strong>Dimensione</strong>: <?= h(format_bytes_for_human($sizeBytes)) ?></li>
            <li><strong>Tipo rilevato</strong>:
                <?= h($fileType === 'marc21' ? 'MARC21' : ($fileType === 'endnote' ? 'EndNote' : 'Sconosciuto')) ?>
            </li>
        </ul>

        <p style="margin-top:0.75rem;">
            <a class="btn-primary" href="<?= h($baseUrl) ?>/index.php?page=staff_import_wizard&amp;file=<?= h($storedName) ?>">
                Vai allo STEP 2 (wizard)
            </a>
        </p>

        <h3 style="margin-top:1.5rem;">Anteprima del contenuto</h3>
        <p class="search-help">
            Attenzione: questa è solo una vista preliminare delle prime righe del file.
            La logica di importazione e mappatura dei campi nel catalogo verrà implementata nei blocchi successivi.
        </p>

        <pre style="background:#111827;color:#e5e7eb;padding:0.75rem 1rem;border-radius:4px;max-height:260px;overflow:auto;font-size:0.8rem;">
<?= h($previewText) ?>
        </pre>
    <?php endif; ?>
</section>
