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
require_once __DIR__ . '/../lib/marc_helpers.php';

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
            WHERE b.opac_flg = \'Y\'
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
    $totalTitoli = (int)$pdo->query("SELECT COUNT(*) FROM biblio WHERE opac_flg = 'Y'")->fetchColumn();
} catch (\PDOException $e) {}

// Top soggetti per la sezione "Esplora per tema"
// Fonti: topic1..5 + biblio_field 650/651 $a — normalizzati e deduplicati
$topTopics = [];
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
    $seenBibidPerLabel = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $bibid  = (int)$row['bibid'];
        $labels = marc_split_subject_string((string)($row['topic'] ?? ''));
        foreach ($labels as $label) {
            $key = mb_strtolower($label, 'UTF-8');
            if (!isset($seenBibidPerLabel[$key])) {
                $seenBibidPerLabel[$key] = ['label' => $label, 'cnt' => []];
            }
            $seenBibidPerLabel[$key]['cnt'][$bibid] = true;
        }
    }
    $freq = [];
    foreach ($seenBibidPerLabel as $key => $entry) {
        $freq[] = ['topic' => $entry['label'], 'cnt' => count($entry['cnt'])];
    }
    usort($freq, fn($a, $b) => $b['cnt'] <=> $a['cnt']);
    $topTopics = array_slice($freq, 0, 14);
} catch (\PDOException $e) {}
?>

<!-- HERO A TUTTA LARGHEZZA CON IMMAGINE STORICA + RICERCA -->
<section class="home-hero-banner">
    <div class="container home-hero-banner-inner">
        <div class="home-hero-banner-text">
            <div class="home-hero-badge">
                <span class="home-hero-badge-dot" aria-hidden="true"></span>
                Archivio vivo della memoria democratica
            </div>
            <?php if ($totalTitoli > 0): ?>
            <div class="home-hero-stat">
                <strong><?= number_format($totalTitoli, 0, ',', '.') ?></strong>
                <span>titoli in catalogo</span>
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
                        autocomplete="off"
                        data-autocomplete="1"
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
                        href="<?= h($baseUrl) ?>/index.php?page=search&amp;subject=<?= urlencode((string)$t['topic']) ?>"
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

/* ── Bibliotecario virtuale ── */
.agente-section {
  max-width: 760px;
  margin: 0 auto 3.5rem;
  padding: 0 1rem;
}
.agente-header {
  display: flex;
  align-items: center;
  gap: .75rem;
  margin-bottom: 1.25rem;
}
.agente-avatar {
  width: 42px; height: 42px;
  background: #c0001a;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.3rem;
  flex-shrink: 0;
}
.agente-title { font-size: 1.2rem; font-weight: 700; color: #1a1a1a; margin: 0; }
.agente-subtitle { font-size: .82rem; color: #64748b; margin: 0; }
.agente-messages {
  background: #f8f9fa;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 1.25rem;
  min-height: 120px;
  max-height: 380px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: .75rem;
  margin-bottom: .75rem;
}
.agente-bubble {
  padding: .65rem .9rem;
  border-radius: 12px;
  font-size: .92rem;
  line-height: 1.55;
  max-width: 88%;
  white-space: pre-wrap;
  word-break: break-word;
}
.agente-bubble--bot {
  background: #fff;
  border: 1px solid #e2e8f0;
  align-self: flex-start;
  color: #1a1a1a;
}
.agente-bubble--user {
  background: #c0001a;
  color: #fff;
  align-self: flex-end;
}
.agente-bubble--error {
  background: #fff3f3;
  border: 1px solid #fecaca;
  color: #b91c1c;
  align-self: flex-start;
  font-size: .85rem;
}
.agente-typing {
  display: flex; gap: 4px; align-items: center;
  padding: .65rem .9rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  align-self: flex-start;
}
.agente-typing span {
  width: 7px; height: 7px;
  background: #94a3b8;
  border-radius: 50%;
  animation: agente-bounce .9s infinite;
}
.agente-typing span:nth-child(2) { animation-delay: .15s; }
.agente-typing span:nth-child(3) { animation-delay: .3s; }
@keyframes agente-bounce {
  0%,80%,100% { transform: translateY(0); }
  40%         { transform: translateY(-6px); }
}
.agente-form {
  display: flex; gap: .5rem;
}
.agente-input {
  flex: 1;
  padding: .65rem .9rem;
  border: 1px solid #d1d5db;
  border-radius: 10px;
  font-size: .92rem;
  outline: none;
  transition: border-color .15s;
}
.agente-input:focus { border-color: #c0001a; }
.agente-send {
  padding: .65rem 1.1rem;
  background: #c0001a;
  color: #fff;
  border: none;
  border-radius: 10px;
  font-size: .92rem;
  font-weight: 600;
  cursor: pointer;
  transition: background .15s;
  white-space: nowrap;
}
.agente-send:hover { background: #a0001a; }
.agente-send:disabled { background: #ccc; cursor: default; }
.agente-bubble a { color: #c0001a; text-decoration: underline; }
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
        const rawTitle   = post.title?.rendered || '';
        const rawExcerpt = post.excerpt?.rendered || '';
        const link       = post.link || '#';
        const media      = post._embedded?.['wp:featuredmedia']?.[0];
        const imgSrc     = media?.source_url || '';

        // Decode HTML entities from WordPress without executing scripts
        const decodeHtml = (html) => {
          const tmp = document.createElement('div');
          tmp.innerHTML = html;
          return tmp.textContent || '';
        };

        const item = document.createElement('a');
        item.className = 'external-review-card';
        item.href = link;
        item.target = '_blank';
        item.rel = 'noopener noreferrer';

        const imgDiv = document.createElement('div');
        imgDiv.className = 'external-review-image';
        if (imgSrc) {
          const img = document.createElement('img');
          img.src = imgSrc;
          img.alt = '';
          imgDiv.appendChild(img);
        }

        const bodyDiv  = document.createElement('div');
        bodyDiv.className = 'external-review-body';

        const titleEl  = document.createElement('h3');
        titleEl.className = 'external-review-title';
        titleEl.textContent = decodeHtml(rawTitle);

        const excerptEl = document.createElement('div');
        excerptEl.className = 'external-review-excerpt';
        excerptEl.textContent = decodeHtml(rawExcerpt);

        bodyDiv.appendChild(titleEl);
        bodyDiv.appendChild(excerptEl);
        item.appendChild(imgDiv);
        item.appendChild(bodyDiv);
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

<!-- ══════════════════════════════════════════════════════
     BIBLIOTECARIO VIRTUALE
     ══════════════════════════════════════════════════════ -->
<section class="agente-section" aria-label="Bibliotecario virtuale">
  <div class="agente-header">
    <div class="agente-avatar" aria-hidden="true">📚</div>
    <div>
      <p class="agente-title">Biblio — Bibliotecario virtuale</p>
      <p class="agente-subtitle">Chiedi un libro, un argomento o come iscriverti</p>
    </div>
  </div>

  <div class="agente-messages" id="agente-messages" role="log" aria-live="polite">
    <div class="agente-bubble agente-bubble--bot">
      Ciao! Sono Biblio, il bibliotecario virtuale della Biblioteca della Resistenza ANPI di Udine. 📚<br>
      Posso aiutarti a trovare libri nel catalogo, rispondere a domande sulla Resistenza e sulla storia del Friuli, o spiegarti come iscriverti e prendere libri in prestito.<br><br>
      Come posso aiutarti?
    </div>
  </div>

  <form class="agente-form" id="agente-form" autocomplete="off">
    <input
      class="agente-input"
      id="agente-input"
      type="text"
      placeholder="Es. Hai libri sulla Resistenza in Friuli?"
      maxlength="500"
      aria-label="Messaggio per il bibliotecario"
    >
    <button class="agente-send" id="agente-send" type="submit">Invia</button>
  </form>
</section>

<script>
(function () {
  const form     = document.getElementById('agente-form');
  const input    = document.getElementById('agente-input');
  const messages = document.getElementById('agente-messages');
  const sendBtn  = document.getElementById('agente-send');
  const ajaxUrl  = '<?= h($baseUrl) ?>/ajax_agente.php';

  // Storico messaggi (ruolo + testo) per contesto multi-turno
  const history = [];

  function escHtml(s) {
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;');
  }

  // Converte **grassetto** e link in HTML
  function renderMarkdown(text) {
    return escHtml(text)
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/g,
               '<a href="$2" target="_blank" rel="noopener">$1</a>');
  }

  function addBubble(text, type) {
    const div = document.createElement('div');
    div.className = 'agente-bubble agente-bubble--' + type;
    if (type === 'error') {
      div.textContent = text;
    } else {
      div.innerHTML = renderMarkdown(text);
    }
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
    return div;
  }

  function showTyping() {
    const div = document.createElement('div');
    div.className = 'agente-typing';
    div.id = 'agente-typing';
    div.innerHTML = '<span></span><span></span><span></span>';
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
  }

  function removeTyping() {
    const t = document.getElementById('agente-typing');
    if (t) t.remove();
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const msg = input.value.trim();
    if (!msg) return;

    input.value   = '';
    sendBtn.disabled = true;
    addBubble(msg, 'user');
    history.push({ role: 'user', text: msg });
    showTyping();

    try {
      const res = await fetch(ajaxUrl, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ message: msg, history: history.slice(0, -1) }),
      });
      const data = await res.json();
      removeTyping();

      if (data.ok) {
        addBubble(data.reply, 'bot');
        history.push({ role: 'model', text: data.reply });
        // Mantieni lo storico leggero
        if (history.length > 20) history.splice(0, 2);
      } else {
        addBubble(data.error || 'Errore sconosciuto.', 'error');
        history.pop();
      }
    } catch (err) {
      removeTyping();
      addBubble('Errore di connessione. Riprova tra poco.', 'error');
      history.pop();
    } finally {
      sendBtn.disabled = false;
      input.focus();
    }
  });
})();
</script>