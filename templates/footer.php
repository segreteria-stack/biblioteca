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
        if (in_array($pageId, ['home', 'search', 'search_advanced'], true)) {
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
                                <a href="<?= htmlspecialchars($base, ENT_QUOTES) ?>/index.php?page=privacy">
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
</body>
</html>
