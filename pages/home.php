<?php
/**
 * Homepage dell'OPAC Biblioteca della Resistenza.
 *
 * PHP version 8.3
 *
 * @package BibliotecaResistenza\Pages
 */

declare(strict_types=1);

$baseUrl = base_url();

/** @var \PDO $pdo */
$pdo = DB::conn();

/**
 * Estrae gli ultimi libri inseriti nel catalogo.
 *
 * @param \PDO $pdo
 * @param int  $maxItems
 * @return array<int,array<string,mixed>>
 */
function home_fetch_carousel_items(PDO $pdo, int $maxItems = 8): array
{
    $maxItems = max(1, $maxItems);

    try {
        $sql = '
            SELECT
                b.bibid,
                b.title,
                b.title_remainder,
                b.author,
                (
                    SELECT bf.field_data
                    FROM biblio_field bf
                    WHERE bf.bibid = b.bibid
                      AND bf.tag = 20
                      AND bf.subfield_cd = \'a\'
                    ORDER BY bf.fieldid
                    LIMIT 1
                ) AS isbn
            FROM biblio b
            ORDER BY b.bibid DESC
            LIMIT ' . (int) $maxItems . '
        ';

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === []) {
            return [];
        }
    } catch (\PDOException $e) {
        return [];
    }

    $items = [];

    foreach ($rows as $row) {
        $bibid  = (int)($row['bibid'] ?? 0);
        $title  = trim((string)($row['title'] ?? ''));
        $rem    = trim((string)($row['title_remainder'] ?? ''));
        $author = trim((string)($row['author'] ?? ''));
        $isbn   = trim((string)($row['isbn'] ?? ''));

        if ($bibid <= 0) continue;

        $fullTitle = trim($title . ' ' . $rem);
        if ($fullTitle === '') $fullTitle = '[Senza titolo]';

        // Usiamo CoverService per normalizzare ISBN e ottenere URL iniziale
        $isbnOrig = CoverService::getIsbnForJs($isbn);
        $isbn13   = CoverService::toIsbn13($isbn);

        $items[] = [
            'bibid'     => $bibid,
            'title'     => $fullTitle,
            'author'    => $author,
            'isbnOrig'  => $isbnOrig,
            'isbn13'    => $isbn13,
            // PHP serve cache locale se disponibile, altrimenti placeholder
            'coverUrl'  => CoverService::getCoverUrl($isbn, $fullTitle, $author),
            'placeholder' => CoverService::placeholderUrl($fullTitle),
            'hasLocal'  => ($isbn13 !== '' && CoverService::hasLocalCover($isbn13)),
        ];
    }

    return $items;
}

$carouselItems = home_fetch_carousel_items($pdo, 8);
$gbApiKey = $GLOBALS['cfg']['google_books']['api_key'] ?? '';

// Contatore titoli in catalogo
$totalTitoli = 0;
try {
    $totalTitoli = (int)$pdo->query('SELECT COUNT(*) FROM biblio')->fetchColumn();
} catch (\PDOException $e) {}

// Top 12 soggetti per la sezione "Esplora per tema"
$topTopics = [];
try {
    $sqlTopics = '
        SELECT topic, COUNT(*) AS cnt
        FROM (
            SELECT topic1 AS topic FROM biblio WHERE topic1 <> \'\'
            UNION ALL SELECT topic2 FROM biblio WHERE topic2 <> \'\'
            UNION ALL SELECT topic3 FROM biblio WHERE topic3 <> \'\'
            UNION ALL SELECT topic4 FROM biblio WHERE topic4 <> \'\'
            UNION ALL SELECT topic5 FROM biblio WHERE topic5 <> \'\'
        ) t
        GROUP BY topic
        ORDER BY cnt DESC
        LIMIT 14
    ';
    $topTopics = $pdo->query($sqlTopics)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\PDOException $e) {}
?>

<!-- HERO A TUTTA LARGHEZZA CON IMMAGINE STORICA + RICERCA -->
<section class="home-hero-banner">
    <div class="container home-hero-banner-inner">
        <div class="home-hero-banner-text">
            <div class="home-hero-badge">
                <span class="home-hero-badge-dot"></span>
                Archivio vivo della memoria democratica
            </div>
            <?php if ($totalTitoli > 0): ?>
            <div class="home-hero-stat">
                <strong><?= number_format($totalTitoli, 0, ',', '.') ?></strong> titoli in catalogo
            </div>
            <?php endif; ?>
            <div class="home-hero-tagline">Biblioteca · Archivi · Memorie</div>

            <h1>Biblioteca<br>della Resistenza</h1>
            <p>
                Libri, opuscoli e documenti sulla storia della Resistenza, dell'antifascismo
                e della memoria del Novecento, con particolare attenzione al Friuli Venezia Giulia.
            </p>
        </div>

        <div class="home-hero-search-card">
            <h2>Cerca nel catalogo</h2>
            <p>Inserisci titolo, autore, soggetto o ISBN per iniziare la ricerca.</p>

            <form class="search-form-simple" action="<?= h($baseUrl) ?>/index.php" method="get">
                <input type="hidden" name="page" value="search">
                <div class="search-form-simple-main">
                    <label for="q-home-hero" class="sr-only">Parole chiave</label>
                    <input
                        type="text"
                        id="q-home-hero"
                        name="q"
                        value="<?= h((string) ($_GET['q'] ?? '')) ?>"
                        placeholder="Titolo, autore, soggetto…"
                        autofocus
                    >
                    <button type="submit" class="btn-primary">
                        Cerca
                    </button>
                </div>
            </form>

            <div class="home-hero-search-links">
                <a href="<?= h($baseUrl) ?>/index.php?page=search_advanced">
                    Ricerca avanzata
                </a>
                <span>Suggerimento: bastano poche parole chiave.</span>
            </div>
        </div>
    </div>
</section>

<!-- CARD CENTRALE -->
<section class="page-section page-section--home">
    <header class="home-hero">
        <div class="home-status">
            <strong>Nota:</strong>
            questo catalogo è in fase di sviluppo e aggiornamento continuo.
            Alcune funzioni sono ancora in lavorazione.
        </div>
    </header>

    <?php if (!empty($topTopics)): ?>
        <section class="home-highlight home-highlight--topics">
            <h2 class="home-highlight-title">
                Esplora per tema
                <a class="home-highlight-more" href="<?= h($baseUrl) ?>/index.php?page=topics">Tutti i temi →</a>
            </h2>
            <div class="home-topics-chips">
                <?php foreach ($topTopics as $t): ?>
                    <a
                        class="home-topic-chip"
                        href="<?= h($baseUrl) ?>/index.php?page=search&amp;q=<?= urlencode((string)$t['topic']) ?>"
                    ><?= h((string)$t['topic']) ?> <span class="home-topic-chip-count"><?= (int)$t['cnt'] ?></span></a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($carouselItems)): ?>
        <section class="home-highlight home-highlight--latest">
            <h2 class="home-highlight-title">Ultimi inserimenti in catalogo</h2>

            <div class="home-latest-grid">
                <?php foreach ($carouselItems as $item): ?>
                    <?php
                    $bibid      = (int) $item['bibid'];
                    $title      = (string) $item['title'];
                    $author     = (string) $item['author'];
                    $isbnOrig   = (string) $item['isbnOrig'];
                    $isbn13     = (string) $item['isbn13'];
                    $coverUrl   = (string) $item['coverUrl'];
                    $placeholder = (string) $item['placeholder'];
                    $hasLocal   = (bool) $item['hasLocal'];
                    ?>
                    <a
                        class="cover-slide"
                        href="<?= h($baseUrl) ?>/index.php?page=item&amp;bibid=<?= $bibid ?>"
                    >
                        <div class="cover-slide-inner">
                            <img
                                src="<?= h($coverUrl) ?>"
                                alt="Copertina di <?= h($title) ?>"
                                class="cover-slide-img"
                                loading="lazy"
                                onerror="this.onerror=null;this.src='<?= h($placeholder) ?>';"
                                <?php if (!$hasLocal && $isbnOrig !== ''): ?>
                                    data-isbn="<?= h($isbnOrig) ?>"
                                    data-isbn13="<?= h($isbn13) ?>"
                                    data-title="<?= h($title) ?>"
                                    data-author="<?= h($author) ?>"
                                    data-placeholder="<?= h($placeholder) ?>"
                                <?php endif; ?>
                            >
                        </div>
                        <div class="cover-slide-meta">
                            <span class="cover-slide-title">
                                <?= h($title) ?>
                            </span>
                            <?php if (trim($author) !== ''): ?>
                                <span class="cover-slide-author">
                                    <?= h($author) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- CALLOUT DONAZIONI -->
    <section class="home-donazioni-callout" aria-label="Sostieni la Biblioteca">
        <h2 class="home-donazioni-title">Sostieni la Biblioteca della Resistenza</h2>
        <p class="home-donazioni-text">
            Puoi contribuire alla crescita del patrimonio librario e documentario
            con donazioni di libri, materiali d'archivio o un sostegno economico.
        </p>
        <a
            class="home-donazioni-btn"
            href="<?= h($baseUrl) ?>/index.php?page=donazioni"
        >
            Scopri come donare
        </a>
    </section>

    <section class="home-info">
        <h2>La biblioteca</h2>
        <p>
            La Biblioteca della Resistenza del Comitato Provinciale ANPI di Udine
            conserva libri, opuscoli, periodici e materiali
            dedicati alla storia della Resistenza, dell'antifascismo,
            dei movimenti di liberazione e della memoria del Novecento.
        </p>
        <p>
            Il catalogo digitale rende progressivamente consultabili le raccolte,
            con particolare attenzione alle esperienze del Friuli Venezia Giulia.
        </p>
        <p>
            Per informazioni su orari, servizi e modalità di consultazione
            visita la pagina
            <a href="<?= h($baseUrl) ?>/index.php?page=contatti">Contatti</a>
            o scrivi alla biblioteca.
        </p>
    </section>
</section>

<style>
/* FIX OPAC – recensioni esterne */
.external-reviews-grid {
  display: grid !important;
  grid-template-columns: repeat(3, 1fr) !important;
  gap: 1.25rem;
}

.external-review-card {
  display: block;
  border: 1px solid rgba(0,0,0,.08);
  background: #fff;
  text-decoration: none;
  color: inherit;
}

.external-review-image {
  aspect-ratio: 16 / 9;
  overflow: hidden;
  background: #eee;
}

.external-review-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

@media (max-width: 900px) {
  .external-reviews-grid {
    grid-template-columns: repeat(2, 1fr) !important;
  }
}

@media (max-width: 560px) {
  .external-reviews-grid {
    grid-template-columns: 1fr !important;
  }
}

.home-highlight--external-reviews {
  margin-bottom: 3rem;
}
</style>

<!-- RECENSIONI DA ANPIUDINE.ORG -->
<section class="home-highlight home-highlight--external-reviews">
    <h2 class="home-highlight-title">
        Recensioni da anpiudine.org
        <a
            href="https://www.anpiudine.org/category/approfondimenti/recensioni/"
            target="_blank"
            rel="noopener"
            class="home-highlight-more"
        >
            Vedi tutte →
        </a>
    </h2>

    <div id="external-reviews-grid" class="external-reviews-grid">
        <!-- popolato via JS -->
    </div>
</section>

<script>
/* =========================================================
   Recensioni da anpiudine.org (WordPress REST API)
   ========================================================= */
(function () {
  const container = document.getElementById('external-reviews-grid');
  if (!container) return;

  const endpoint =
    'https://www.anpiudine.org/wp-json/wp/v2/posts' +
    '?categories=31&per_page=6&_embed';

  fetch(endpoint)
    .then(r => r.ok ? r.json() : null)
    .then(posts => {
      if (!posts || !posts.length) return;
      posts.forEach(post => {
        const title   = post.title?.rendered || '';
        const excerpt = post.excerpt?.rendered || '';
        const link    = post.link || '#';
        let img = '';
        const media = post._embedded?.['wp:featuredmedia']?.[0];
        if (media?.source_url) img = media.source_url;

        const item = document.createElement('a');
        item.className = 'external-review-card';
        item.href = link;
        item.target = '_blank';
        item.rel = 'noopener';
        item.innerHTML = `
          <div class="external-review-image">
            ${img ? `<img src="${img}" alt="">` : ''}
          </div>
          <div class="external-review-body">
            <h3 class="external-review-title">${title}</h3>
            <div class="external-review-excerpt">${excerpt}</div>
          </div>
        `;
        container.appendChild(item);
      });
    })
    .catch(() => {});
})();
</script>

<?php if (!empty($gbApiKey)): ?>
<script>
// =============================================================================
// Caricamento copertine vetrina — stessa logica di item.php
// Gerarchia: cache locale (già nell'src) → Google Books API →
//            OpenLibrary → salvataggio sul server → placeholder
// =============================================================================
(function () {
    const apiKey = <?= json_encode($gbApiKey) ?>;
    const imgs   = document.querySelectorAll('.cover-slide-img[data-isbn]');
    if (!imgs.length) return;

    /**
     * Salva la cover trovata sul server (fire-and-forget).
     */
    function saveCoverOnServer(isbn13, imageUrl) {
        if (!isbn13) return;
        fetch('cover_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ isbn: isbn13, url: imageUrl })
        }).catch(() => {});
    }

    /**
     * Chiama Google Books API con una query.
     */
    function fetchGoogleBooks(q) {
        const url = 'https://www.googleapis.com/books/v1/volumes'
            + '?q=' + encodeURIComponent(q)
            + '&maxResults=1'
            + '&fields=items(volumeInfo/imageLinks)'
            + '&key=' + encodeURIComponent(apiKey);

        return fetch(url)
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                const links = data?.items?.[0]?.volumeInfo?.imageLinks;
                if (!links) return null;
                const src = links.thumbnail || links.smallThumbnail
                    || links.medium || links.large || links.extraLarge;
                return src ? src.replace(/^http:\/\//, 'https://') : null;
            })
            .catch(() => null);
    }

    /**
     * Prova OpenLibrary.
     */
    function tryOpenLibrary(isbn13) {
        if (!isbn13) return Promise.resolve(null);
        return new Promise((resolve) => {
            const testImg = new Image();
            const olUrl = 'https://covers.openlibrary.org/b/isbn/'
                + encodeURIComponent(isbn13) + '-M.jpg?default=false';
            testImg.onload  = () => resolve(olUrl);
            testImg.onerror = () => resolve(null);
            testImg.src = olUrl;
        });
    }

    /**
     * Cascata per una singola immagine.
     */
    async function loadCover(img) {
        const isbn       = img.getAttribute('data-isbn');
        const isbn13     = img.getAttribute('data-isbn13');
        const title      = img.getAttribute('data-title');
        const author     = img.getAttribute('data-author');
        const placeholder = img.getAttribute('data-placeholder');

        let src = null;

        // 2. Google Books — ISBN originale
        if (isbn) {
            src = await fetchGoogleBooks('isbn:' + isbn);
        }
        // 2b. Google Books — ISBN-13
        if (!src && isbn13 && isbn13 !== isbn) {
            src = await fetchGoogleBooks('isbn:' + isbn13);
        }
        // 2c. Google Books — titolo + autore
        if (!src && title) {
            let q = 'intitle:' + title;
            if (author) q += ' inauthor:' + author;
            src = await fetchGoogleBooks(q);
        }
        // 3. OpenLibrary
        if (!src) {
            src = await tryOpenLibrary(isbn13);
        }

        if (src) {
            img.onerror = null; // evita loop se la cover trovata desse errore
            img.src = src;
            // 4. Salva sul server
            saveCoverOnServer(isbn13, src);
        }
        // 5. Se src null, resta il placeholder già impostato dall'onerror
    }

    // Carica tutte le cover in parallelo
    imgs.forEach(img => loadCover(img));
})();
</script>
<?php endif; ?>