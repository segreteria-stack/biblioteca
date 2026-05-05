<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Benvenuto alla Biblioteca della Resistenza</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr>
      <td align="center" style="padding:30px 10px">
        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #ddd;border-radius:4px">

          <!-- Header -->
          <tr>
            <td style="background:#b00020;padding:20px 24px;border-radius:4px 4px 0 0">
              <p style="margin:0;color:#ffffff;font-size:18px;font-weight:bold">
                Biblioteca della Resistenza
              </p>
              <p style="margin:4px 0 0 0;color:#ffd0d5;font-size:13px">ANPI Udine</p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:24px 24px 16px 24px">
              <h2 style="margin:0 0 14px 0;color:#222;font-size:20px">Benvenuto<?= ($firstName ?? '') !== '' ? ', ' . h((string)$firstName) : '' ?>!</h2>

              <p style="margin:0 0 12px 0;color:#444;line-height:1.6">
                Il tuo account per l'<strong>Area Utente</strong> della Biblioteca della Resistenza è stato creato.
              </p>

              <p style="margin:0 0 12px 0;color:#444;line-height:1.6">
                Il tuo numero tessera è: <strong><?= h((string)($barcode ?? '')) ?></strong>
              </p>

              <p style="margin:0 0 20px 0;color:#444;line-height:1.6">
                Clicca il pulsante qui sotto per scegliere la tua password e attivare l'account:
              </p>

              <table cellpadding="0" cellspacing="0" style="margin:0 0 20px 0">
                <tr>
                  <td style="background:#b00020;border-radius:6px;padding:12px 24px">
                    <a href="<?= h((string)($activateLink ?? '')) ?>"
                       style="color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold">
                      Attiva il mio account
                    </a>
                  </td>
                </tr>
              </table>

              <p style="margin:0 0 10px 0;color:#666;font-size:13px">
                Il link è valido per <?= h((string)($expires ?? '72 ore')) ?>. Se non riesci a cliccare il pulsante, copia questo indirizzo nel tuo browser:
              </p>
              <p style="margin:0 0 16px 0;word-break:break-all">
                <a href="<?= h((string)($activateLink ?? '')) ?>" style="color:#b00020;font-size:13px">
                  <?= h((string)($activateLink ?? '')) ?>
                </a>
              </p>

              <p style="margin:0;color:#888;font-size:13px;line-height:1.5">
                Se non hai richiesto la creazione di questo account, ignora questa email oppure contatta la biblioteca.
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding:14px 24px;border-top:1px solid #eee">
              <p style="margin:0;font-size:12px;color:#999">
                Messaggio automatico — non rispondere a questa email.<br>
                Biblioteca della Resistenza · ANPI Udine ·
                <a href="https://biblioteca.anpiudine.org" style="color:#999">biblioteca.anpiudine.org</a>
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
