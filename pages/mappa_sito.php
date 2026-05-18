<?php
declare(strict_types=1);

/**
 * Pagina "Mappa del sito" (sitemap visiva) OPAC
 *
 * PHP 8.3
 */

$baseUrl = function_exists('base_url') ? base_url() : '';
$u = static fn(string $page): string => h($baseUrl) . '/index.php' . ($page !== '' ? '?page=' . $page : '');
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
                <div class="sitemap-area-title">
                    <span class="sitemap-area-icon">🏠</span>
                    <a href="<?= $u('') ?>">Home – Catalogo online Biblioteca della Resistenza</a>
                </div>
                <ul>
                    <li><span class="sitemap-nolink">Introduzione alla biblioteca e alla collezione</span></li>
                    <li><span class="sitemap-nolink">Ricerca veloce nel catalogo</span></li>
                    <li><span class="sitemap-nolink">Sezione "Dai un'occhiata a caso…" (titoli suggeriti)</span></li>
                </ul>
            </li>

            <!-- CATALOGO ONLINE -->
            <li>
                <div class="sitemap-area-title">
                    <span class="sitemap-area-icon">📚</span>
                    <span>Catalogo online – ricerca e navigazione</span>
                </div>
                <ul>
                    <li>
                        <a href="<?= $u('search') ?>">Ricerca semplice nel catalogo</a>
                        <ul>
                            <li>Ricerca per parole chiave (titolo, autore, soggetto)</li>
                            <li>Filtri di base (sezione, tipologia di materiale)</li>
                        </ul>
                    </li>

                    <li>
                        <a href="<?= $u('search_advanced') ?>">Ricerca avanzata nel catalogo</a>
                        <ul>
                            <li>Ricerca combinata su più campi (titolo, autore, soggetto, anno, editore…)</li>
                            <li>Aggiunta di più righe di ricerca (operatori AND/OR)</li>
                        </ul>
                    </li>

                    <li>
                        <span class="sitemap-nolink">Esplora il catalogo (sfoglia)</span>
                        <ul>
                            <li><a href="<?= $u('browse') . '&amp;type=autori' ?>">Sfoglia per autore</a></li>
                            <li><a href="<?= $u('browse') . '&amp;type=titoli' ?>">Sfoglia per titolo (elenco alfabetico)</a></li>
                            <li>
                                <a href="<?= $u('topics') ?>">Sfoglia per soggetto / tema</a>
                                <span class="sitemap-note">Nuvolette di soggetti principali (Resistenza, Antifascismo, Storia locale, Memoria del Novecento, ecc.) visualizzate in home e nelle pagine di ricerca.</span>
                            </li>
                            <li><a href="<?= $u('novita') ?>">Novità in biblioteca</a> — ultimi titoli inseriti in catalogo</li>
                            <li><a href="<?= $u('percorsi') ?>">Percorsi tematici e suggerimenti di lettura</a></li>
                        </ul>
                    </li>

                    <li>
                        <span class="sitemap-nolink">Scheda titolo (pagina di dettaglio del libro/documento)</span>
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
                <div class="sitemap-area-title">
                    <span class="sitemap-area-icon">ℹ️</span>
                    <span>Informazioni sulla Biblioteca</span>
                </div>
                <ul>
                    <li>
                        <a href="<?= $u('contatti') ?>">Chi siamo, sede e contatti</a>
                        <ul>
                            <li>Presentazione della Biblioteca della Resistenza</li>
                            <li>Collegamento con il Comitato Provinciale ANPI di Udine</li>
                            <li>Indirizzo e recapiti</li>
                            <li>Indicazioni su come arrivare</li>
                        </ul>
                    </li>

                    <li>
                        <a href="<?= $u('regolamento') ?>">Regolamento della biblioteca</a>
                        <ul>
                            <li>Finalità del servizio e funzioni della biblioteca</li>
                            <li>Modalità di accesso, consultazione e comportamento in sede</li>
                            <li>Condizioni e durata del prestito</li>
                            <li>Diritti e doveri degli utenti</li>
                        </ul>
                    </li>

                    <li>
                        <a href="<?= $u('donazioni') ?>">Donazioni</a>
                        <ul>
                            <li>Come donare libri e documenti alla biblioteca</li>
                            <li>Modulo di contatto per proposte di donazione</li>
                        </ul>
                    </li>
                </ul>
            </li>

            <!-- AREA UTENTE (RISERVATA) -->
            <li>
                <div class="sitemap-area-title">
                    <span class="sitemap-area-icon">👤</span>
                    <span>Area utente&nbsp;<span class="sitemap-badge sitemap-badge--restricted">riservata</span></span>
                </div>
                <ul>
                    <li>
                        <a href="<?= $u('patron_login') ?>">Accesso area utente</a>
                        <ul>
                            <li><a href="<?= $u('user_register') ?>">Registrazione nuovo utente</a></li>
                            <li><a href="<?= $u('user_forgot') ?>">Recupero password</a></li>
                        </ul>
                    </li>
                    <li><span class="sitemap-nolink">Visualizzazione e gestione dei dati di profilo</span></li>
                    <li><span class="sitemap-nolink">Elenco prestiti in corso e scadenze</span></li>
                    <li><span class="sitemap-nolink">Cronologia dei prestiti effettuati</span></li>
                    <li><span class="sitemap-nolink">Prenotazioni attive e richieste di libri</span></li>
                </ul>
            </li>

            <!-- AREA STAFF (RISERVATA) -->
            <li>
                <div class="sitemap-area-title">
                    <span class="sitemap-area-icon">🔧</span>
                    <span>Area staff&nbsp;<span class="sitemap-badge sitemap-badge--restricted">riservata</span></span>
                </div>
                <ul>
                    <li>
                        <a href="<?= $u('login') ?>">Accesso staff</a>
                    </li>
                    <li><span class="sitemap-nolink">Gestione catalogo (inserimento e modifica record bibliografici)</span></li>
                    <li><span class="sitemap-nolink">Gestione soggetti / tag e percorsi tematici</span></li>
                    <li><span class="sitemap-nolink">Gestione utenti e prestiti</span></li>
                    <li><span class="sitemap-nolink">Strumenti di amministrazione, log e report statistici</span></li>
                </ul>
            </li>

            <!-- PAGINE LEGALI -->
            <li>
                <div class="sitemap-area-title">
                    <span class="sitemap-area-icon">📄</span>
                    <span>Informazioni legali e documenti</span>
                </div>
                <ul>
                    <li>
                        <a href="<?= $u('privacy') ?>">Privacy Policy</a>
                        <ul>
                            <li>Informazioni sul trattamento dei dati personali</li>
                            <li>Dati raccolti tramite il catalogo online</li>
                            <li>Diritti dell'interessato e modalità di contatto</li>
                        </ul>
                    </li>

                    <li>
                        <a href="<?= $u('note_legali') ?>">Note legali</a>
                        <ul>
                            <li>Informazioni sul titolare del sito</li>
                            <li>Condizioni d'uso del catalogo online</li>
                            <li>Crediti e indicazioni sui contenuti</li>
                        </ul>
                    </li>

                    <li>
                        <a href="<?= $u('mappa_sito') ?>">Mappa del sito</a>
                        <ul>
                            <li>Panoramica completa della struttura del catalogo online</li>
                        </ul>
                    </li>
                </ul>
            </li>

        </ul>
    </div>
</section>
