<?php
declare(strict_types=1);

$base   = function_exists('base_url') ? base_url() : '';
$year   = date('Y');
$pageId = $page ?? '';

// URL Google Maps per "Come arrivare"
$mapsAddress = 'Via Brigata Re 29, 33100 Udine (UD), Italia';
$mapsUrl     = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($mapsAddress);

// Patron: link coerente (se loggato → area; altrimenti → login)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$patronHref = rtrim((string)$base, '/') . '/index.php?page=patron_login';
if (!empty($_SESSION['patron']) && is_array($_SESSION['patron'])) {
    $patronHref = rtrim((string)$base, '/') . '/index.php?page=patron_area';
}
?>

        <?php
        // TAG CLOUD: ultimo elemento del main, solo su pagine pubbliche OPAC
        // Esclusa dalla scheda singola (page=item) per non disturbare i soggetti specifici.
        // 'home' esclusa: ha già la propria sezione "Esplora per tema" con chip stilizzate
        if (in_array($pageId, ['search', 'search_advanced'], true)) {
            include __DIR__ . '/tag_cloud.php';
        }
        ?>
    </main>

    <footer class="site-footer">
        <div class="container">
            <div class="site-footer-inner">

                <div class="footer-cols">

                    <!-- Colonna 1: Contatti e posizione -->
                    <section class="footer-col footer-col--contacts" aria-label="Contatti">
                        <h2 class="footer-title">Contatti</h2>
                        <p class="footer-address">
                            Biblioteca della Resistenza<br>
                            c/o Comitato Provinciale ANPI di Udine<br>
                            Via Brigata Re 29, 33100 Udine (UD)
                        </p>
                        <p class="footer-contact-links">
                            tel. <a href="tel:+390432504813">0432 504813</a><br>
                            <a href="mailto:biblioteca@anpiudine.org">biblioteca@anpiudine.org</a><br>
                            <a
                                href="<?= htmlspecialchars($mapsUrl, ENT_QUOTES) ?>"
                                target="_blank"
                                rel="noopener"
                            >
                                Come arrivare
                            </a>
                        </p>

                        <div class="footer-opening-hours">
                            <strong>Orari di apertura</strong><br>
                            dal lunedì al venerdì 9–13,<br>
                            martedì anche 15–18,<br>
                            preferibilmente su appuntamento.
                        </div>

                        <p class="footer-more">
                            <a href="<?= htmlspecialchars($base, ENT_QUOTES) ?>/index.php?page=contatti">
                                Chi siamo
                            </a>
                        </p>
                    </section>

                    <!-- Colonna 2: Strumenti e risorse -->
                    <section class="footer-col footer-col--tools" aria-label="Strumenti e risorse">
                        <h2 class="footer-title">Strumenti e risorse</h2>
                        <ul class="footer-links">
                            <li>
                                <a href="<?= htmlspecialchars($base, ENT_QUOTES) ?>/index.php?page=search">
                                    Catalogo / Ricerca semplice
                                </a>
                            </li>
                            <li>
                                <a href="<?= htmlspecialchars($base, ENT_QUOTES) ?>/index.php?page=search_advanced">
                                    Ricerca avanzata
                                </a>
                            </li>
                            <li>
                                <a href="<?= htmlspecialchars($base, ENT_QUOTES) ?>/index.php?page=donazioni">
                                    Donazioni
                                </a>
                            </li>
                            <li>
                                <a href="<?= htmlspecialchars($patronHref, ENT_QUOTES) ?>">
                                    Area Patron
                                </a>
                            </li>
                            <li>
                                <a href="<?= htmlspecialchars($base, ENT_QUOTES) ?>/index.php?page=login">
                                    Accesso staff
                                </a>
                            </li>
                        </ul>
                    </section>

                    <!-- Colonna 3: Informazioni legali e copyright -->
                    <section class="footer-col footer-col--legal" aria-label="Informazioni legali">
                        <h2 class="footer-title">Informazioni legali</h2>
                        <ul class="footer-links">
                            <li>
                                <a href="<?= htmlspecialchars($base, ENT_QUOTES) ?>/index.php?page=regolamento">
                                    Regolamento della Biblioteca
                                </a>
                            </li>
                            <li>
                                <a href="https://www.anpiudine.org/privacy-policy/" target="_blank" rel="noopener">
                                    Privacy Policy
                                </a>
                            </li>
                            <li>
                                <a href="<?= rtrim(htmlspecialchars($base, ENT_QUOTES), '/') ?>/index.php?page=note_legali">
                                    Note legali
                                </a>
                            </li>
                            <li>
                                <a href="<?= htmlspecialchars($base, ENT_QUOTES) ?>/index.php?page=mappa_sito">
                                    Mappa del sito
                                </a>
                            </li>
                        </ul>

                        <p class="footer-copy">
                            &copy; <?= (int) $year ?> Biblioteca della Resistenza – ANPI Udine.
                        </p>
                    </section>

                    <!-- Colonna 4: Supporto e loghi -->
                    <section class="footer-col footer-col--support" aria-label="Supporto e patrocinio">
                        <h2 class="footer-title">Supporto</h2>
                        <p>Con il sostegno di:</p>
                        <div class="footer-logos">
                            <?php
                            // Prima il logo lungo (denominazione), poi quello più quadrato
                            $logoLong   = $base . '/assets/FVG_denominativo_RGB.png';
                            $logoSquare = $base . '/assets/logo FVG 102.jpg';
                            ?>
                            <img
                                src="<?= htmlspecialchars($logoLong, ENT_QUOTES) ?>"
                                alt="Regione Autonoma Friuli Venezia Giulia - denominazione"
                                loading="lazy"
                            >
                            <img
                                src="<?= htmlspecialchars($logoSquare, ENT_QUOTES) ?>"
                                alt="Regione Autonoma Friuli Venezia Giulia"
                                loading="lazy"
                            >
                        </div>
                    </section>

                </div><!-- /.footer-cols -->

            </div><!-- /.site-footer-inner -->
        </div><!-- /.container -->
    </footer>

<script>
/* Autocomplete per tutti gli input con data-autocomplete="1" */
(function () {
  'use strict';

  const BASE = <?= json_encode(rtrim((string)$base, '/')) ?>;

  function escHtml(s) {
    return s.replace(/[&<>"']/g, function(c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function initAc(input) {
    // Wrap the input in .ac-wrap (only if not already done)
    let wrap = input.parentElement;
    if (!wrap.classList.contains('ac-wrap')) {
      wrap = document.createElement('div');
      wrap.className = 'ac-wrap';
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);
    }

    let dropdown = null;
    let timer    = null;
    let active   = -1;
    let current  = [];

    function items() { return dropdown ? Array.from(dropdown.querySelectorAll('.ac-item')) : []; }

    function setActive(i) {
      items().forEach(function(el, idx) { el.classList.toggle('ac-active', idx === i); });
      active = i;
    }

    function close() {
      if (dropdown) { dropdown.remove(); dropdown = null; active = -1; current = []; }
    }

    function render(list) {
      close();
      if (!list || list.length === 0) return;
      const ICONS  = { title: '📖', author: '✍️', topic: '🏷️' };
      const LABELS = { title: 'Titolo', author: 'Autore', topic: 'Soggetto' };
      dropdown = document.createElement('ul');
      dropdown.className = 'ac-dropdown';
      dropdown.setAttribute('role', 'listbox');
      list.forEach(function(s) {
        const li = document.createElement('li');
        li.className = 'ac-item';
        li.setAttribute('role', 'option');
        li.innerHTML =
          '<span class="ac-item-icon">' + (ICONS[s.type] || '📄') + '</span>' +
          '<span class="ac-item-body">' +
            '<span class="ac-item-label">' + escHtml(s.label) + '</span>' +
            (s.sub ? '<span class="ac-item-sub">' + escHtml(s.sub) + '</span>' : '') +
          '</span>' +
          '<span class="ac-item-type">' + escHtml(LABELS[s.type] || '') + '</span>';
        li.addEventListener('mousedown', function(e) {
          e.preventDefault();
          pick(s);
        });
        dropdown.appendChild(li);
      });
      current = list;
      wrap.appendChild(dropdown);
    }

    function pick(s) {
      window.location.href = s.url;
    }

    function doFetch(q) {
      fetch(BASE + '/ajax_autocomplete.php?q=' + encodeURIComponent(q), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(r) { return r.json(); })
      .then(function(d) { if (d.ok) render(d.suggestions); })
      .catch(function() {});
    }

    input.addEventListener('input', function() {
      clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < 2) { close(); return; }
      timer = setTimeout(function() { doFetch(q); }, 280);
    });

    input.addEventListener('keydown', function(e) {
      var list = items();
      if (!dropdown || list.length === 0) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault(); setActive(Math.min(active + 1, list.length - 1));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault(); setActive(Math.max(active - 1, 0));
      } else if (e.key === 'Enter' && active >= 0) {
        e.preventDefault(); pick(current[active]);
      } else if (e.key === 'Escape') {
        close();
      }
    });

    document.addEventListener('click', function(e) {
      if (!wrap.contains(e.target)) close();
    });
  }

  document.querySelectorAll('input[data-autocomplete]').forEach(initAc);
  window._initAcInput = initAc;
}());
</script>
</body>
</html>
