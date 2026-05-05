<?php
declare(strict_types=1);

/**
 * Pagina "Mappa del sito" (sitemap visiva) OPAC
 *
 * Serve sia come strumento di orientamento per l'utente,
 * sia come pagina indicizzabile per i motori di ricerca.
 *
 * PHP 8.3
 *
 * @package BibliotecaResistenza\Pages
 */

$baseUrl = function_exists('base_url') ? base_url() : '';
?>
<section class="page-section page-sitemap">
    <header class="sitemap-header">
        <h1>Mappa del sito</h1>
        <p class="sitemap-intro">
            Questa pagina presenta la struttura del catalogo online della
            <strong>Biblioteca della Resistenza – ANPI Udine</strong>.
            Puoi usarla per orientarti tra le sezioni del sito e raggiungere
            rapidamente le pagine di tuo interesse.
        </p>
        <p class="sitemap-intro">
            La mappa è organizzata in aree funzionali: <em>catalogo</em>,
            <em>informazioni sulla biblioteca</em>, <em>aree riservate</em> e
            <em>pagine legali</em>.
        </p>
    </header>

    <div class="sitemap-body">
        <ul class="sitemap-tree">
            <!-- HOME -->
            <li>
                <a href="<?= h($baseUrl) ?>/index.php">
                    Home – Catalogo online Biblioteca della Resistenza
                </a>
                <ul>
                    <li>Introduzione alla biblioteca e alla collezione</li>
                    <li>Ricerca veloce nel catalogo</li>
                    <li>Sezione “Dai un'occhiata a caso…” (titoli suggeriti)</li>
                </ul>
            </li>

            <!-- CATALOGO ONLINE -->
            <li>
                <a href="<?= h($baseUrl) ?>/index.php?page=search">
                    Catalogo online – ricerca e navigazione
                </a>
                <ul>
                    <li>
                        <a href="<?= h($baseUrl) ?>/index.php?page=search">
                            Ricerca semplice nel catalogo
                        </a>
                        <ul>
                            <li>Ricerca per parole chiave (titolo, autore, soggetto)</li>
                            <li>Filtri di base (sezione, tipologia di materiale – se disponibili)</li>
                        </ul>
                    </li>

                    <li>
                        <a href="<?= h($baseUrl) ?>/index.php?page=search_advanced">
                            Ricerca avanzata nel catalogo
                        </a>
                        <ul>
                            <li>Ricerca combinata su più campi (titolo, autore, soggetto, anno, editore…)</li>
                            <li>Aggiunta di più righe di ricerca (operatori AND/OR)</li>
                        </ul>
                    </li>

                    <li>
                        Esplora il catalogo (sfoglia)
                        <ul>
                            <li>Sfoglia per autore (funzionalità di esplorazione per autore)</li>
                            <li>Sfoglia per titolo (elenco alfabetico dei titoli)</li>
                            <li>Sfoglia per soggetto / tema</li>
                            <li>
                                Esplora per tema (tag cloud)<br>
                                <span class="sitemap-note">
                                    Nuvolette di soggetti principali (Resistenza, Antifascismo,
                                    Storia locale, Memoria del Novecento, ecc.) visualizzate in home
                                    e nelle pagine di ricerca.
                                </span>
                            </li>
                            <li>Novità in biblioteca (ultimi titoli inseriti in catalogo)</li>
                            <li>Percorsi tematici e suggerimenti di lettura</li>
                        </ul>
                    </li>

                    <li>
                        Scheda titolo (pagina di dettaglio del libro/documento)
                        <ul>
                            <li>Dati bibliografici completi (titolo, autore, editore, anno, collana…)</li>
                            <li>Soggetti / tag associati</li>
                            <li>Collocazione e disponibilità del materiale</li>
                            <li>Copertine e riassunti (quando disponibili)</li>
                            <li>Altri titoli dello stesso autore</li>
                        </ul>
                    </li>
                </ul>
            </li>

            <!-- INFORMAZIONI SULLA BIBLIOTECA -->
            <li>
                Informazioni sulla Biblioteca
                <ul>
                    <li>
                        <a href="<?= h($baseUrl) ?>/index.php?page=contatti">
                            Chi siamo, sede e contatti
                        </a>
                        <ul>
                            <li>Presentazione della Biblioteca della Resistenza</li>
                            <li>Collegamento con il Comitato Provinciale ANPI di Udine</li>
                            <li>Indirizzo e recapiti</li>
                            <li>Indicazioni su come arrivare</li>
                        </ul>
                    </li>

                    <li>
                        <a href="<?= h($baseUrl) ?>/index.php?page=regolamento">
                            Regolamento della biblioteca
                        </a>
                        <ul>
                            <li>Finalità del servizio e funzioni della biblioteca</li>
                            <li>Modalità di accesso, consultazione e comportamento in sede</li>
                            <li>Condizioni e durata del prestito</li>
                            <li>Diritti e doveri degli utenti</li>
                        </ul>
                    </li>
                </ul>
            </li>

            <!-- AREA UTENTE (RISERVATA) -->
            <li>
                Area utente (riservata)
                <ul>
                    <li>
                        <a href="<?= h($baseUrl) ?>/index.php?page=patron_login">
                            Accesso area utente
                        </a>
                    </li>
                    <li>Visualizzazione e gestione dei dati di profilo</li>
                    <li>Elenco prestiti in corso e scadenze</li>
                    <li>Cronologia dei prestiti effettuati</li>
                    <li>Prenotazioni attive e richieste di libri</li>
                </ul>
            </li>

            <!-- AREA STAFF (RISERVATA) -->
            <li>
                Area staff (riservata)
                <ul>
                    <li>
                        <a href="<?= h($baseUrl) ?>/index.php?page=login">
                            Accesso staff
                        </a>
                    </li>
                    <li>Gestione catalogo (inserimento e modifica record bibliografici)</li>
                    <li>Gestione soggetti / tag e percorsi tematici</li>
                    <li>Gestione utenti e prestiti</li>
                    <li>Strumenti di amministrazione, log e report statistici</li>
                </ul>
            </li>

            <!-- PAGINE LEGALI E DI SERVIZIO -->
            <li>
                Informazioni legali e documenti
                <ul>
                    <li>
                        <a href="<?= h($baseUrl) ?>/index.php?page=privacy">
                            Privacy Policy
                        </a>
                        <ul>
                            <li>Informazioni sul trattamento dei dati personali</li>
                            <li>Dati raccolti tramite il catalogo online</li>
                            <li>Diritti dell’interessato e modalità di contatto</li>
                        </ul>
                    </li>

                    <li>
                        <a href="<?= h($baseUrl) ?>/index.php?page=note_legali">
                            Note legali
                        </a>
                        <ul>
                            <li>Informazioni sul titolare del sito</li>
                            <li>Condizioni d’uso del catalogo online</li>
                            <li>Crediti e indicazioni sui contenuti</li>
                        </ul>
                    </li>

                    <li>
                        <a href="<?= h($baseUrl) ?>/index.php?page=mappa_sito">
                            Mappa del sito
                        </a>
                        <ul>
                            <li>Panoramica completa della struttura del catalogo online</li>
                        </ul>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</section>
