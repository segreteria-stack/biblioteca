<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><title>Conferma la tua email</title></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:32px 0">
  <tr><td align="center">
    <table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;border:1px solid #e0e0e0">
      <tr><td style="background:#c0392b;padding:24px 32px">
        <h1 style="margin:0;color:#fff;font-size:22px">Biblioteca della Resistenza</h1>
      </td></tr>
      <tr><td style="padding:32px">
        <h2 style="margin:0 0 16px;font-size:18px;color:#222">Conferma il tuo indirizzo email</h2>
        <p style="margin:0 0 16px;color:#444;line-height:1.6">
          Grazie per esserti registrato<?= !empty($firstName) ? ', <strong>' . h($firstName) . '</strong>' : '' ?>!
          Per completare la registrazione devi confermare il tuo indirizzo email.
          Il link è valido per <strong><?= h($expires ?? '24 ore') ?></strong>.
        </p>
        <p style="text-align:center;margin:24px 0">
          <a href="<?= h($verifyLink ?? '') ?>"
             style="display:inline-block;background:#c0392b;color:#fff;padding:14px 28px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:15px">
            Conferma email
          </a>
        </p>
        <p style="margin:16px 0 0;color:#888;font-size:13px;line-height:1.5">
          Se non hai effettuato questa registrazione, ignora questa email.<br>
          In caso di problemi, copia e incolla questo indirizzo nel browser:<br>
          <a href="<?= h($verifyLink ?? '') ?>" style="color:#c0392b;word-break:break-all"><?= h($verifyLink ?? '') ?></a>
        </p>
      </td></tr>
      <tr><td style="background:#f9f9f9;padding:16px 32px;border-top:1px solid #e0e0e0">
        <p style="margin:0;color:#aaa;font-size:12px">
          Biblioteca della Resistenza — ANPI Udine &bull; Messaggio automatico, non rispondere a questa email.
        </p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
