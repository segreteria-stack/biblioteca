<?php
declare(strict_types=1);

/**
 * Tag cloud / Esplora per tema (versione compatta per homepage)
 *
 * Estrae i soggetti dalle colonne topic1..topic5 della tabella `biblio`,
 * conta le occorrenze e visualizza un elenco limitato di tag (pillole)
 * cliccabili che reindirizzano alla ricerca per soggetto.
 *
 * Da qui è possibile andare alla pagina completa "tutti i temi".
 */

/** @var string $page (opzionale, arriva dal template principale) */

$baseUrl = function_exists('base_url') ? base_url() : '';

try {
    /** @var PDO $pdo */
    $pdo = DB::conn();
} catch (Throwable $e) {
    // Se il DB non è disponibile, non mostriamo la nuvoletta ma non blocchiamo la pagina.
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
    // In caso di errore DB, non mostriamo la tag cloud ma non blocchiamo il resto.
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

        // Se per caso è un numero, lo castiamo a stringa.
        if (!is_string($raw)) {
            $raw = (string)$raw;
        }

        // Alcuni sistemi separano più soggetti con ; oppure , : li dividiamo.
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
    // Nessun soggetto da mostrare.
    return;
}

// -----------------------------------------------------------------------------
// 2) Normalizzazione in array e individuazione dei più usati
// -----------------------------------------------------------------------------

$topics = []; // ogni elemento: ['label' => string, 'use_count' => int]

foreach ($frequencies as $label => $count) {
    $topics[] = [
        'label'     => (string)$label,
        'use_count' => (int)$count,
    ];
}

// Ordiniamo per frequenza decrescente, poi alfabetico (per determinare i "popolari")
usort($topics, function (array $a, array $b): int {
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

// Identifichiamo i "più usati" (per esempio i primi 10)
$popularLabels = [];
$maxPopular    = min(10, count($topics));
for ($i = 0; $i < $maxPopular; $i++) {
    $label = (string)($topics[$i]['label'] ?? '');
    if ($label !== '') {
        $popularLabels[$label] = true;
    }
}

// Per varietà visiva mescoliamo l'ordine di visualizzazione nella home
shuffle($topics);

// Limitiamo il numero massimo di tag mostrati nella home (per non sovraccaricare la pagina)
$maxTagsToShow = 12;
if (count($topics) > $maxTagsToShow) {
    $topics = array_slice($topics, 0, $maxTagsToShow);
}
?>

<section class="tag-cloud" aria-labelledby="tag-cloud-title">
    <h2 id="tag-cloud-title" class="tag-cloud-title">
        Esplora per tema
    </h2>

    <ul class="tag-cloud-list">
        <?php foreach ($topics as $topic): ?>
            <?php
            $label = trim((string)($topic['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $isPopular = !empty($popularLabels[$label]);

            // Link alla ricerca per soggetto:
            // verrà intercettato da search.php con parametro "subject"
            $href = $baseUrl . '/index.php?page=search&subject=' . rawurlencode($label);
            ?>
            <li>
                <a
                    href="<?= h($href) ?>"
                    class="subject-tag<?= $isPopular ? ' subject-tag--popular' : '' ?>"
                >
                    <?= h($label) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="tag-cloud-more-wrap">
        <a
            href="<?= h($baseUrl) ?>/index.php?page=topics"
            class="btn-link tag-cloud-more"
        >
            Mostra tutti i temi
        </a>
    </div>
</section>
