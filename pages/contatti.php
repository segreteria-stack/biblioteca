<?php
declare(strict_types=1);

/**
 * Pagina Contatti - OPAC Biblioteca della Resistenza
 * PHP 8.x
 *
 * Nota: lo stile è volutamente "scoped" per non impattare altre pagine.
 * Quando approvato, spostiamo le regole in style.css.
 */

// Helper escaping (se non esiste già nel progetto)
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// --- Dati contatto (centralizzati qui; in futuro spostabili in config/backend) ---
$orgName   = 'Biblioteca della Resistenza';
$parentOrg = 'Comitato Provinciale ANPI di Udine';

$addressLine = 'Via Brigata Re, 29';
$cityLine    = '33100 Udine (UD)';
$fullAddress = $addressLine . ', ' . $cityLine;

$email = 'biblioteca@anpiudine.org';
$phoneDisplay = '0432 504813';
$phoneTel     = '+390432504813';

// Link utili
$mailtoHref   = 'mailto:' . $email;
$mapsQuery    = rawurlencode($fullAddress);
$mapsPlaceUrl = 'https://www.google.com/maps/search/?api=1&query=' . $mapsQuery;
$mapsDirUrl   = 'https://www.google.com/maps/dir/?api=1&destination=' . $mapsQuery;

// Orari (testo breve e leggibile)
$hoursHtml = <<<HTML
<strong>Orari di apertura</strong><br>
Lunedì–Venerdì: 9–13<br>
Martedì anche: 15–18<br>
<em>Preferibilmente su appuntamento.</em>
HTML;

// (Facoltativo) embed mappa: usa l’URL “Maps Embed” se ne hai uno.
// Per evitare chiavi/API, usiamo un embed semplice basato su query.
$mapEmbedSrc = 'https://www.google.com/maps?q=' . rawurlencode($fullAddress) . '&output=embed';

?>
<style>
/* Scoped: Contatti */
.contact-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:1.25rem;align-items:start}
@media (max-width: 900px){.contact-grid{grid-template-columns:1fr}}
.contact-card{background:var(--color-bg-card);border:1px solid var(--color-border-subtle);border-radius:10px;padding:1.25rem;box-shadow:0 2px 10px rgba(0,0,0,.04)}
.contact-title{font-size:1.15rem;margin:0 0 .75rem}
.contact-kv{margin:0;padding:0;list-style:none;display:grid;gap:.55rem}
.contact-kv li{display:flex;gap:.55rem;align-items:flex-start}
.contact-k{min-width:5.5rem;font-weight:700;color:var(--color-text-muted, #555)}
.contact-v{flex:1}
.contact-actions{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1rem}
.contact-btn{display:inline-flex;align-items:center;gap:.45rem;padding:.55rem .85rem;border-radius:999px;border:1px solid var(--color-border-subtle);text-decoration:none;font-weight:700}
.contact-btn-primary{background:var(--btn-primary-bg, #e11e28);color:var(--btn-primary-text, #fff);border-color:transparent}
.contact-btn:hover{filter:brightness(.98)}
.contact-note{margin-top:1rem;padding:.85rem;border-radius:10px;background:#fff7f7;border:1px solid rgba(225,30,40,.25)}
.contact-note strong{color:var(--color-primary, #e11e28)}
.contact-map{border-radius:10px;overflow:hidden;border:1px solid var(--color-border-subtle);background:#fff}
.contact-map iframe{display:block;width:100%;height:320px;border:0}
.contact-subgrid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-top:1.25rem}
@media (max-width: 900px){.contact-subgrid{grid-template-columns:1fr}}
.contact-list{margin:.5rem 0 0;padding-left:1.1rem}
.contact-list li{margin:.25rem 0}
.small-muted{color:var(--color-text-muted, #666);font-size:.95rem}
</style>

<section class="container site-main container">
  <div class="page-section">
    <h1>Contatti</h1>
    <p class="small-muted">
      Informazioni per raggiungere e contattare la <?= h($orgName) ?> (<?= h($parentOrg) ?>).
    </p>

    <div class="contact-grid">
      <!-- Card contatti -->
      <div class="contact-card" aria-label="Dati di contatto">
        <h2 class="contact-title"><?= h($orgName) ?></h2>

        <ul class="contact-kv">
          <li>
            <div class="contact-k">Indirizzo</div>
            <div class="contact-v">
              <?= h($addressLine) ?><br><?= h($cityLine) ?><br>
              <a href="<?= h($mapsPlaceUrl) ?>" target="_blank" rel="noopener">Apri su Google Maps</a>
            </div>
          </li>
          <li>
            <div class="contact-k">Telefono</div>
            <div class="contact-v">
              <a href="tel:<?= h($phoneTel) ?>"><?= h($phoneDisplay) ?></a>
            </div>
          </li>
          <li>
            <div class="contact-k">Email</div>
            <div class="contact-v">
              <a href="<?= h($mailtoHref) ?>"><?= h($email) ?></a>
            </div>
          </li>
        </ul>

        <div class="contact-actions" role="group" aria-label="Azioni rapide">
          <a class="contact-btn contact-btn-primary" href="<?= h($mailtoHref) ?>">Scrivi una email</a>
          <a class="contact-btn" href="tel:<?= h($phoneTel) ?>">Chiama</a>
          <a class="contact-btn" href="<?= h($mapsDirUrl) ?>" target="_blank" rel="noopener">Indicazioni</a>
        </div>

        <div class="contact-note">
          <strong>Nota</strong><br>
          Per consultazioni e ricerche complesse, consigliamo di contattarci prima per concordare l’appuntamento.
        </div>
      </div>

      <!-- Mappa -->
      <div class="contact-card" aria-label="Mappa e posizione">
        <h2 class="contact-title">Dove siamo</h2>
        <div class="contact-map">
          <iframe
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
            src="<?= h($mapEmbedSrc) ?>"
            title="Mappa: <?= h($orgName) ?> - <?= h($fullAddress) ?>"
          ></iframe>
        </div>
        <p class="small-muted" style="margin:.75rem 0 0">
          Se la mappa non si visualizza, usa il link “Apri su Google Maps” nella sezione indirizzo.
        </p>
      </div>
    </div>

    <!-- Seconda riga: orari + come arrivare -->
    <div class="contact-subgrid">
      <div class="contact-card" aria-label="Orari di apertura">
        <h2 class="contact-title">Orari</h2>
        <p style="margin:0"><?= $hoursHtml ?></p>
      </div>

      <div class="contact-card" aria-label="Come arrivare">
        <h2 class="contact-title">Come arrivare</h2>
        <ul class="contact-list">
          <li>Usa il pulsante <strong>Indicazioni</strong> per avviare la navigazione.</li>
          <li>Se arrivi in auto, verifica la disponibilità di parcheggi in zona.</li>
          <li>Per gruppi/scuole: contatto consigliato con anticipo.</li>
        </ul>
        <p class="small-muted" style="margin:.75rem 0 0">
          Per aggiornamenti su aperture straordinarie o iniziative, consulta anche le pagine informative del sito ANPI.
        </p>
      </div>
    </div>

    <!-- Dati strutturati (SEO) -->
    <script type="application/ld+json">
    <?= json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'Library',
      'name' => $orgName,
      'parentOrganization' => ['@type' => 'Organization', 'name' => $parentOrg],
      'address' => [
        '@type' => 'PostalAddress',
        'streetAddress' => $addressLine,
        'addressLocality' => 'Udine',
        'postalCode' => '33100',
        'addressRegion' => 'UD',
        'addressCountry' => 'IT'
      ],
      'telephone' => $phoneTel,
      'email' => $email,
      'url' => (isset($_SERVER['HTTP_HOST']) ? ('https://' . $_SERVER['HTTP_HOST'] . ($_SERVER['REQUEST_URI'] ?? '')) : null)
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
    </script>

  </div>
</section>
