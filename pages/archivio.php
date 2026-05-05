<?php
declare(strict_types=1);

/**
 * Pagina Archivio storico – Biblioteca della Resistenza
 *
 * Elenco dei fascicoli conservati nell'archivio storico del
 * Comitato Provinciale ANPI di Udine.
 *
 * Tabella usata: archivio_storico
 * Colonne principali:
 *  - ID
 *  - Busta
 *  - Fascicolo
 *  - Serie
 *  - Sottoserie
 *  - Titolo del fascicolo
 *  - Descrizione documento
 *  - Estremi cronologici
 *  - Anno
 *
 * PHP 8.3
 *
 * @package BibliotecaResistenza\Pages
 */

/** @var PDO $pdo */
$pdo     = DB::conn();
$baseUrl = function_exists('base_url') ? base_url() : '';

// -----------------------------------------------------------------------------
// Parametri di input (GET)
// -----------------------------------------------------------------------------

$perPage = 20;

// Filtro di ricerca
$q = trim((string)($_GET['q'] ?? ''));

// Pagina corrente
$pageNum = (int)($_GET['p'] ?? 1);
if ($pageNum < 1) {
    $pageNum = 1;
}

// Ordinamento
$sort = (string)($_GET['sort'] ?? 'busta');
$dir  = strtolower((string)($_GET['dir'] ?? 'asc'));
$dir  = $dir === 'desc' ? 'desc' : 'asc';

/**
 * Mappa dei campi ordinabili -> nome colonna SQL (con backtick dove servono)
 */
$sortableMap = [
    'busta'        => 'Busta',
    'fascicolo'    => 'Fascicolo',
    'serie'        => 'Serie',
    'sottoserie'   => 'Sottoserie',
    'titolo'       => '`Titolo del fascicolo`',
    'descrizione'  => '`Descrizione documento`',
    'estremi'      => '`Estremi cronologici`',
    'anno'         => 'Anno',
];

if (!array_key_exists($sort, $sortableMap)) {
    $sort = 'busta';
}
$orderBy = $sortableMap[$sort] . ' ' . strtoupper($dir);

// -----------------------------------------------------------------------------
// Costruzione WHERE e parametri
// -----------------------------------------------------------------------------

$whereParts = ['1=1'];
$params     = [];

if ($q !== '') {
    $whereParts[] = '('
        . 'Busta LIKE :q OR '
        . 'Fascicolo LIKE :q OR '
        . 'Serie LIKE :q OR '
        . 'Sottoserie LIKE :q OR '
        . '`Titolo del fascicolo` LIKE :q OR '
        . '`Descrizione documento` LIKE :q OR '
        . '`Estremi cronologici` LIKE :q OR '
        . 'Anno LIKE :q'
        . ')';
    $params[':q'] = '%' . $q . '%';
}

$whereSql = implode(' AND ', $whereParts);

// -----------------------------------------------------------------------------
// Funzione helper per creare URL con parametri coerenti
// -----------------------------------------------------------------------------

/**
 * @param array<string,mixed> $overrides
 */
function archivio_build_url(string $baseUrl, array $overrides = []): string
{
    $params = [
        'page' => 'archivio',
    ];

    // Manteniamo eventuali parametri esistenti
    $current = [
        'q'    => $_GET['q']    ?? null,
        'sort' => $_GET['sort'] ?? null,
        'dir'  => $_GET['dir']  ?? null,
        'p'    => $_GET['p']    ?? null,
    ];

    foreach ($current as $k => $v) {
        if ($v !== null) {
            $params[$k] = $v;
        }
    }

    // Override espliciti
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }

    return $baseUrl . '/index.php?' . http_build_query($params);
}

// -----------------------------------------------------------------------------
// Query: conteggio totale + fetch pagina
// -----------------------------------------------------------------------------

$totalRows   = 0;
$totalPages  = 1;
$records     = [];
$errorMsg    = '';

try {
    // Conteggio
    $sqlCount = "SELECT COUNT(*) AS cnt
                 FROM archivio_storico
                 WHERE $whereSql";
    $stmt = $pdo->prepare($sqlCount);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->execute();
    $totalRows = (int)$stmt->fetchColumn();

    if ($totalRows > 0) {
        $totalPages = (int)ceil($totalRows / $perPage);
        if ($pageNum > $totalPages) {
            $pageNum = $totalPages;
        }
    } else {
        $totalPages = 1;
        $pageNum    = 1;
    }

    $offset = ($pageNum - 1) * $perPage;
    if ($offset < 0) {
        $offset = 0;
    }

    $sql = "SELECT
                ID,
                Busta,
                Fascicolo,
                Serie,
                Sottoserie,
                `Titolo del fascicolo`    AS titolo_fascicolo,
                `Descrizione documento`   AS descrizione_documento,
                `Estremi cronologici`     AS estremi_cronologici,
                Anno
            FROM archivio_storico
            WHERE $whereSql
            ORDER BY $orderBy
            LIMIT :limit
            OFFSET :offset";

    $stmt = $pdo->prepare($sql);

    // Parametri filtro
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }

    // Parametri paginazione
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);

    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (PDOException $e) {
    // Messaggio generico lato utente
    $errorMsg = "Si è verificato un problema nel caricamento dell'archivio.";
    $records  = [];
}

?>
<section class="page-section page-archivio">
    <header class="archive-header">
        <h1>Archivio storico – Biblioteca della Resistenza</h1>
        <p class="archive-intro">
            Elenco dei fascicoli conservati nell'archivio storico del Comitato Provinciale ANPI di Udine.
            Puoi filtrare i risultati, ordinarli per colonna e navigare tra le pagine.
        </p>
    </header>

    <!-- Filtro di ricerca -->
    <section class="archive-search">
        <form method="get" action="<?= h($baseUrl) ?>/index.php" class="search-form search-form-advanced">
            <input type="hidden" name="page" value="archivio">
            <div class="search-row-inline">
                <label for="archivio-q">Cerca nell'archivio</label>
                <input
                    type="text"
                    id="archivio-q"
                    name="q"
                    value="<?= h($q) ?>"
                    placeholder="Titolo del fascicolo, descrizione, serie, anno..."
                >
                <button type="submit" class="btn-primary">Cerca</button>
                <?php if ($q !== ''): ?>
                    <a
                        class="btn-link"
                        href="<?= h($baseUrl) ?>/index.php?page=archivio"
                    >
                        Pulisci filtro
                    </a>
                <?php endif; ?>
            </div>
            <?php if ($totalRows > 0): ?>
                <p class="search-help">
                    Risultati: <?= (int)$totalRows ?> &mdash;
                    Pagina <?= (int)$pageNum ?> di <?= (int)$totalPages ?>.
                </p>
            <?php endif; ?>
        </form>
    </section>

    <?php if ($errorMsg !== ''): ?>
        <div class="generic-box" style="margin-top:0.75rem;">
            <?= h($errorMsg) ?>
        </div>
    <?php elseif ($totalRows === 0): ?>
        <div class="generic-box" style="margin-top:0.75rem;">
            Nessun fascicolo trovato per i criteri indicati.
        </div>
    <?php else: ?>

        <!-- Tabella risultati -->
        <div class="archive-table-wrapper" style="margin-top:1rem; overflow-x:auto;">
            <table class="archive-table" style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                <thead>
                <tr>
                    <?php
                    // Helper per creare header ordinabile
                    /**
                     * @param string $label
                     * @param string $key
                     */
                    function archivio_sort_th(string $label, string $key, string $sort, string $dir, string $baseUrl, string $q): void {
                        $isActive = ($sort === $key);
                        $nextDir  = ($isActive && $dir === 'asc') ? 'desc' : 'asc';
                        $url      = archivio_build_url($baseUrl, [
                            'sort' => $key,
                            'dir'  => $nextDir,
                            'p'    => 1,
                            'q'    => $q,
                        ]);

                        $arrow = '';
                        if ($isActive) {
                            $arrow = $dir === 'asc' ? ' ↑' : ' ↓';
                        }

                        echo '<th style="border-bottom:1px solid #e5e7eb; padding:0.4rem 0.35rem; white-space:nowrap; text-align:left;">';
                        echo '<a href="' . h($url) . '" style="color:inherit; text-decoration:none;">';
                        echo h($label . $arrow);
                        echo '</a>';
                        echo '</th>';
                    }
                    ?>

                    <?php archivio_sort_th('Busta', 'busta', $sort, $dir, $baseUrl, $q); ?>
                    <?php archivio_sort_th('Fascicolo', 'fascicolo', $sort, $dir, $baseUrl, $q); ?>
                    <?php archivio_sort_th('Serie', 'serie', $sort, $dir, $baseUrl, $q); ?>
                    <?php archivio_sort_th('Sottoserie', 'sottoserie', $sort, $dir, $baseUrl, $q); ?>
                    <?php archivio_sort_th('Titolo del fascicolo', 'titolo', $sort, $dir, $baseUrl, $q); ?>
                    <?php archivio_sort_th('Descrizione documento', 'descrizione', $sort, $dir, $baseUrl, $q); ?>
                    <?php archivio_sort_th('Estremi cronologici', 'estremi', $sort, $dir, $baseUrl, $q); ?>
                    <?php archivio_sort_th('Anno', 'anno', $sort, $dir, $baseUrl, $q); ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $row): ?>
                    <tr>
                        <td style="border-bottom:1px solid #f3f4f6; padding:0.35rem 0.35rem;"><?= h((string)$row['Busta']) ?></td>
                        <td style="border-bottom:1px solid #f3f4f6; padding:0.35rem 0.35rem;"><?= h((string)$row['Fascicolo']) ?></td>
                        <td style="border-bottom:1px solid #f3f4f6; padding:0.35rem 0.35rem;"><?= h((string)$row['Serie']) ?></td>
                        <td style="border-bottom:1px solid #f3f4f6; padding:0.35rem 0.35rem;"><?= h((string)$row['Sottoserie']) ?></td>
                        <td style="border-bottom:1px solid #f3f4f6; padding:0.35rem 0.35rem; font-weight:600;">
                            <?= h((string)$row['titolo_fascicolo']) ?>
                        </td>
                        <td style="border-bottom:1px solid #f3f4f6; padding:0.35rem 0.35rem;">
                            <?= h((string)$row['descrizione_documento']) ?>
                        </td>
                        <td style="border-bottom:1px solid #f3f4f6; padding:0.35rem 0.35rem; white-space:nowrap;">
                            <?= h((string)$row['estremi_cronologici']) ?>
                        </td>
                        <td style="border-bottom:1px solid #f3f4f6; padding:0.35rem 0.35rem; white-space:nowrap;">
                            <?= h((string)$row['Anno']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginazione -->
        <nav class="pagination" aria-label="Navigazione pagine archivio" style="margin-top:1rem; display:flex; flex-wrap:wrap; gap:0.35rem; align-items:center;">
            <?php
            $firstUrl = archivio_build_url($baseUrl, ['p' => 1]);
            $prevUrl  = archivio_build_url($baseUrl, ['p' => max(1, $pageNum - 1)]);
            $nextUrl  = archivio_build_url($baseUrl, ['p' => min($totalPages, $pageNum + 1)]);
            $lastUrl  = archivio_build_url($baseUrl, ['p' => $totalPages]);
            ?>

            <a class="page-link" href="<?= h($firstUrl) ?>">&laquo; Prima</a>
            <a class="page-link" href="<?= h($prevUrl) ?>">&lsaquo; Prec.</a>

            <span style="margin:0 0.25rem;">
                Pagina <?= (int)$pageNum ?> di <?= (int)$totalPages ?>
            </span>

            <a class="page-link" href="<?= h($nextUrl) ?>">Succ. &rsaquo;</a>
            <a class="page-link" href="<?= h($lastUrl) ?>">Ultima &raquo;</a>

            <!-- Salto diretto a pagina -->
            <form
                method="get"
                action="<?= h($baseUrl) ?>/index.php"
                style="margin-left:0.75rem; display:flex; gap:0.25rem; align-items:center;"
            >
                <input type="hidden" name="page" value="archivio">
                <?php if ($q !== ''): ?>
                    <input type="hidden" name="q" value="<?= h($q) ?>">
                <?php endif; ?>
                <input type="hidden" name="sort" value="<?= h($sort) ?>">
                <input type="hidden" name="dir" value="<?= h($dir) ?>">
                <label for="jump-page" style="font-size:0.85rem;">Vai alla pagina</label>
                <input
                    id="jump-page"
                    type="number"
                    name="p"
                    min="1"
                    max="<?= (int)$totalPages ?>"
                    value="<?= (int)$pageNum ?>"
                    style="width:4rem; padding:0.2rem 0.3rem; font-size:0.85rem;"
                >
                <button type="submit" class="btn-secondary" style="padding:0.25rem 0.7rem; font-size:0.85rem;">
                    Vai
                </button>
            </form>
        </nav>

    <?php endif; ?>
</section>
