<?php
declare(strict_types=1);

/**
 * Pagina "Tutti i temi" (tag cloud completa)
 *
 * URL previsto: index.php?page=topics
 *
 * Mostra tutti i soggetti (topic1..topic5) estratti dalla tabella `biblio`,
 * raggruppati per iniziale alfabetica con indice A-Z e ordinati per popolarità
 * all'interno di ciascun gruppo.
 */

$baseUrl = function_exists('base_url') ? base_url() : '';

try {
    /** @var PDO $pdo */
    $pdo = DB::conn();
} catch (Throwable $e) {
    // Se il DB non è disponibile, mostriamo un messaggio amichevole.
    ?>
    <section class="page-section page-section--topics">
        <h1>Esplora tutti i temi</h1>
        <p>Al momento non è possibile recuperare l'elenco dei temi.</p>
    </section>
    <?php
    return;
}

// -----------------------------------------------------------------------------
// 1) Recupero soggetti dalle colonne topic1..topic5
// -----------------------------------------------------------------------------

$sql = '
    SELECT
        topic1,
        topic2,
        topic3,
        topic4,
        topic5
    FROM biblio
';

try {
    $stmt = $pdo->query($sql);
} catch (PDOException $e) {
    ?>
    <section class="page-section page-section--topics">
        <h1>Esplora tutti i temi</h1>
        <p>Si è verificato un errore nel recupero dei temi.</p>
    </section>
    <?php
    return;
}

$frequencies = []; // [etichetta => conteggio]

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    foreach (['topic1', 'topic2', 'topic3', 'topic4', 'topic5'] as $col) {
        if (!array_key_exists($col, $row)) {
            continue;
        }

        $raw = $row[$col];

        if ($raw === null || $raw === '') {
            continue;
        }

        if (!is_string($raw)) {
            $raw = (string)$raw;
        }

        // Più soggetti eventualmente separati da ; o ,
        $parts = preg_split('/[;|,]+/', $raw);
        if (!is_array($parts)) {
            $parts = [$raw];
        }

        foreach ($parts as $part) {
            $label = trim((string)$part);
            if ($label === '') {
                continue;
            }

            if (!isset($frequencies[$label])) {
                $frequencies[$label] = 0;
            }
            $frequencies[$label]++;
        }
    }
}

if ($frequencies === []) {
    ?>
    <section class="page-section page-section--topics">
        <h1>Esplora tutti i temi</h1>
        <p>Al momento non ci sono temi disponibili nel catalogo.</p>
        <p>
            <a href="<?= h($baseUrl) ?>/index.php" class="btn-link">
                Torna alla home del catalogo
            </a>
        </p>
    </section>
    <?php
    return;
}

// -----------------------------------------------------------------------------
// 2) Popolarità globale per evidenziare i temi più usati
// -----------------------------------------------------------------------------

$freqSorted = $frequencies;
arsort($freqSorted); // più usati prima

$popularLabels = [];
$maxPopular    = 24; // soggetti evidenziati come più frequenti
$counter       = 0;

foreach ($freqSorted as $label => $count) {
    $label = (string)$label;
    if ($label === '') {
        continue;
    }
    $popularLabels[$label] = true;
    $counter++;
    if ($counter >= $maxPopular) {
        break;
    }
}

// -----------------------------------------------------------------------------
// 3) Raggruppamento alfabetico + ordinamento per popolarità nel gruppo
// -----------------------------------------------------------------------------

$groups = []; // es. 'A' => [ ['label'=>..., 'use_count'=>...], ... ]

foreach ($frequencies as $label => $count) {
    $label = trim((string)$label);
    if ($label === '') {
        continue;
    }

    // Ricava la prima lettera (con fallback senza mbstring)
    $firstChar = null;
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $firstChar = mb_strtoupper(mb_substr($label, 0, 1, 'UTF-8'), 'UTF-8');
    } else {
        $firstChar = strtoupper(substr($label, 0, 1));
    }

    // Se non è A-Z, mettiamo nel gruppo "#"
    if (!preg_match('/[A-Z]/', $firstChar)) {
        $key = '#';
    } else {
        $key = $firstChar;
    }

    if (!isset($groups[$key])) {
        $groups[$key] = [];
    }

    $groups[$key][] = [
        'label'     => $label,
        'use_count' => (int)$count,
    ];
}

// Ordine dei gruppi: A-Z, poi eventualmente "#"
$groupKeys = array_keys($groups);
sort($groupKeys, SORT_STRING);

if (in_array('#', $groupKeys, true)) {
    // sposta '#' in fondo
    $groupKeys = array_values(array_diff($groupKeys, ['#']));
    $groupKeys[] = '#';
}

// Ordina i temi dentro ogni gruppo per popolarità decrescente, poi alfabetico
foreach ($groups as $key => &$items) {
    usort($items, function (array $a, array $b): int {
        $countA = (int)($a['use_count'] ?? 0);
        $countB = (int)($b['use_count'] ?? 0);

        if ($countA === $countB) {
            $labelA = (string)($a['label'] ?? '');
            $labelB = (string)($b['label'] ?? '');
            return strcmp($labelA, $labelB);
        }

        // Più usati prima
        return ($countA < $countB) ? 1 : -1;
    });
}
unset($items);

$totalTopics = count($frequencies);
?>

<section class="page-section page-section--topics">
    <header class="topics-header">
        <h1>Esplora tutti i temi</h1>
        <p class="home-hero-subtitle">
            Elenco completo dei soggetti utilizzati nel catalogo,
            raggruppati per iniziale. Clicca su un tema per vedere i titoli collegati.
        </p>
    </header>

    <p>
        Sono attualmente presenti
        <strong><?= (int)$totalTopics ?></strong>
        temi diversi in catalogo.
    </p>

    <p class="topics-actions">
        <a href="<?= h($baseUrl) ?>/index.php" class="btn-link">
            Torna alla home del catalogo
        </a>
    </p>

    <!-- Indice alfabetico A-Z -->
    <nav class="topics-index" aria-label="Indice alfabetico dei temi">
        <ul class="topics-index-list">
            <?php foreach ($groupKeys as $key): ?>
                <?php
                $label = ($key === '#') ? '#' : $key;
                $anchorId = 'topics-' . $label;
                ?>
                <li class="topics-index-item">
                    <a href="#<?= h($anchorId) ?>" class="topics-index-link">
                        <?= h($label) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <?php foreach ($groupKeys as $key): ?>
        <?php
        $items    = $groups[$key] ?? [];
        if ($items === []) {
            continue;
        }

        $sectionLabel = ($key === '#') ? 'Altro' : $key;
        $anchorId     = 'topics-' . $sectionLabel;
        ?>
        <section class="topics-group" id="<?= h($anchorId) ?>">
            <h2 class="topics-group-title">
                <?= h($sectionLabel) ?>
            </h2>

            <ul class="tag-cloud-list topics-group-list">
                <?php foreach ($items as $topic): ?>
                    <?php
                    $label = trim((string)($topic['label'] ?? ''));
                    if ($label === '') {
                        continue;
                    }

                    $count = (int)($topic['use_count'] ?? 0);
                    $isPopular = !empty($popularLabels[$label]);

                    $href = $baseUrl . '/index.php?page=search&subject=' . rawurlencode($label);
                    ?>
                    <li>
                        <a
                            href="<?= h($href) ?>"
                            class="subject-tag<?= $isPopular ? ' subject-tag--popular' : '' ?>"
                            title="Vedi titoli con tema &quot;<?= h($label) ?>&quot;"
                        >
                            <?= h($label) ?>
                            <?php if ($count > 0): ?>
                                <span class="subject-count">
                                    (<?= (int)$count ?>)
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</section>
