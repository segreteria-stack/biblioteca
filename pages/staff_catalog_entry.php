<?php
declare(strict_types=1);

/**
 * Hub inserimento record bibliografici — Area staff
 *
 * Punto di accesso unico che presenta i quattro percorsi di inserimento
 * e guida lo staff nella scelta del metodo più adatto.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$baseUrl = function_exists('base_url') ? base_url() : '';

if (empty($_SESSION['staff_user_id'])) {
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=staff_catalog_entry');
    exit;
}
?>
<style>
.entry-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.25rem;
    margin-top: 1.5rem;
}
.entry-card {
    display: flex;
    flex-direction: column;
    padding: 1.4rem 1.5rem 1.3rem;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #fff;
    text-decoration: none;
    color: inherit;
    transition: border-color 0.12s ease, box-shadow 0.12s ease;
}
.entry-card:hover,
.entry-card:focus-visible {
    border-color: var(--color-primary, #b91c1c);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.entry-card-icon {
    font-size: 1.6rem;
    margin-bottom: 0.65rem;
    line-height: 1;
}
.entry-card-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #111827;
    margin-bottom: 0.35rem;
}
.entry-card-desc {
    font-size: 0.875rem;
    color: #4b5563;
    line-height: 1.5;
    flex: 1;
}
.entry-card-when {
    margin-top: 0.75rem;
    padding-top: 0.65rem;
    border-top: 1px solid #f3f4f6;
    font-size: 0.8rem;
    color: #6b7280;
}
.entry-card-when strong {
    color: #374151;
}
.entry-card-badge {
    display: inline-block;
    margin-bottom: 0.5rem;
    padding: 0.15rem 0.55rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    background: #f3f4f6;
    color: #6b7280;
}
.entry-card-badge--recommended {
    background: #dcfce7;
    color: #15803d;
}
</style>

<section class="page-section">
    <nav style="font-size:0.88rem;margin-bottom:1.25rem;">
        <a href="<?= h($baseUrl) ?>/index.php?page=staff">Dashboard</a> › Inserisci record
    </nav>

    <h1>Inserisci un nuovo record in catalogo</h1>
    <p style="color:#4b5563;max-width:640px;">
        Scegli il metodo più adatto alla situazione. Tutti i percorsi creano un record in <code>biblio</code>
        con la relativa copia in <code>biblio_copy</code>.
    </p>

    <div class="entry-grid">

        <!-- 1. Manuale con lookup ISBN -->
        <a class="entry-card" href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_new">
            <div class="entry-card-badge entry-card-badge--recommended">Consigliato</div>
            <div class="entry-card-icon">✏️</div>
            <div class="entry-card-title">Inserimento manuale</div>
            <div class="entry-card-desc">
                Form guidato con tutti i campi: titolo, autore, editore, soggetti, ISBN, riassunto.
                Pulsante di lookup automatico da SBN / Google Books per precompilare i dati a partire dall'ISBN.
            </div>
            <div class="entry-card-when">
                <strong>Quando usarlo:</strong> per singoli titoli, quando hai il libro in mano
                o vuoi controllare ogni campo prima di salvare.
            </div>
        </a>

        <!-- 2. Import da SBN -->
        <a class="entry-card" href="<?= h($baseUrl) ?>/index.php?page=staff_sbn_import">
            <div class="entry-card-badge">SBN</div>
            <div class="entry-card-icon">🔍</div>
            <div class="entry-card-title">Importazione da SBN</div>
            <div class="entry-card-desc">
                Ricerca nel Catalogo Unico SBN per ISBN, titolo o autore. Mostra i risultati in tabella;
                apre un form editabile con tutti i campi MARC (Dewey, lingua, soggetti, abstract, ecc.)
                prima di importare.
            </div>
            <div class="entry-card-when">
                <strong>Quando usarlo:</strong> quando il titolo è presente in SBN e vuoi importare
                subito un record completo e normalizzato.
            </div>
        </a>

        <!-- 3. Import da file MARC21/EndNote -->
        <a class="entry-card" href="<?= h($baseUrl) ?>/index.php?page=staff_import_file">
            <div class="entry-card-badge">File</div>
            <div class="entry-card-icon">📂</div>
            <div class="entry-card-title">Import da file <span style="font-weight:400;font-size:0.85em;">(MARC21 / EndNote)</span></div>
            <div class="entry-card-desc">
                Wizard in tre step: carica il file, verifica l'anteprima dei dati estratti, conferma
                l'inserimento. Supporta MARC21 ISO2709 (<code>.mrc</code>, <code>.iso</code>)
                e EndNote testo (<code>.txt</code>, <code>.enw</code>).
            </div>
            <div class="entry-card-when">
                <strong>Quando usarlo:</strong> per importare record esportati da altri sistemi
                gestionali o scaricati da banche dati bibliografiche.
            </div>
        </a>

        <!-- 4. Import MARCXML -->
        <a class="entry-card" href="<?= h($baseUrl) ?>/index.php?page=staff_import_marc">
            <div class="entry-card-badge">XML</div>
            <div class="entry-card-icon">🗂️</div>
            <div class="entry-card-title">Import MARCXML <span style="font-weight:400;font-size:0.85em;">(.xml)</span></div>
            <div class="entry-card-desc">
                Import diretto da file MARCXML (<code>&lt;record&gt;</code> o <code>&lt;collection&gt;</code>).
                Importazione immediata senza anteprima. Mappa titolo, autore, soggetti e ISBN
                dai campi standard MARC.
            </div>
            <div class="entry-card-when">
                <strong>Quando usarlo:</strong> per file XML già verificati, tipicamente esportati
                da OPAC o sistemi che producono MARCXML puro.
            </div>
        </a>

    </div>

    <hr style="margin:2.5rem 0 1.5rem;border:none;border-top:1px solid #e5e7eb;">

    <h2 style="font-size:1rem;color:#374151;margin-bottom:0.75rem;">Operazioni correlate</h2>
    <div style="display:flex;flex-wrap:wrap;gap:0.6rem;">
        <a class="btn-secondary" href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_edit">Modifica record esistente</a>
        <a class="btn-secondary" href="<?= h($baseUrl) ?>/index.php?page=staff_sbn">Arricchimento metadati SBN (per ISBN)</a>
        <a class="btn-secondary" href="<?= h($baseUrl) ?>/index.php?page=staff_sbn_enrich_title">Arricchimento metadati SBN (per titolo)</a>
        <a class="btn-secondary" href="<?= h($baseUrl) ?>/index.php?page=staff">Dashboard staff</a>
    </div>
</section>
