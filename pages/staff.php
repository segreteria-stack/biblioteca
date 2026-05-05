<?php
/**
 * Area Staff – Dashboard principale
 *
 * Struttura:
 *  1) Barra metriche live (titoli, prestiti aperti, in scadenza oggi, utenti attivi)
 *  2) Azioni rapide (4 card compatte)
 *  3) Tre sezioni: Catalogo · Utenti e prestiti · Manutenzione
 *
 * PHP 8.3 · PDO · MariaDB
 *
 * @package BibliotecaResistenza\Pages
 */

declare(strict_types=1);

/** @var \PDO $pdo */
$pdo = DB::conn();

// -----------------------------------------------------------------------------
// Protezione: accesso solo per staff autenticato
// -----------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    $baseUrl  = function_exists('base_url') ? base_url() : '';
    $redirect = 'staff';
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=' . urlencode($redirect));
    exit;
}

$baseUrl   = function_exists('base_url') ? base_url() : '';
$staffName = $_SESSION['staff_fullname'] ?? ($_SESSION['staff_username'] ?? 'Operatore');

// -----------------------------------------------------------------------------
// Metriche live
// -----------------------------------------------------------------------------
$metrics = [
    'titoli'         => 0,
    'prestiti'       => 0,
    'in_scadenza'    => 0,
    'utenti_attivi'  => 0,
];

try {
    $metrics['titoli'] = (int) $pdo
        ->query('SELECT COUNT(*) FROM biblio')
        ->fetchColumn();
} catch (\PDOException $e) { /* non bloccante */ }

// CORRETTO: conta solo prestiti effettivi (out/ln), non tutti gli stati non-disponibili
try {
    $metrics['prestiti'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM biblio_copy WHERE status_cd IN ('out', 'ln')")
        ->fetchColumn();
} catch (\PDOException $e) { /* non bloccante */ }

// CORRETTO: solo prestiti in scadenza (out/ln), non copie scartate/prenotate/etc.
try {
    $metrics['in_scadenza'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM biblio_copy WHERE due_back_dt = CURDATE() AND status_cd IN ('out', 'ln')")
        ->fetchColumn();
} catch (\PDOException $e) { /* non bloccante */ }

try {
    $metrics['utenti_attivi'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM member WHERE is_active = 'Y'")
        ->fetchColumn();
} catch (\PDOException $e) { /* non bloccante */ }

// Helper: formatta numero con separatore migliaia
function fmt_metric(int $n): string
{
    return number_format($n, 0, ',', '.');
}

?>
<section class="page-section page-staff page-staff-dashboard">

    <!-- ------------------------------------------------------------------ -->
    <!-- Intestazione                                                          -->
    <!-- ------------------------------------------------------------------ -->
    <header class="staff-header">
        <div class="staff-header-top">
            <div class="staff-header-main">
                <h1>Area staff</h1>
                <p class="staff-header-subtitle">
                    Pannello di amministrazione della Biblioteca della Resistenza.
                </p>
            </div>

            <div class="staff-current-user">
                <span class="staff-current-user-label">Collegato come</span>
                <strong class="staff-current-user-name"><?= h($staffName) ?></strong>
                <a class="staff-logout-link" href="<?= h($baseUrl) ?>/index.php?page=staff_logout">
                    Esci
                </a>
            </div>
        </div>
    </header>

    <!-- ------------------------------------------------------------------ -->
    <!-- Metriche live                                                         -->
    <!-- ------------------------------------------------------------------ -->
    <div class="staff-metrics">

        <div class="staff-metric">
            <span class="staff-metric-num"><?= fmt_metric($metrics['titoli']) ?></span>
            <span class="staff-metric-label">Titoli in catalogo</span>
        </div>

        <div class="staff-metric <?= $metrics['prestiti'] > 0 ? 'staff-metric--warn' : '' ?>">
            <span class="staff-metric-num"><?= fmt_metric($metrics['prestiti']) ?></span>
            <span class="staff-metric-label">Prestiti aperti</span>
        </div>

        <div class="staff-metric <?= $metrics['in_scadenza'] > 0 ? 'staff-metric--alert' : '' ?>">
            <span class="staff-metric-num"><?= fmt_metric($metrics['in_scadenza']) ?></span>
            <span class="staff-metric-label">In scadenza oggi</span>
        </div>

        <div class="staff-metric">
            <span class="staff-metric-num"><?= fmt_metric($metrics['utenti_attivi']) ?></span>
            <span class="staff-metric-label">Utenti attivi</span>
        </div>

    </div>

    <!-- ------------------------------------------------------------------ -->
    <!-- Azioni rapide                                                         -->
    <!-- ------------------------------------------------------------------ -->
    <div class="staff-quick-actions">

        <a class="staff-quick-card" href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_entry">
            <span class="staff-quick-icon staff-quick-icon--purple">+</span>
            <span class="staff-quick-title">Nuovo record</span>
            <span class="staff-quick-text">Inserisci un titolo in catalogo</span>
        </a>

        <a class="staff-quick-card" href="<?= h($baseUrl) ?>/index.php?page=staff_search">
            <span class="staff-quick-icon staff-quick-icon--teal">🔍</span>
            <span class="staff-quick-title">Cerca nel catalogo</span>
            <span class="staff-quick-text">Ricerca avanzata con collocazione e collezione</span>
        </a>

        <a class="staff-quick-card" href="<?= h($baseUrl) ?>/index.php?page=admin_loans">
            <span class="staff-quick-icon staff-quick-icon--blue">↕</span>
            <span class="staff-quick-title">Prestiti</span>
            <span class="staff-quick-text">Registra, restituisci, rinnova</span>
        </a>

        <a class="staff-quick-card" href="<?= h($baseUrl) ?>/index.php?page=admin_patrons">
            <span class="staff-quick-icon staff-quick-icon--coral">👤</span>
            <span class="staff-quick-title">Utenti</span>
            <span class="staff-quick-text">Anagrafica lettori</span>
        </a>

    </div>

    <!-- ------------------------------------------------------------------ -->
    <!-- Sezioni                                                               -->
    <!-- ------------------------------------------------------------------ -->
    <div class="staff-dashboard">

        <!-- CARD 1 – Catalogo -->
        <section class="staff-card">
            <header class="staff-card-header">
                <div class="staff-card-icon-wrap">
                    <span class="staff-card-icon">📚</span>
                </div>
                <div>
                    <h2 class="staff-card-title">Gestione del catalogo</h2>
                    <p class="staff-card-subtitle">Record bibliografici, import e arricchimento</p>
                </div>
            </header>

            <ul class="staff-card-list">

                <li class="staff-card-item staff-card-item--active">
                    <div class="staff-card-item-body">
                        <a href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_entry">Inserisci un nuovo record</a>
                        <p class="staff-card-note">Hub con tutti i metodi di inserimento: manuale, da SBN, da file MARC21/EndNote, da MARCXML.</p>
                    </div>
                </li>

                <li class="staff-card-item staff-card-item--active">
                    <div class="staff-card-item-body">
                        <a href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_edit">Modifica record</a>
                        <p class="staff-card-note">Cerca per BIBID, ISBN o titolo e aggiorna i metadati di un record esistente.</p>
                    </div>
                </li>

                <li class="staff-card-item staff-card-item--active">
                    <div class="staff-card-item-body">
                        <a href="<?= h($baseUrl) ?>/index.php?page=staff_search">Ricerca nel catalogo</a>
                        <p class="staff-card-note">Ricerca avanzata con filtri per collocazione, collezione, soggetti MARC. Accesso diretto alla modifica di ogni record.</p>
                    </div>
                </li>

                <li class="staff-card-item staff-card-item--active">
    <div class="staff-card-item-body">
        <a href="<?= h($baseUrl) ?>/index.php?page=staff_bulk_description">Arricchimento riassunti</a>
        <p class="staff-card-note">Recupera automaticamente il riassunto (MARC 520 $a) per i record con ISBN che non hanno ancora una descrizione. Fonti: SBN → OpenLibrary → Google Books.</p>
    </div>
</li>

                <li class="staff-card-item staff-card-item--prep">
                    <div class="staff-card-item-body">
                        <span class="staff-link-static">Gestione soggetti / tag <span class="staff-feature-pill">in preparazione</span></span>
                        <p class="staff-card-note">Interfaccia per visualizzare, aggiungere o modificare i soggetti usati in "Esplora per tema".</p>
                    </div>
                </li>

                <li class="staff-card-item staff-card-item--active">
    <div class="staff-card-item-body">
        <a href="<?= h($baseUrl) ?>/index.php?page=staff_sbn">Arricchimento metadati da SBN (per ISBN)</a>
        <p class="staff-card-note">Recupera autore, editore, anno e BID dal Catalogo Unico SBN per i record con ISBN. Collegamento automatico a OPAC nazionale.</p>
    </div>
</li>

<li class="staff-card-item staff-card-item--active">
    <div class="staff-card-item-body">
        <a href="<?= h($baseUrl) ?>/index.php?page=staff_sbn_enrich_title">Arricchimento metadati da SBN (per titolo)</a>
        <p class="staff-card-note">Cerca record senza BID SBN per titolo, scegli il match corretto dai risultati e arricchisci con autore, editore, Dewey, soggetti, ecc.</p>
    </div>
</li>

<li class="staff-card-item staff-card-item--active">
    <div class="staff-card-item-body">
        <a href="<?= h($baseUrl) ?>/index.php?page=staff_sbn_import">Importazione nuovi record da SBN</a>
        <p class="staff-card-note">Cerca per ISBN, titolo o autore nel Catalogo Unico SBN e importa nuovi record completi con tutti i campi MARC (autore, editore, soggetti, Dewey, abstract, ecc.).</p>
    </div>
</li>

            </ul>
        </section>

        <!-- CARD 2 – Utenti e prestiti -->
        <section class="staff-card">
            <header class="staff-card-header">
                <div class="staff-card-icon-wrap">
                    <span class="staff-card-icon">👥</span>
                </div>
                <div>
                    <h2 class="staff-card-title">Utenti e prestiti</h2>
                    <p class="staff-card-subtitle">Anagrafica lettori e circolazione</p>
                </div>
            </header>

            <ul class="staff-card-list">

                <li class="staff-card-item staff-card-item--active">
                    <a href="<?= h($baseUrl) ?>/index.php?page=admin_patrons">
                        Lista utenti
                    </a>
                    <p class="staff-card-note">
                        Elenco lettori con ricerca per nome, cognome, email o numero tessera.
                        Crea nuovi utenti e aggiorna i dati anagrafici.
                    </p>
                </li>

                <li class="staff-card-item staff-card-item--active">
                    <div class="staff-card-item-body">
                        <a href="<?= h($baseUrl) ?>/index.php?page=admin_patrons">Lista utenti</a>
                        <p class="staff-card-note">Elenco lettori con ricerca per nome, cognome, email o numero tessera. Crea nuovi utenti e aggiorna i dati anagrafici.</p>
                    </div>
                </li>

                <li class="staff-card-item staff-card-item--active">
                    <div class="staff-card-item-body">
                        <a href="<?= h($baseUrl) ?>/index.php?page=admin_loans">Gestione prestiti</a>
                        <p class="staff-card-note">Registrazione di prestiti, restituzioni e rinnovi, con monitoraggio scadenze e solleciti.</p>
                    </div>
                </li>

                <li class="staff-card-item staff-card-item--prep">
                    <div class="staff-card-item-body">
                        <span class="staff-link-static">Aggiornamento disponibilità copie <span class="staff-feature-pill">in preparazione</span></span>
                        <p class="staff-card-note">Modifica rapida dello stato copia (disponibile / in prestito) e della collocazione fisica.</p>
                    </div>
                </li>

            </ul>
        </section>

        <!-- CARD 3 – Manutenzione -->
        <section class="staff-card">
            <header class="staff-card-header">
                <div class="staff-card-icon-wrap">
                    <span class="staff-card-icon">🛠️</span>
                </div>
                <div>
                    <h2 class="staff-card-title">Manutenzione e report</h2>
                    <p class="staff-card-subtitle">Account staff, statistiche e log</p>
                </div>
            </header>

            <ul class="staff-card-list">

                <li class="staff-card-item staff-card-item--active">
                    <div class="staff-card-item-body">
                        <a href="<?= h($baseUrl) ?>/index.php?page=staff_user_add">Nuovo account staff</a>
                        <p class="staff-card-note">Crea un operatore con username, email e permessi (amministrazione, catalogo, prestiti, report).</p>
                    </div>
                </li>

                <li class="staff-card-item staff-card-item--active">
                    <div class="staff-card-item-body">
                        <a href="<?= h($baseUrl) ?>/index.php?page=staff_user_list">Elenco account staff</a>
                        <p class="staff-card-note">Vista completa degli operatori registrati con modifica e reset password.</p>
                    </div>
                </li>

                <li class="staff-card-item staff-card-item--active">
                    <div class="staff-card-item-body">
                        <a href="<?= h($baseUrl) ?>/index.php?page=admin_reports">Report e statistiche</a>
                        <p class="staff-card-note">Report predefiniti (totale titoli, prestiti attivi, copertine mancanti) con esportazione PDF.</p>
                    </div>
                </li>

                <li class="staff-card-item staff-card-item--prep">
                    <div class="staff-card-item-body">
                        <span class="staff-link-static">Log di sistema <span class="staff-feature-pill">in preparazione</span></span>
                        <p class="staff-card-note">Cronologia delle modifiche rilevanti: creazione e modifica record, prestiti, importazioni MARC.</p>
                    </div>
                </li>

            </ul>
        </section>

    </div><!-- /.staff-dashboard -->

</section>