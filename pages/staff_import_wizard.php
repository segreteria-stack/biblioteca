<?php
/**
 * Importazione dati – Wizard (STEP 2)
 *
 * - Legge un file caricato in /uploads/imports
 * - Supporta:
 *     * MARC21 in formato ISO2709 (.mrc, .iso, .marc)
 *     * EndNote testo (.txt, .enw)
 * - Per MARC:
 *     * effettua un parsing minimale ISO2709
 *     * popola i campi chiave (ISBN, autori, titolo, pubblicazione, descrizione fisica, riassunto, soggetti)
 *     * mostra anteprima MARC normalizzata (tag + indicatori + sotto-campi)
 * - Per EndNote:
 *     * estrae dati di base da %T, %A, %I, %D, %C, %B, %7
 *     * mostra il testo originale EndNote
 *
 * STEP 3:
 * - I dati qui mostrati e modificabili vengono inviati a pages/staff_import_apply.php
 *   che si occupa di creare il record nel catalogo (biblio + biblio_field).
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../lib/helpers.php'; // per h()

$baseUrl = function_exists('base_url') ? base_url() : '';

/**
 * Directory in cui admin_import.php salva i file.
 * (radice del progetto /uploads/imports)
 */
$importBaseDir = realpath(__DIR__ . '/../uploads/imports');
if ($importBaseDir === false) {
    $errorMsg = 'La directory di importazione non è stata trovata.';
}

/**
 * Recupero del nome file dalla query string / POST.
 * Esempio URL:
 *   index.php?page=staff_import_wizard&file=20251118_115358_Mondo_contadino_e_lotta_di_liberazione.mrc
 */
$fileParam = isset($_GET['file']) ? (string) $_GET['file'] : (string) ($_POST['file'] ?? '');
$fileParam = trim($fileParam);
$fileParam = basename($fileParam); // sicurezza

// Variabili di output
$fileExists      = false;
$filePath        = '';
$fileSize        = 0;
$fileKind        = 'unknown';  // 'marc' | 'endnote' | 'unknown'
$fileOriginal    = '';
$previewText     = '';
$marcTaggedText  = '';         // anteprima MARC normalizzata
$isbnVal         = '';
$authorsVal      = '';
$titleVal        = '';
$pubVal          = '';
$physVal         = '';
$abstractVal     = '';
$subjectsVal     = '';
$errorMsg        = $errorMsg ?? null;

// -----------------------------------------------------------------------------
// Funzioni di utilità MARC
// -----------------------------------------------------------------------------

/**
 * Parsing minimale ISO2709.
 */
function marc_parse_iso2709(string $raw): array
{
    $len = strlen($raw);
    if ($len < 24) {
        return ['leader' => '', 'fields' => []];
    }

    $leader   = substr($raw, 0, 24);
    $baseAddr = (int) substr($leader, 12, 5);
    if ($baseAddr <= 0 || $baseAddr >= $len) {
        return ['leader' => $leader, 'fields' => []];
    }

    $directory = substr($raw, 24, $baseAddr - 24 - 1); // -1 per togliere 0x1E finale
    $dirLen    = strlen($directory);
    $fields    = [];

    for ($pos = 0; $pos + 11 < $dirLen; $pos += 12) {
        $entry = substr($directory, $pos, 12);
        $tag   = substr($entry, 0, 3);
        $flen  = (int) substr($entry, 3, 4);
        $off   = (int) substr($entry, 7, 5);

        if ($flen <= 0 || $off < 0 || ($baseAddr + $off + $flen) > $len + 1) {
            continue;
        }

        // Singolo campo + rimozione terminatore di campo (0x1E)
        $fieldData = substr($raw, $baseAddr + $off, $flen - 1);

        if ($tag < '010') {
            // Campi di controllo (001, 005, 008...)
            $fields[] = [
                'tag'       => $tag,
                'ctrl'      => true,
                'value_raw' => $fieldData,
            ];
        } else {
            $ind1    = substr($fieldData, 0, 1);
            $ind2    = substr($fieldData, 1, 1);
            $subRaw  = substr($fieldData, 2);
            $subList = [];

            if ($subRaw !== '') {
                $parts = explode("\x1F", $subRaw);
                foreach ($parts as $part) {
                    if ($part === '') {
                        continue;
                    }
                    $code = substr($part, 0, 1);
                    $val  = substr($part, 1);
                    $subList[] = [
                        'code' => $code,
                        'val'  => $val,
                    ];
                }
            }

            $fields[] = [
                'tag'       => $tag,
                'ctrl'      => false,
                'ind1'      => $ind1,
                'ind2'      => $ind2,
                'subfields' => $subList,
            ];
        }
    }

    return [
        'leader' => $leader,
        'fields' => $fields,
    ];
}

/**
 * Converte la struttura MARC in testo leggibile tipo:
 * 245 10 $a Titolo : $b Sottotitolo / $c Autore
 */
function marc_to_pretty_text(array $marc): string
{
    $lines = [];

    foreach ($marc['fields'] as $field) {
        if (!empty($field['ctrl'])) {
            $val = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $field['value_raw']);
            $lines[] = $field['tag'] . ' ' . trim($val);
            continue;
        }

        $tag  = $field['tag'];
        $ind1 = $field['ind1'] ?? ' ';
        $ind2 = $field['ind2'] ?? ' ';

        $subsText = [];
        foreach ($field['subfields'] as $sf) {
            $code = $sf['code'];
            $val  = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $sf['val']);
            $val  = trim($val);
            if ($val === '') {
                continue;
            }
            $subsText[] = '$' . $code . ' ' . $val;
        }

        $lines[] = sprintf(
            '%s %s%s %s',
            $tag,
            $ind1,
            $ind2,
            implode(' ', $subsText)
        );
    }

    return implode("\n", $lines);
}

/**
 * Raccoglie, per un certo TAG, i sotto-campi indicati in $codes (es. ['a','b','c'])
 * e li concatena in una stringa unica.
 */
function marc_collect_subfields(array $marc, string $tag, array $codes): string
{
    $out = [];

    foreach ($marc['fields'] as $field) {
        if (($field['tag'] ?? '') !== $tag || !empty($field['ctrl'])) {
            continue;
        }
        foreach ($field['subfields'] as $sf) {
            if (!in_array($sf['code'], $codes, true)) {
                continue;
            }
            $val = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $sf['val']);
            $val = trim($val);
            if ($val !== '') {
                $out[] = $val;
            }
        }
    }

    return implode(' ', $out);
}

// -----------------------------------------------------------------------------
// Funzioni EndNote
// -----------------------------------------------------------------------------

/**
 * Parsing semplice file EndNote testo.
 * Ritorna array associativo: 'T','A','I','D','C','B','7' => [...o stringhe...]
 */
function endnote_parse(string $raw): array
{
    $lines = preg_split("/\r\n|\r|\n/", $raw);
    $out = [];

    foreach ($lines as $line) {
        $line = rtrim($line, "\r\n");
        if ($line === '' || $line[0] !== '%' || strlen($line) < 3) {
            continue;
        }
        $tag = substr($line, 1, 1);           // es. 'A','T','I'...
        $val = trim(substr($line, 3));        // dopo "%X "
        if ($val === '') {
            continue;
        }
        if (!isset($out[$tag])) {
            $out[$tag] = [];
        }
        $out[$tag][] = $val;
    }

    return $out;
}

// -----------------------------------------------------------------------------
// Caricamento file e dispatch per tipo
// -----------------------------------------------------------------------------

if ($errorMsg === null) {
    if ($fileParam === '') {
        $errorMsg = 'Nessun file di importazione risulta selezionato.';
    } else {
        $filePath = $importBaseDir . DIRECTORY_SEPARATOR . $fileParam;
        if (!is_file($filePath) || !is_readable($filePath)) {
            $errorMsg = 'Il file selezionato non è stato trovato o non è leggibile sul server.';
        } else {
            $fileExists = true;
            $fileSize   = filesize($filePath) ?: 0;

            // Deduce tipo dal nome (estensione)
            $ext = strtolower(pathinfo($fileParam, PATHINFO_EXTENSION));
            if (in_array($ext, ['mrc', 'iso', 'marc'], true)) {
                $fileKind = 'marc';
            } elseif (in_array($ext, ['txt', 'enw'], true)) {
                $fileKind = 'endnote';
            } else {
                $fileKind = 'unknown';
            }

            $raw = file_get_contents($filePath);
            if ($raw === false) {
                $errorMsg = 'Impossibile leggere il contenuto del file dal server.';
            } else {
                // Nome "originale": se admin_import l'ha salvato in sessione, usiamolo
                if (isset($_SESSION['import_last']['original_name'])) {
                    $fileOriginal = (string) $_SESSION['import_last']['original_name'];
                } else {
                    $fileOriginal = $fileParam;
                }

                // Anteprima "grezza" (prime ~400 colonne leggibili)
                $cleanPreview = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $raw);
                $cleanPreview = preg_replace('/\s+/', ' ', $cleanPreview ?? '');
                $previewText  = substr($cleanPreview, 0, 400);

                // Dispatch per tipo
                if ($fileKind === 'marc') {
                    $marc = marc_parse_iso2709($raw);
                    $marcTaggedText = marc_to_pretty_text($marc);

                    // Compilazione campi principali
                    $isbnVal     = marc_collect_subfields($marc, '020', ['a', 'z']);
                    $authorsVal  = marc_collect_subfields($marc, '100', ['a']) . ' ' .
                                   marc_collect_subfields($marc, '700', ['a']);
                    $titleVal    = marc_collect_subfields($marc, '245', ['a', 'b', 'c']);
                    $pubVal      = marc_collect_subfields($marc, '260', ['a', 'b', 'c']);
                    if ($pubVal === '') {
                        $pubVal = marc_collect_subfields($marc, '264', ['a', 'b', 'c']);
                    }
                    $physVal     = marc_collect_subfields($marc, '300', ['a', 'b', 'c']);
                    $abstractVal = marc_collect_subfields($marc, '520', ['a', 'b']);
                    $subjectsVal = marc_collect_subfields($marc, '650', ['a', 'x', 'y', 'z']);
                } elseif ($fileKind === 'endnote') {
                    $parsed = endnote_parse($raw);

                    $authors = $parsed['A'] ?? [];
                    $title   = $parsed['T'][0] ?? '';
                    $place   = $parsed['C'][0] ?? '';
                    $pub     = $parsed['I'][0] ?? '';
                    $year    = $parsed['D'][0] ?? '';
                    $series  = $parsed['B'][0] ?? '';
                    $ed      = $parsed['7'][0] ?? '';

                    $authorsVal = implode('; ', $authors);
                    $titleVal   = $title;
                    $pubParts   = array_filter([$place, $pub, $year], static fn($v) => trim((string)$v) !== '');
                    $pubVal     = implode(' : ', $pubParts);

                    $abstractVal = ''; // EndNote base non ha riassunto nell'esempio
                    $subjectsVal = '';
                    $physVal     = $series !== '' ? 'Serie: ' . $series : '';
                    if ($ed !== '') {
                        $physVal = trim($physVal . ' Edizione: ' . $ed);
                    }

                    // Come anteprima "completa", mostriamo il testo EndNote così com'è
                    $marcTaggedText = trim((string) $raw);
                } else {
                    // Tipo sconosciuto: ci limitiamo a mostrare anteprima grezza
                    $marcTaggedText = $previewText;
                }
            }
        }
    }
}
?>
<section class="page-section">
    <h1>Importazione dati – Wizard (STEP 2)</h1>

    <?php if ($errorMsg !== null): ?>
        <p><?= h((string) $errorMsg) ?></p>
        <p>
            <a href="<?= h($baseUrl) ?>/index.php?page=admin_import">
                Torna alla pagina di caricamento
            </a>
        </p>
        <?php return; ?>
    <?php endif; ?>

    <p>
        Stai lavorando sul file:
    </p>
    <ul>
        <li><strong>Nome originale:</strong> <?= h((string) $fileOriginal) ?></li>
        <li><strong>Salvato come:</strong> <?= h((string) $fileParam) ?></li>
        <li><strong>Dimensione:</strong> <?= h((string) $fileSize) ?> byte</li>
        <li><strong>Tipo rilevato:</strong>
            <?php if ($fileKind === 'marc'): ?>
                MARC21
            <?php elseif ($fileKind === 'endnote'): ?>
                EndNote
            <?php else: ?>
                Sconosciuto
            <?php endif; ?>
        </li>
    </ul>

    <?php if ($fileKind === 'marc'): ?>
        <h2>Anteprima campi principali (MARC21)</h2>
        <p>
            Puoi correggere manualmente i dati estratti. Con lo STEP 3 verrà creato un nuovo record
            nel catalogo (tabella <code>biblio</code> + <code>biblio_field</code>).
        </p>

        <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_import_apply">
            <input type="hidden" name="file" value="<?= h((string) $fileParam) ?>">
            <input type="hidden" name="kind" value="marc">

            <div class="search-row">
                <label for="imp-isbn">ISBN (020)</label>
                <input
                    type="text"
                    id="imp-isbn"
                    name="isbn"
                    value="<?= h((string) $isbnVal) ?>"
                >
            </div>

            <div class="search-row">
                <label for="imp-authors">Autore principale e altri (100/700)</label>
                <textarea
                    id="imp-authors"
                    name="authors"
                    rows="2"
                ><?= h((string) $authorsVal) ?></textarea>
            </div>

            <div class="search-row">
                <label for="imp-title">Titolo e responsabilità (245)</label>
                <textarea
                    id="imp-title"
                    name="title"
                    rows="3"
                ><?= h((string) $titleVal) ?></textarea>
            </div>

            <div class="search-row">
                <label for="imp-pub">Pubblicazione (260/264)</label>
                <textarea
                    id="imp-pub"
                    name="pub"
                    rows="2"
                ><?= h((string) $pubVal) ?></textarea>
            </div>

            <div class="search-row">
                <label for="imp-phys">Descrizione fisica (300)</label>
                <textarea
                    id="imp-phys"
                    name="phys"
                    rows="2"
                ><?= h((string) $physVal) ?></textarea>
            </div>

            <div class="search-row">
                <label for="imp-abstract">Riassunto / abstract (520)</label>
                <textarea
                    id="imp-abstract"
                    name="abstract"
                    rows="3"
                ><?= h((string) $abstractVal) ?></textarea>
            </div>

            <div class="search-row">
                <label for="imp-subjects">Soggetti / argomenti (650)</label>
                <textarea
                    id="imp-subjects"
                    name="subjects"
                    rows="3"
                ><?= h((string) $subjectsVal) ?></textarea>
            </div>

            <p style="font-size:0.85rem;color:#666;margin-top:0.5rem;">
                I soggetti verranno distribuiti nei campi <code>topic1..topic5</code> della tabella
                <code>biblio</code> (una voce per riga, fino a 5).
            </p>

            <div class="search-actions" style="margin-top:1rem;">
                <button type="button" class="btn-secondary"
                        onclick="window.location.href='<?= h($baseUrl) ?>/index.php?page=admin_import'">
                    Torna allo STEP 1 (selezione file)
                </button>

                <button type="submit" class="btn-primary">
                    STEP 3 – Crea record nel catalogo
                </button>
            </div>
        </form>

        <h2 style="margin-top:2rem;">Anteprima completa del record (testo MARC normalizzato)</h2>
        <pre style="background:#111827;color:#E5E7EB;padding:1rem;border-radius:6px;overflow:auto;font-size:0.8rem;">
<?= h((string) $marcTaggedText) ?>

        </pre>

    <?php elseif ($fileKind === 'endnote'): ?>
        <h2>Anteprima campi principali (EndNote)</h2>
        <p>
            Il file è stato riconosciuto come EndNote. I dati principali sono stati mappati
            nei campi sottostanti; con lo STEP 3 verrà creato un nuovo record nel catalogo.
        </p>

        <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_import_apply">
            <input type="hidden" name="file" value="<?= h((string) $fileParam) ?>">
            <input type="hidden" name="kind" value="endnote">

            <div class="search-row">
                <label for="imp-authors-enw">Autori</label>
                <textarea
                    id="imp-authors-enw"
                    name="authors"
                    rows="2"
                ><?= h((string) $authorsVal) ?></textarea>
            </div>

            <div class="search-row">
                <label for="imp-title-enw">Titolo</label>
                <textarea
                    id="imp-title-enw"
                    name="title"
                    rows="3"
                ><?= h((string) $titleVal) ?></textarea>
            </div>

            <div class="search-row">
                <label for="imp-pub-enw">Pubblicazione (luogo, editore, anno)</label>
                <textarea
                    id="imp-pub-enw"
                    name="pub"
                    rows="2"
                ><?= h((string) $pubVal) ?></textarea>
            </div>

            <div class="search-row">
                <label for="imp-phys-enw">Serie / edizione</label>
                <textarea
                    id="imp-phys-enw"
                    name="phys"
                    rows="2"
                ><?= h((string) $physVal) ?></textarea>
            </div>

            <div class="search-row">
                <label for="imp-abstract-enw">Riassunto / note</label>
                <textarea
                    id="imp-abstract-enw"
                    name="abstract"
                    rows="3"
                ><?= h((string) $abstractVal) ?></textarea>
            </div>

            <div class="search-row">
                <label for="imp-subjects-enw">Soggetti / parole chiave (facoltativo)</label>
                <textarea
                    id="imp-subjects-enw"
                    name="subjects"
                    rows="3"
                ><?= h((string) $subjectsVal) ?></textarea>
            </div>

            <div class="search-actions" style="margin-top:1rem;">
                <button type="button" class="btn-secondary"
                        onclick="window.location.href='<?= h($baseUrl) ?>/index.php?page=admin_import'">
                    Torna allo STEP 1 (selezione file)
                </button>
                <button type="submit" class="btn-primary">
                    STEP 3 – Crea record nel catalogo
                </button>
            </div>
        </form>

        <h2 style="margin-top:2rem;">Anteprima completa del file EndNote</h2>
        <pre style="background:#111827;color:#E5E7EB;padding:1rem;border-radius:6px;overflow:auto;font-size:0.8rem;">
<?= h((string) $marcTaggedText) ?>

        </pre>

    <?php else: ?>
        <p>
            Il tipo di file non è stato riconosciuto (nome: <?= h((string) $fileParam) ?>).
            Attualmente sono supportati:
            <strong>MARC21</strong> (.mrc, .iso, .marc) e
            <strong>EndNote</strong> (.txt, .enw).
        </p>
        <p>
            <a href="<?= h($baseUrl) ?>/index.php?page=admin_import">
                Torna alla pagina di caricamento
            </a>
        </p>
    <?php endif; ?>
</section>
