<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><title>Test email OPAC</title></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:32px 0">
  <tr><td align="center">
    <table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;border:1px solid #e0e0e0">
      <tr><td style="background:#27ae60;padding:24px 32px">
        <h1 style="margin:0;color:#fff;font-size:20px">✓ Test email — Biblioteca della Resistenza</h1>
      </td></tr>
      <tr><td style="padding:32px">
        <p style="margin:0 0 12px;color:#444;line-height:1.6">
          Questo è un messaggio di test inviato dal sistema OPAC.
        </p>
        <p style="margin:0;color:#888;font-size:13px">Timestamp: <?= h($ts ?? date('Y-m-d H:i:s')) ?></p>
      </td></tr>
      <tr><td style="background:#f9f9f9;padding:16px 32px;border-top:1px solid #e0e0e0">
        <p style="margin:0;color:#aaa;font-size:12px">Messaggio automatico generato dal sistema OPAC.</p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
