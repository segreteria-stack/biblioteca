<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Prenotazione confermata</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr>
      <td align="center" style="padding:30px 10px">
        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #ddd;border-radius:4px">

          <tr>
            <td style="background:#b00020;padding:20px 24px;border-radius:4px 4px 0 0">
              <p style="margin:0;color:#ffffff;font-size:18px;font-weight:bold">Biblioteca della Resistenza</p>
              <p style="margin:4px 0 0 0;color:#ffd0d5;font-size:13px">ANPI Udine</p>
            </td>
          </tr>

          <tr>
            <td style="padding:24px 24px 16px 24px">
              <h2 style="margin:0 0 14px 0;color:#222;font-size:20px">Prenotazione registrata</h2>

              <p style="margin:0 0 16px 0;color:#444;line-height:1.6">
                Ciao<?= ($patron_name ?? '') !== '' ? ' <strong>' . h((string)$patron_name) . '</strong>' : '' ?>, la tua prenotazione è stata registrata.
                Ti contatteremo non appena il volume sarà disponibile.
              </p>

              <table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin-bottom:20px">
                <tr style="border-bottom:1px solid #eee">
                  <td style="padding:9px 0;color:#666;width:130px;font-size:14px">Titolo</td>
                  <td style="padding:9px 0;color:#222;font-size:14px"><strong><?= h((string)($book_title ?? '')) ?></strong></td>
                </tr>
                <?php if (($book_author ?? '') !== ''): ?>
                <tr style="border-bottom:1px solid #eee">
                  <td style="padding:9px 0;color:#666;font-size:14px">Autore</td>
                  <td style="padding:9px 0;color:#222;font-size:14px"><?= h((string)$book_author) ?></td>
                </tr>
                <?php endif; ?>
              </table>

              <p style="margin:0 0 10px 0;color:#666;font-size:13px;line-height:1.5">
                Puoi verificare lo stato delle tue prenotazioni nell'area personale del sito.
              </p>

              <p style="margin:16px 0 0 0">
                <a href="https://biblioteca.anpiudine.org/public/index.php?page=patron_area&tab=holds"
                   style="color:#b00020;font-size:13px">Vai alle tue prenotazioni</a>
              </p>
            </td>
          </tr>

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
