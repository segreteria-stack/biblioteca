<?php
declare(strict_types=1);

/**
 * Percorsi tematici e suggerimenti di lettura
 */

$baseUrl  = function_exists('base_url') ? base_url() : '';
$indexUrl = $baseUrl . '/index.php';

$percorsi = [
    [
        'icon'    => '🎖️',
        'titolo'  => 'La Resistenza partigiana',
        'desc'    => 'Storie, documenti e protagonisti della lotta di liberazione in Italia e in Friuli. Dal primo antifascismo alla Liberazione del 1945.',
        'href'    => $indexUrl . '?page=search&subject=' . urlencode('Resistenza'),
        'href_label' => 'Cerca nel catalogo',
        'extra'   => null,
    ],
    [
        'icon'    => '✊',
        'titolo'  => 'Antifascismo',
        'desc'    => 'Il movimento antifascista dalle origini alla Liberazione: figure, organizzazioni, lotte. L\'eredità politica e culturale nella storia italiana.',
        'href'    => $indexUrl . '?page=search&subject=' . urlencode('Antifascismo'),
        'href_label' => 'Cerca nel catalogo',
        'extra'   => null,
    ],
    [
        'icon'    => '🗺️',
        'titolo'  => 'Storia del Friuli-Venezia Giulia',
        'desc'    => 'Il territorio locale attraverso i secoli: comunità, confini, culture, lingue. La storia della Venezia Giulia, del Friuli e di Trieste nel Novecento.',
        'href'    => $indexUrl . '?page=search&subject=' . urlencode('Friuli-Venezia Giulia'),
        'href_label' => 'Cerca nel catalogo',
        'extra'   => null,
    ],
    [
        'icon'    => '⚔️',
        'titolo'  => 'Seconda guerra mondiale',
        'desc'    => 'La guerra sui fronti italiano ed europeo: occupazione nazifascista, deportazioni, stragi, lotta di liberazione. Memoria e ricostruzione storica.',
        'href'    => $indexUrl . '?page=search&subject=' . urlencode('Guerra mondiale, 2'),
        'href_label' => 'Cerca nel catalogo',
        'extra'   => null,
    ],
    [
        'icon'    => '📖',
        'titolo'  => 'Memorie e testimonianze',
        'desc'    => 'Diari, lettere e memorie di protagonisti del Novecento. Le voci dirette di chi ha vissuto la guerra, la deportazione, la clandestinità.',
        'href'    => $indexUrl . '?page=search&subject=' . urlencode('Memorie'),
        'href_label' => 'Cerca nel catalogo',
        'extra'   => null,
    ],
    [
        'icon'    => '✍️',
        'titolo'  => 'Biografie e figure storiche',
        'desc'    => 'Vite di partigiani, comandanti, antifascisti, intellettuali e figure chiave della storia italiana. Dal Risorgimento al secondo Dopoguerra.',
        'href'    => $indexUrl . '?page=search&subject=' . urlencode('Biografie'),
        'href_label' => 'Cerca nel catalogo',
        'extra'   => null,
    ],
    [
        'icon'    => '🏭',
        'titolo'  => 'Lavoro e movimento operaio',
        'desc'    => 'Storia del lavoro, delle organizzazioni sindacali e delle lotte sociali nel Novecento. Il proletariato italiano tra fascismo, guerra e ricostruzione.',
        'href'    => $indexUrl . '?page=search&subject=' . urlencode('Movimento operaio'),
        'href_label' => 'Cerca nel catalogo',
        'extra'   => null,
    ],
    [
        'icon'    => '🌍',
        'titolo'  => 'Storia contemporanea',
        'desc'    => 'L\'Italia e l\'Europa nel Novecento: Repubblica, dopoguerra, anni di piombo, caduta del Muro. Un secolo di storia politica, sociale e culturale.',
        'href'    => $indexUrl . '?page=search&subject=' . urlencode('Storia contemporanea'),
        'href_label' => 'Cerca nel catalogo',
        'extra'   => null,
    ],
];
?>
<section class="page-section page-percorsi">
    <header class="percorsi-header">
        <h1>Percorsi tematici</h1>
        <p class="percorsi-intro">
            Suggerimenti di lettura organizzati per tema. Ogni percorso raccoglie i principali
            filoni della collezione della <strong>Biblioteca della Resistenza</strong>:
            storia, memoria, antifascismo, territorio.
        </p>
        <p class="percorsi-intro">
            Seleziona un tema per esplorare i titoli disponibili nel catalogo, oppure
            <a href="<?= h($baseUrl . '/index.php?page=topics') ?>">sfoglia tutti i soggetti</a>.
        </p>
    </header>

    <div class="percorsi-grid">
        <?php foreach ($percorsi as $p): ?>
            <article class="percorso-card">
                <div class="percorso-icon" aria-hidden="true"><?= $p['icon'] ?></div>
                <div class="percorso-body">
                    <h2 class="percorso-title"><?= h($p['titolo']) ?></h2>
                    <p class="percorso-desc"><?= h($p['desc']) ?></p>
                    <a href="<?= h($p['href']) ?>" class="percorso-link">
                        <?= h($p['href_label']) ?> →
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="percorsi-footer">
        <p>
            La collezione comprende oltre 6.000 titoli su storia, antifascismo, Resistenza,
            letteratura e territorio. Per ricerche più precise usa la
            <a href="<?= h($baseUrl . '/index.php?page=search_advanced') ?>">ricerca avanzata</a>.
        </p>
    </div>
</section>
