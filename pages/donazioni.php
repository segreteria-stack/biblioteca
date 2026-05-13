<?php
declare(strict_types=1);

/**
 * Pagina "Donazioni"
 */

$base = function_exists('base_url') ? base_url() : '';

/** @var array<string,mixed> $cfg */
$donFormOk  = false;
$donFormErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['don_submit'])) {
    $donName    = trim((string)($_POST['don_name']    ?? ''));
    $donEmail   = trim((string)($_POST['don_email']   ?? ''));
    $donType    = trim((string)($_POST['don_type']    ?? ''));
    $donMessage = trim((string)($_POST['don_message'] ?? ''));

    $allowedTypes = ['libri' => 'Donazione di libri/materiali', 'economica' => 'Donazione economica', 'altro' => 'Altro'];

    if ($donName === '' || $donEmail === '' || !filter_var($donEmail, FILTER_VALIDATE_EMAIL)) {
        $donFormErr = 'Inserisci nome e indirizzo email valido.';
    } elseif ($donMessage === '') {
        $donFormErr = 'Scrivi un breve messaggio per descrivere la donazione.';
    } else {
        $typeLabel = $allowedTypes[$donType] ?? 'Donazione';
        $subject   = '[Biblioteca] ' . $typeLabel . ' da ' . $donName;
        $body      = '<p><strong>Tipo:</strong> ' . h($typeLabel) . '</p>'
                   . '<p><strong>Nome:</strong> ' . h($donName) . '</p>'
                   . '<p><strong>Email:</strong> ' . h($donEmail) . '</p>'
                   . '<p><strong>Messaggio:</strong><br>' . nl2br(h($donMessage)) . '</p>';

        $staffEmail = (string)($cfg['mail']['staff_email'] ?? '');

        try {
            require_once ROOT . '/lib/EmailService.php';
            $mailer = new EmailService($cfg, ROOT);
            $to = $staffEmail ?: 'biblioteca@anpiudine.org';
            $mailer->send($to, $subject, 'donazione', [
                'don_type_label' => $typeLabel,
                'don_name'       => $donName,
                'don_email'      => $donEmail,
                'don_message'    => $donMessage,
            ]);
            $donFormOk = true;
        } catch (Throwable $e) {
            $donFormOk = true; // non blocchiamo l'utente per errori email
        }
    }
}
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
        <li>storia della <strong>Resistenza</strong> e dell'antifascismo;</li>
        <li>storia del <strong>Novecento</strong>, con attenzione al Friuli Venezia Giulia;</li>
        <li>storia politica e sociale, diritti umani, memoria e deportazioni;</li>
        <li>fonti a stampa, opuscoli, riviste, documenti d'archivio coerenti con le finalità dell'ANPI.</li>
    </ul>

    <p>
        Per garantire una corretta gestione delle donazioni, prima di consegnare i
        materiali è necessario concordare le modalità con il personale della
        Biblioteca. In particolare:
    </p>

    <ul>
        <li>l'accettazione avviene dopo una <strong>valutazione bibliografica</strong> e di stato di conservazione;</li>
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
        <li>l'acquisto di <strong>nuove pubblicazioni</strong> e basi dati;</li>
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
        contattare la segreteria ANPI all'indirizzo:
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

    <hr>

    <h2>Proponi una donazione</h2>
    <p>Usa il modulo qui sotto per segnalarci la tua intenzione di donare. Ti risponderemo entro pochi giorni.</p>

    <?php if ($donFormOk): ?>
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:1rem 1.25rem;color:#166534;margin-bottom:1.5rem">
            <strong>Grazie!</strong> La tua richiesta è stata inviata. Ti contatteremo presto.
        </div>
    <?php endif; ?>

    <?php if ($donFormErr !== ''): ?>
        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:.75rem 1.25rem;color:#991b1b;margin-bottom:1rem">
            <?= h($donFormErr) ?>
        </div>
    <?php endif; ?>

    <?php if (!$donFormOk): ?>
    <form method="post" action="<?= rtrim(h($base), '/') ?>/index.php?page=donazioni" class="donazioni-form" style="max-width:540px">
        <div style="margin-bottom:1rem">
            <label for="don_type" style="display:block;font-weight:600;margin-bottom:.35rem">Tipo di donazione</label>
            <select id="don_type" name="don_type" style="width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px">
                <option value="libri">Libri e materiali</option>
                <option value="economica">Donazione economica</option>
                <option value="altro">Altro</option>
            </select>
        </div>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem">
            <div style="flex:1 1 200px">
                <label for="don_name" style="display:block;font-weight:600;margin-bottom:.35rem">Nome e cognome <span style="color:#b00">*</span></label>
                <input type="text" id="don_name" name="don_name" required style="width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;box-sizing:border-box" value="<?= h($_POST['don_name'] ?? '') ?>">
            </div>
            <div style="flex:1 1 200px">
                <label for="don_email" style="display:block;font-weight:600;margin-bottom:.35rem">Email <span style="color:#b00">*</span></label>
                <input type="email" id="don_email" name="don_email" required style="width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;box-sizing:border-box" value="<?= h($_POST['don_email'] ?? '') ?>">
            </div>
        </div>
        <div style="margin-bottom:1.25rem">
            <label for="don_message" style="display:block;font-weight:600;margin-bottom:.35rem">Descrizione <span style="color:#b00">*</span></label>
            <textarea id="don_message" name="don_message" rows="5" required style="width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;box-sizing:border-box;resize:vertical" placeholder="Descrivi brevemente la donazione che intendi proporre..."><?= h($_POST['don_message'] ?? '') ?></textarea>
        </div>
        <button type="submit" name="don_submit" style="padding:.6rem 1.5rem;background:#b00;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:1rem;font-weight:600">
            Invia proposta
        </button>
    </form>
    <?php endif; ?>

</section>
