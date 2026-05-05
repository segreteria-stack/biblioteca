<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><title>Reimposta la password staff</title></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:32px 0">
  <tr><td align="center">
    <table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;border:1px solid #e0e0e0">
      <tr><td style="background:#2c3e50;padding:24px 32px">
        <h1 style="margin:0;color:#fff;font-size:20px">Staff — Biblioteca della Resistenza</h1>
      </td></tr>
      <tr><td style="padding:32px">
        <h2 style="margin:0 0 16px;font-size:18px;color:#222">Reimposta la tua password staff</h2>
        <p style="margin:0 0 16px;color:#444;line-height:1.6">
          Ciao <?= h($username ?? '') ?>,<br>
          è stata richiesta la reimpostazione della password per il tuo account staff.
          Il link è valido per <strong>1 ora</strong>.
        </p>
        <p style="text-align:center;margin:24px 0">
          <a href="<?= h($resetLink ?? '') ?>"
             style="display:inline-block;background:#2c3e50;color:#fff;padding:14px 28px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:15px">
            Reimposta password
          </a>
        </p>
        <p style="margin:16px 0 0;color:#888;font-size:13px;line-height:1.5">
          Se non hai richiesto il reset, ignora questa email e contatta l'amministratore di sistema.<br>
          In caso di problemi, copia e incolla questo indirizzo nel browser:<br>
          <a href="<?= h($resetLink ?? '') ?>" style="color:#2c3e50;word-break:break-all"><?= h($resetLink ?? '') ?></a>
        </p>
      </td></tr>
      <tr><td style="background:#f9f9f9;padding:16px 32px;border-top:1px solid #e0e0e0">
        <p style="margin:0;color:#aaa;font-size:12px">Messaggio automatico generato dal sistema OPAC.</p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
