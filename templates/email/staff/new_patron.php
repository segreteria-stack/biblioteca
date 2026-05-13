<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Nuova registrazione utente</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr>
      <td align="center" style="padding:30px 10px">
        <table width="640" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #ddd">
          <tr>
            <td style="padding:20px 24px">
              <h2 style="margin:0 0 12px 0;color:#333">Nuova registrazione patron</h2>

              <p style="margin:0 0 12px 0;color:#444">
                È stato registrato un nuovo utente nell’OPAC.
              </p>

              <table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse">
                <tr>
                  <td style="padding:8px 0;color:#666;width:140px">Nome</td>
                  <td style="padding:8px 0;color:#222"><strong><?= h((string)($name ?? '')) ?></strong></td>
                </tr>
                <tr>
                  <td style="padding:8px 0;color:#666">Email</td>
                  <td style="padding:8px 0;color:#222"><?= h((string)($email ?? '')) ?></td>
                </tr>
                <tr>
                  <td style="padding:8px 0;color:#666">MBRID</td>
                  <td style="padding:8px 0;color:#222"><?= h((string)($mbrid ?? '')) ?></td>
                </tr>
                <tr>
                  <td style="padding:8px 0;color:#666">Data/Ora</td>
                  <td style="padding:8px 0;color:#222"><?= h((string)($created_at ?? '')) ?></td>
                </tr>
                <tr>
                  <td style="padding:8px 0;color:#666">IP</td>
                  <td style="padding:8px 0;color:#222"><?= h((string)($ip ?? '')) ?></td>
                </tr>
              </table>

              <?php if (!empty($adminLink)): ?>
                <p style="margin:16px 0 0 0">
                  <a href="<?= h((string)$adminLink) ?>">Apri gestione utenti</a>
                </p>
              <?php endif; ?>

              <hr style="border:none;border-top:1px solid #e0e0e0;margin:18px 0">
              <p style="margin:0;font-size:12px;color:#777">Messaggio automatico.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
