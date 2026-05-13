<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Sollecito restituzione</title>
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
              <h2 style="margin:0 0 14px 0;color:#222;font-size:20px">Sollecito restituzione</h2>

              <p style="margin:0 0 16px 0;color:#444;line-height:1.6">
                Ciao<?= ($name ?? '') !== '' ? ' <strong>' . h((string)$name) . '</strong>' : '' ?>,
                ti ricordiamo che <?= count((array)($loans ?? [])) === 1 ? 'il seguente volume è' : 'i seguenti volumi sono' ?> in scadenza o già scaduti:
              </p>

              <table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin-bottom:20px">
                <tr style="background:#f8f8f8">
                  <th style="padding:8px 10px;text-align:left;color:#555;font-size:13px;font-weight:600">Titolo</th>
                  <th style="padding:8px 10px;text-align:left;color:#555;font-size:13px;font-weight:600">Scadenza</th>
                  <th style="padding:8px 10px;text-align:left;color:#555;font-size:13px;font-weight:600">Stato</th>
                </tr>
                <?php foreach ((array)($loans ?? []) as $loan): ?>
                <?php
                  $lTitle   = h((string)($loan['title'] ?? ''));
                  $lDue     = h((string)($loan['due_date'] ?? ''));
                  $lDays    = (int)($loan['days_overdue'] ?? 0);
                  $lColor   = $lDays > 0 ? '#b00020' : '#b07000';
                  $lStatus  = $lDays > 0 ? 'Scaduto da ' . $lDays . ' gg' : 'Scade oggi';
                ?>
                <tr style="border-bottom:1px solid #eee">
                  <td style="padding:9px 10px;color:#222;font-size:14px"><?= $lTitle ?></td>
                  <td style="padding:9px 10px;color:#222;font-size:14px"><?= $lDue ?></td>
                  <td style="padding:9px 10px;font-size:14px;font-weight:bold;color:<?= $lColor ?>"><?= $lStatus ?></td>
                </tr>
                <?php endforeach; ?>
              </table>

              <p style="margin:0 0 10px 0;color:#444;line-height:1.6">
                Ti chiediamo di provvedere alla restituzione il prima possibile presso la biblioteca.
              </p>

              <?php if (($opacLink ?? '') !== ''): ?>
              <p style="margin:16px 0 0 0">
                <a href="<?= h((string)$opacLink) ?>"
                   style="color:#b00020;font-size:13px">Vai all'Area Utente</a>
              </p>
              <?php endif; ?>
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
