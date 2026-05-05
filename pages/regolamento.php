<?php
declare(strict_types=1);

/**
 * Pagina Regolamento della Biblioteca della Resistenza
 *
 * Contiene il regolamento dal Titolo 1 al Titolo 3, formattato
 * in sezioni leggibili e coerenti con lo stile del sito.
 *
 * PHP 8.3
 *
 * @package BibliotecaResistenza\Pages
 */

$baseUrl = function_exists('base_url') ? base_url() : '';
?>
<section class="page-section page-regolamento">
    <header class="regolamento-header">
        <h1>Regolamento della Biblioteca della Resistenza</h1>
        <p class="regolamento-intro">
            Biblioteca della Resistenza del Comitato Provinciale ANPI di Udine.
        </p>

        <!-- Sommario navigabile (Table of Contents) -->
        <nav class="regolamento-toc" aria-label="Sommario del regolamento">
            <p class="regolamento-toc-title">Sommario</p>
            <ol class="regolamento-toc-list">
                <li>
                    <a href="#titolo-1">
                        Titolo 1 – Istituzione e finalità del servizio
                    </a>
                </li>
                <li>
                    <a href="#titolo-2">
                        Titolo 2 – Patrimonio e gestione
                    </a>
                </li>
                <li>
                    <a href="#titolo-3">
                        Titolo 3 – I servizi al pubblico
                    </a>
                </li>
            </ol>
        </nav>
    </header>

    <div class="regolamento-body">
        <!-- ================================
             TITOLO 1 – ISTITUZIONE E FINALITÀ
             ================================ -->
        <section class="regolamento-titolo" id="titolo-1">
            <h2 class="regolamento-titolo-heading">
                <span class="regolamento-titolo-label">Titolo 1</span>
                <span>Istituzione e finalità del servizio</span>
            </h2>

            <!-- Articolo 1 -->
            <article class="regolamento-articolo" id="articolo-1">
                <h3 class="regolamento-articolo-heading">
                    <span class="reg-art-num">Articolo 1</span>
                    <span class="reg-art-title">Funzioni</span>
                </h3>
                <p>
                    La Biblioteca della Resistenza è un servizio del Comitato Provinciale ANPI di Udine,
                    rivolto al pubblico e ai soci interessati. Il servizio è gestito in forma diretta,
                    quale strumento di realizzazione dei fini statutari in ordine alla diffusione della
                    cultura e si prefigge di contribuire alla promozione della crescita culturale e
                    dello sviluppo sociale della comunità in cui la sede associativa è inserita, con
                    particolare attenzione ai temi della storia della Resistenza, dell'antifascismo
                    e della memoria del Novecento.
                </p>
            </article>

            <!-- Articolo 2 -->
            <article class="regolamento-articolo" id="articolo-2">
                <h3 class="regolamento-articolo-heading">
                    <span class="reg-art-num">Articolo 2</span>
                    <span class="reg-art-title">Interventi e attività</span>
                </h3>
                <p>
                    La Biblioteca della Resistenza attua i seguenti interventi:
                </p>

                <div class="regolamento-callout">
                    <div class="regolamento-callout-title">
                        Ambiti principali di raccolta e documentazione:
                    </div>
                    <ul class="regolamento-list">
                        <li>Storia della Resistenza;</li>
                        <li>Antifascismo;</li>
                        <li>Storia del Novecento;</li>
                        <li>Storia locale (Friuli Venezia Giulia);</li>
                        <li>Movimenti di liberazione e temi correlati.</li>
                    </ul>
                </div>

                <p>
                    a) raccoglie, ordina, predispone per l'uso dei soci e dei cittadini libri,
                    periodici, pubblicazioni, materiale documentario in qualsiasi supporto esso si
                    presenti e quant'altro costituisca elemento utile all'informazione, alla
                    documentazione e all'attività di libera lettura nei settori sopra indicati;
                </p>
                <p>
                    b) provvede alla raccolta e alla conservazione di documenti e testi acquisiti
                    per acquisto, dono e scambio;
                </p>
                <p>
                    c) favorisce studi, pubblicazioni, ricerche scolastiche e universitarie;
                </p>
                <p>
                    d) contribuisce all'attuazione del diritto allo studio e all'educazione permanente.
                </p>
            </article>

            <!-- Articolo 3 -->
            <article class="regolamento-articolo" id="articolo-3">
                <h3 class="regolamento-articolo-heading">
                    <span class="reg-art-num">Articolo 3</span>
                    <span class="reg-art-title">Forme di coordinamento</span>
                </h3>
                <p>
                    La Biblioteca promuove forme di collegamento e cooperazione con biblioteche,
                    archivi, agenzie culturali, educative e documentarie, pubbliche e private.
                </p>
            </article>
        </section>

        <!-- ================================
             TITOLO 2 – PATRIMONIO E GESTIONE
             ================================ -->
        <section class="regolamento-titolo" id="titolo-2">
            <h2 class="regolamento-titolo-heading">
                <span class="regolamento-titolo-label">Titolo 2</span>
                <span>Patrimonio e gestione</span>
            </h2>

            <!-- Articolo 4 -->
            <article class="regolamento-articolo" id="articolo-4">
                <h3 class="regolamento-articolo-heading">
                    <span class="reg-art-num">Articolo 4</span>
                    <span class="reg-art-title">Patrimonio</span>
                </h3>
                <p>
                    Il patrimonio della Biblioteca è costituito da:
                </p>
                <ul class="regolamento-list">
                    <li>
                        a) libri e documenti, in qualsiasi supporto essi si presentino, costituenti le
                        raccolte della Biblioteca e da tutto quello successivamente acquisito per
                        acquisto, dono e scambio. Tutto il materiale è registrato in appositi inventari;
                    </li>
                    <li>
                        b) cataloghi, archivi bibliografici, basi di dati;
                    </li>
                    <li>
                        c) attrezzature ed arredi.
                    </li>
                </ul>
            </article>
        </section>

        <!-- ================================
             TITOLO 3 – I SERVIZI AL PUBBLICO
             ================================ -->
        <section class="regolamento-titolo" id="titolo-3">
            <h2 class="regolamento-titolo-heading">
                <span class="regolamento-titolo-label">Titolo 3</span>
                <span>I servizi al pubblico</span>
            </h2>

            <!-- Articolo 5 -->
            <article class="regolamento-articolo" id="articolo-5">
                <h3 class="regolamento-articolo-heading">
                    <span class="reg-art-num">Articolo 5</span>
                    <span class="reg-art-title">Accesso alla Biblioteca</span>
                </h3>
                <p>
                    L'accesso alla Biblioteca e la consultazione in sede sono liberi e gratuiti
                    per tutti i cittadini. L'uso dei servizi deve avvenire con un comportamento
                    rispettoso degli altri e del patrimonio della Biblioteca.
                </p>
            </article>

            <!-- Articolo 6 -->
            <article class="regolamento-articolo" id="articolo-6">
                <h3 class="regolamento-articolo-heading">
                    <span class="reg-art-num">Articolo 6</span>
                    <span class="reg-art-title">Orari di apertura al pubblico</span>
                </h3>

                <div class="regola-evidenziata">
                    <p>
                        I tempi e la durata di apertura al pubblico per l'accesso ai servizi sono
                        fissati dal Comitato Provinciale ANPI di Udine e sono i seguenti:
                    </p>
                    <ul class="regolamento-list">
                        <li>
                            Orari attuali: dal lunedì al venerdì 9:00–13:00, martedì anche 15:00–18:00;
                        </li>
                        <li>
                            l'accesso è preferibilmente su appuntamento.
                        </li>
                    </ul>
                </div>
            </article>

            <!-- Articolo 7 -->
            <article class="regolamento-articolo" id="articolo-7">
                <h3 class="regolamento-articolo-heading">
                    <span class="reg-art-num">Articolo 7</span>
                    <span class="reg-art-title">Consultazione in sede</span>
                </h3>
                <p>
                    La consultazione dei cataloghi e la lettura in sede dei documenti posseduti
                    dalla Biblioteca è libera e gratuita. La Biblioteca mette inoltre a disposizione
                    la consultazione di tipo informatico e telematico.
                </p>
            </article>

            <!-- Articolo 8 -->
            <article class="regolamento-articolo" id="articolo-8">
                <h3 class="regolamento-articolo-heading">
                    <span class="reg-art-num">Articolo 8</span>
                    <span class="reg-art-title">Servizio di prestito</span>
                </h3>
                <p>
                    Il prestito del materiale librario e
                    documentario di proprietà della Biblioteca è riservato ai soci dell'ANPI.
                    Il servizio è autorizzato previa richiesta scritta di iscrizione al prestito, presentando un documento di
                    identità personale e sottoscrivendo l'impegno di rispettare le condizioni
                    stabilite dal presente Regolamento.
                </p>
            </article>

            <!-- Articolo 9 -->
            <article class="regolamento-articolo" id="articolo-9">
                <h3 class="regolamento-articolo-heading">
                    <span class="reg-art-num">Articolo 9</span>
                    <span class="reg-art-title">Condizioni e modalità per il prestito</span>
                </h3>

                <div class="regolamento-callout regola-evidenziata">
                    <div class="regolamento-callout-title">
                        Condizioni principali del prestito:
                    </div>
                    <ul class="regolamento-list">
                        <li>
                            a) possono essere presi a prestito contemporaneamente e cumulativamente:
                            2 libri per 30 giorni;
                        </li>
                        <li>
                            b) il prestito di ogni documento può essere rinnovato una volta,
                            se non è stato nel frattempo prenotato;
                        </li>
                        <li>
                            c) qualora il materiale prestato non sia restituito nel rispetto del
                            termine previsto, all'avvenuta scadenza viene inviata una email con
                            avviso di sollecito;
                        </li>
                        <li>
                            d) è prevista l'esclusione temporanea o definitiva dal servizio di
                            prestito nei seguenti casi: non restituzione del materiale prestato
                            o constatato danneggiamento delle opere prestate;
                        </li>
                        <li>
                            e) non può essere dato in prestito a domicilio il materiale di
                            consultazione, quali ad esempio enciclopedie e dizionari.
                        </li>
                    </ul>
                </div>
            </article>
        </section>
    </div>
</section>
