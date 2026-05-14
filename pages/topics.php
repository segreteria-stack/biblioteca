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
require_once __DIR__ . '/../lib/marc_helpers.php';

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
// 1) Recupero soggetti da topic1..5 + biblio_field 650/651 $a
//    Una query UNION carica (bibid, raw_topic), poi PHP normalizza e conta.
// -----------------------------------------------------------------------------

$frequencies = []; // [label_normalizzata => count(distinct bibid)]

try {
    $stmt = $pdo->query("
        SELECT bibid, topic FROM (
            SELECT bibid, topic1 AS topic FROM biblio WHERE opac_flg='Y' AND topic1 <> ''
            UNION ALL SELECT bibid, topic2 FROM biblio WHERE opac_flg='Y' AND topic2 <> ''
            UNION ALL SELECT bibid, topic3 FROM biblio WHERE opac_flg='Y' AND topic3 <> ''
            UNION ALL SELECT bibid, topic4 FROM biblio WHERE opac_flg='Y' AND topic4 <> ''
            UNION ALL SELECT bibid, topic5 FROM biblio WHERE opac_flg='Y' AND topic5 <> ''
            UNION ALL
            SELECT bf.bibid, bf.field_data FROM biblio_field bf
            JOIN biblio b ON b.bibid = bf.bibid AND b.opac_flg = 'Y'
            WHERE bf.tag IN (650, 651) AND bf.subfield_cd = 'a' AND bf.field_data <> ''
        ) t
        ORDER BY bibid
    ");

    // Conta distinct bibid per label normalizzata (soggetti composti splittati)
    $seenBibidPerLabel = []; // [label_key => [bibid => true]]
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $bibid  = (int)$row['bibid'];
        $labels = marc_split_subject_string((string)($row['topic'] ?? ''));
        foreach ($labels as $label) {
            $key = mb_strtolower($label, 'UTF-8');
            if (!isset($seenBibidPerLabel[$key])) {
                $seenBibidPerLabel[$key] = ['label' => $label, 'bibids' => []];
            }
            $seenBibidPerLabel[$key]['bibids'][$bibid] = true;
        }
    }
    foreach ($seenBibidPerLabel as $entry) {
        $frequencies[(string)$entry['label']] = count($entry['bibids']);
    }
} catch (PDOException $e) {
    ?>
    <section class="page-section page-section--topics">
        <h1>Esplora tutti i temi</h1>
        <p>Si è verificato un errore nel recupero dei temi.</p>
    </section>
    <?php
    return;
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
    <div class="topics-layout">

        <!-- Contenuto principale: header + gruppi per lettera -->
        <div class="topics-content">
            <header class="topics-header">
                <h1>Esplora tutti i temi</h1>
                <p class="topics-subtitle">
                    <?= (int)$totalTopics ?> soggetti nel catalogo —
                    clicca su un tema per vedere i titoli collegati.
                </p>
            </header>

            <?php foreach ($groupKeys as $key):
                $items = $groups[$key] ?? [];
                if ($items === []) continue;
                $sectionLabel = ($key === '#') ? 'Altro' : $key;
                $anchorId     = 'topics-' . $sectionLabel;
            ?>
            <section class="topics-group" id="<?= h($anchorId) ?>">
                <h2 class="topics-group-title"><?= h($sectionLabel) ?></h2>
                <ul class="tag-cloud-list">
                    <?php foreach ($items as $topic):
                        $label = trim((string)($topic['label'] ?? ''));
                        if ($label === '') continue;
                        $count     = (int)($topic['use_count'] ?? 0);
                        $isPopular = !empty($popularLabels[$label]);
                        $href      = $baseUrl . '/index.php?page=search&subject=' . rawurlencode($label);
                    ?>
                        <li>
                            <a href="<?= h($href) ?>"
                               class="subject-tag<?= $isPopular ? ' subject-tag--popular' : '' ?>"
                               title="<?= h($label) ?> (<?= $count ?> titol<?= $count === 1 ? 'o' : 'i' ?>)">
                                <?= h($label) ?><?php if ($count > 1): ?><span class="subject-count"> (<?= $count ?>)</span><?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endforeach; ?>

            <p class="topics-back">
                <a href="<?= h($baseUrl) ?>/index.php" class="btn-link">← Torna alla home</a>
            </p>
        </div>

        <!-- Indice alfabetico A-Z — barra verticale fissa a destra -->
        <nav class="topics-index" aria-label="Indice alfabetico dei temi">
            <?php foreach ($groupKeys as $key):
                $display      = ($key === '#') ? '#' : $key;
                $sectionLabel = ($key === '#') ? 'Altro' : $key;
                $anchorId     = 'topics-' . $sectionLabel;
            ?>
                <a href="#<?= h($anchorId) ?>" class="topics-index-link"><?= h($display) ?></a>
            <?php endforeach; ?>
        </nav>

    </div>
</section>
