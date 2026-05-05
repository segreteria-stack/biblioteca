<?php
/**
 * Area Staff - Ricerca Z39.50 su OPAC SBN
 *
 * Funzione di interrogazione del catalogo SBN via Z39.50, con:
 * - host: opac.sbn.it
 * - porta: 2100
 * - database: nopac
 * - sintassi record: MARC21
 *
 * Richiede l'estensione PHP YAZ (funzioni yaz_*).
 *
 * @package BibliotecaResistenza\Pages
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// Protezione: accesso solo per staff autenticato
// -----------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    $baseUrl  = function_exists('base_url') ? base_url() : '';
    $redirect = 'staff_z3950';

    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=' . urlencode($redirect));
    exit;
}

// -----------------------------------------------------------------------------
// Helper: ricerca Z39.50 su OPAC SBN
// -----------------------------------------------------------------------------

if (!function_exists('sbn_z3950_search')) {
    /**
     * Esegue una ricerca su OPAC SBN via Z39.50 usando YAZ.
     *
     * @param string $queryType  isbn|title|author
     * @param string $term       Termine da cercare
     * @param int    $limit      Numero massimo di record da recuperare
     * @return array{
     *   error?:string,
     *   records?:array<int,array{number:int,raw:string}>,
     *   hits?:int
     * }
     */
    function sbn_z3950_search(string $queryType, string $term, int $limit = 10): array
    {
        // Verifica estensione YAZ
        if (!function_exists('yaz_connect')) {
            return [
                'error' => 'Le funzioni YAZ per Z39.50 non sono disponibili. '
                    . 'Verifica che l\'estensione PHP "yaz" sia installata e abilitata.'
            ];
        }

        $term  = trim($term);
        $limit = max(1, min($limit, 20));

        if ($term === '') {
            return ['error' => 'Inserisci un termine di ricerca.'];
        }

        // Mappatura tipo -> attributo Bib-1 (use attribute)
        // Riferimento: accesso Z39.50 a OPAC SBN (Bib-1)
        switch ($queryType) {
            case 'isbn':
                $useAttr = '7';     // ISBN
                break;
            case 'author':
                $useAttr = '1003';  // Nome personale
                break;
            case 'title':
            default:
                $useAttr = '4';     // Titolo
                break;
        }

        // Costruzione query PQF (Prefix Query Format) per YAZ
        // Esempio: @attr 1=7 "9788804670988"
        $pqf = '@attr 1=' . $useAttr . ' "' . $term . '"';

        // Parametri OPAC SBN (fonte: ICCU - Accesso Z39.50 a OPAC SBN)
        $host = 'opac.sbn.it';
        $port = 2100;
        $db   = 'nopac';

        $connStr = $host . ':' . $port . '/' . $db;

        $id = @yaz_connect($connStr);
        if ($id === false) {
            return ['error' => 'Impossibile connettersi al server Z39.50 di SBN.'];
        }

        // Richiediamo record in sintassi MARC21 (supportata da OPAC SBN)
        @yaz_syntax($id, 'MARC21');
        // Charset UTF-8 (come da specifica OPAC SBN)
        @yaz_set_option($id, 'charset', 'utf-8');

        // Impostiamo il range di record da recuperare (1..$limit)
        @yaz_range($id, 1, $limit);

        // Avviamo la ricerca in modalità RPN usando PQF
        @yaz_search($id, 'rpn', $pqf);
        @yaz_wait();

        $err = yaz_error($id);
        if ($err) {
            $code    = yaz_errno($id);
            $addInfo = yaz_addinfo($id);

            @yaz_close($id);

            $msg = "Errore Z39.50 ($code): $err";
            if (!empty($addInfo)) {
                $msg .= ' - ' . $addInfo;
            }

            return ['error' => $msg];
        }

        $hits = yaz_hits($id);
        if ($hits <= 0) {
            @yaz_close($id);
            return ['error' => 'Nessun record trovato su SBN per la ricerca indicata.'];
        }

        $max     = min($hits, $limit);
        $records = [];

        for ($i = 1; $i <= $max; $i++) {
            // Recupero record grezzo in MARC (MARC21, ISO 2709)
            $raw = yaz_record($id, $i, 'raw');
            if ($raw === false || $raw === null) {
                continue;
            }

            $records[] = [
                'number' => $i,
                'raw'    => $raw,
            ];
        }

        @yaz_close($id);

        if ($records === []) {
            return [
                'error' => 'La ricerca ha prodotto risultati, ma non è stato possibile '
                         . 'recuperare i record dal server Z39.50.'
            ];
        }

        return [
            'records' => $records,
            'hits'    => $hits,
        ];
    }
}

// -----------------------------------------------------------------------------
// Logica pagina
// -----------------------------------------------------------------------------

$baseUrl   = function_exists('base_url') ? base_url() : '';
$staffName = $_SESSION['staff_fullname'] ?? ($_SESSION['staff_username'] ?? 'Operatore');

$queryType = (string)($_POST['query_type'] ?? $_GET['query_type'] ?? 'isbn');
$term      = (string)($_POST['term'] ?? $_GET['term'] ?? '');
$limit     = (int)($_POST['limit'] ?? $_GET['limit'] ?? 10);

$zError    = '';
$zResults  = [];
$zHits     = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = sbn_z3950_search($queryType, $term, $limit);

    if (isset($result['error'])) {
        $zError = (string)$result['error'];
    } else {
        $zResults = $result['records'] ?? [];
        $zHits    = (int)($result['hits'] ?? count($zResults));
    }
}

?>
<section class="page-section page-staff-z3950">
    <header class="staff-header">
        <div class="staff-header-top">
            <h1>Acquisizione da OPAC SBN (Z39.50)</h1>
            <div class="staff-current-user">
                <span>Collegato come:</span>
                <strong><?= h($staffName) ?></strong>
            </div>
        </div>

        <p class="staff-header-subtitle">
            Cerca nel Catalogo SBN tramite protocollo Z39.50 (MARC21) per recuperare i
            metadati dei libri. In questa prima fase i record sono mostrati in forma
            grezza; in seguito potremo mappare i campi direttamente nel catalogo locale.
        </p>

        <p style="margin-top:0.4rem;font-size:0.85rem;color:#666;">
            Server: opac.sbn.it : 2100 / database <code>nopac</code> – sintassi record: MARC21.
        </p>
    </header>

    <section class="page-section" style="margin-top:1rem;">
        <h2>Ricerca Z39.50 su SBN</h2>

        <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_z3950" class="search-form">
            <div class="search-row-inline">
                <div>
                    <label for="z3950-query-type">Campo di ricerca</label>
                    <select id="z3950-query-type" name="query_type">
                        <option value="isbn"   <?= $queryType === 'isbn'   ? 'selected' : '' ?>>ISBN</option>
                        <option value="title"  <?= $queryType === 'title'  ? 'selected' : '' ?>>Titolo</option>
                        <option value="author" <?= $queryType === 'author' ? 'selected' : '' ?>>Autore</option>
                    </select>
                </div>

                <div style="flex:1 1 260px;">
                    <label for="z3950-term">Termine da cercare</label>
                    <input
                        type="text"
                        id="z3950-term"
                        name="term"
                        value="<?= h($term) ?>"
                        placeholder="Es. 9788804670988 oppure titolo / autore"
                        required
                    >
                </div>

                <div style="max-width:120px;">
                    <label for="z3950-limit">N. record</label>
                    <input
                        type="number"
                        id="z3950-limit"
                        name="limit"
                        min="1"
                        max="20"
                        value="<?= $limit > 0 ? (int)$limit : 10 ?>"
                    >
                </div>
            </div>

            <div class="search-actions">
                <button type="submit" class="btn-primary">
                    Avvia ricerca su SBN
                </button>

                <a class="btn-link" href="<?= h($baseUrl) ?>/index.php?page=staff">
                    Torna alla dashboard staff
                </a>
            </div>
        </form>

        <?php if ($zError !== ''): ?>
            <div class="generic-box" style="margin-top:1rem;">
                <p><?= nl2br(h($zError)) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($zResults !== []): ?>
            <div class="generic-box" style="margin-top:1.25rem;">
                <p style="margin-bottom:0.5rem;">
                    Risultati SBN: trovati <?= (int)$zHits ?> record.
                    Ne mostriamo i primi <?= count($zResults) ?>.
                </p>

                <?php foreach ($zResults as $rec): ?>
                    <article class="z3950-record" style="margin-top:0.75rem;">
                        <h3 style="font-size:0.98rem;margin:0 0 0.4rem 0;">
                            Record <?= (int)$rec['number'] ?> (MARC21 grezzo)
                        </h3>
                        <pre style="white-space:pre-wrap;font-size:0.8rem;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;padding:0.6rem;max-height:260px;overflow:auto;">
<?= h($rec['raw']) ?>
                        </pre>

                        <p style="margin-top:0.4rem;font-size:0.8rem;color:#666;">
                            (In una fase successiva aggiungeremo il pulsante per importare questo record nel catalogo locale.)
                        </p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>
