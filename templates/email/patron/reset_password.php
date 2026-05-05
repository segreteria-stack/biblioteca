<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Reimposta la password</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr>
      <td align="center" style="padding:30px 10px">
        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #ddd">
          <tr>
            <td style="padding:20px 24px">
              <h2 style="margin:0 0 12px 0;color:#333">Reimposta la password</h2>

              <p style="margin:0 0 10px 0;color:#444">
                Hai richiesto la reimpostazione della password per <strong>Biblioteca della Resistenza</strong>.
              </p>

              <p style="margin:0 0 10px 0;color:#444">
                <a href="<?= h((string)($resetLink ?? '')) ?>">Clicca qui per impostare una nuova password</a>
              </p>

              <p style="margin:0 0 10px 0;color:#444">
                Il link è valido per <?= h((string)($expires ?? '1 ora')) ?>.
              </p>

              <p style="margin:0 0 10px 0;color:#444">
                Se non sei stato tu, ignora questa email.
              </p>

              <hr style="border:none;border-top:1px solid #e0e0e0;margin:18px 0">

              <p style="margin:0;font-size:12px;color:#777">
                Messaggio automatico, non rispondere a questa email.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
