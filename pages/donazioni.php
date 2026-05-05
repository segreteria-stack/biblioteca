<?php
declare(strict_types=1);

/**
 * Pagina "Donazioni"
 *
 * Informazioni su come sostenere la Biblioteca della Resistenza:
 * - donazioni di libri e materiali d’archivio
 * - donazioni economiche
 */

$base = function_exists('base_url') ? base_url() : '';
?>
<section class="page-section page-section--static page-section--donazioni">
    <h1>Donazioni</h1>

    <p>
        La <strong>Biblioteca della Resistenza</strong> è un servizio culturale
        del Comitato Provinciale ANPI di Udine. La crescita del patrimonio
        librario e documentario, così come la possibilità di offrire servizi
        aggiornati al pubblico, dipende in larga parte anche dal sostegno di
        chi condivide le nostre finalità.
    </p>

    <p>
        È possibile contribuire alle attività della Biblioteca attraverso
        <strong>donazioni di materiali</strong> (libri, opuscoli, documenti)
        oppure mediante <strong>donazioni economiche</strong>.
    </p>

    <hr>

    <h2>Donazioni di libri e materiali</h2>

    <p>
        La Biblioteca accoglie con particolare interesse materiali relativi a:
    </p>

    <ul>
        <li>storia della <strong>Resistenza</strong> e dell’antifascismo;</li>
        <li>storia del <strong>Novecento</strong>, con attenzione al Friuli Venezia Giulia;</li>
        <li>storia politica e sociale, diritti umani, memoria e deportazioni;</li>
        <li>fonti a stampa, opuscoli, riviste, documenti d’archivio coerenti con le finalità dell’ANPI.</li>
    </ul>

    <p>
        Per garantire una corretta gestione delle donazioni, prima di consegnare i
        materiali è necessario concordare le modalità con il personale della
        Biblioteca. In particolare:
    </p>

    <ul>
        <li>l’accettazione avviene dopo una <strong>valutazione bibliografica</strong> e di stato di conservazione;</li>
        <li>non possono essere accolti materiali in condizioni gravemente compromesse
            (muffe, danni strutturali, forte usura);</li>
        <li>in caso di donazioni molto consistenti può essere richiesta una
            <strong>lista preliminare dei titoli</strong>.</li>
    </ul>

    <p>
        Per proporre una donazione di libri o documenti puoi scrivere a:<br>
        <a href="mailto:biblioteca@anpiudine.org">biblioteca@anpiudine.org</a><br>
        oppure utilizzare i recapiti indicati nella pagina
        <a href="<?= rtrim(htmlspecialchars($base, ENT_QUOTES), '/') ?>/index.php?page=contatti">
            Contatti
        </a>.
    </p>

    <hr>

    <h2>Donazioni economiche</h2>

    <p>
        Le donazioni economiche permettono di sostenere:
    </p>

    <ul>
        <li>l’acquisto di <strong>nuove pubblicazioni</strong> e basi dati;</li>
        <li>la <strong>catalogazione</strong> e digitalizzazione di fondi documentari;</li>
        <li>attività di <strong>didattica e divulgazione</strong> rivolte a scuole e cittadinanza;</li>
        <li>interventi di <strong>conservazione</strong> e valorizzazione del patrimonio.</li>
    </ul>

    <p>
        È possibile effettuare una donazione tramite <strong>bonifico bancario</strong>
        intestato a:
    </p>

    <p class="donazioni-iban">
        <strong>Intestatario</strong>: Comitato Provinciale ANPI di Udine<br>
        <strong>Causale</strong>: Donazione per Biblioteca della Resistenza<br>
        <strong>IBAN</strong>: <em>IT91 J076 0112 3000 0001 7980 335</em>
    </p>

    <p>
        Per informazioni amministrative, ricevute o donazioni finalizzate a progetti
        specifici (es. digitalizzazioni, mostre, attività con le scuole) è possibile
        contattare la segreteria ANPI all’indirizzo:
        <a href="mailto:info@anpiudine.org">info@anpiudine.org</a>.
    </p>

    <hr>

    <h2>Riconoscimenti</h2>

    <p>
        Salvo diversa richiesta del donatore, le donazioni significative possono essere
        ricordate negli strumenti di comunicazione della Biblioteca (sito web, relazioni
        annuali, materiali di progetto), nel rispetto della normativa sulla privacy.
    </p>

    <p>
        La Biblioteca della Resistenza e il Comitato Provinciale ANPI di Udine
        ringraziano tutte le persone, associazioni e istituzioni che vorranno
        contribuire a mantenere vivo e accessibile il patrimonio della memoria
        democratica.
    </p>
</section>
