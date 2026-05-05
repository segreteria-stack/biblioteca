<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><title>Nuova registrazione utente</title></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:32px 0">
  <tr><td align="center">
    <table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;border:1px solid #e0e0e0">
      <tr><td style="background:#2c3e50;padding:24px 32px">
        <h1 style="margin:0;color:#fff;font-size:20px">Staff — Biblioteca della Resistenza</h1>
      </td></tr>
      <tr><td style="padding:32px">
        <h2 style="margin:0 0 16px;font-size:18px;color:#222">Nuova registrazione utente</h2>
        <p style="margin:0 0 16px;color:#444;line-height:1.6">
          Un nuovo utente si è registrato autonomamente sul portale OPAC.
        </p>
        <table width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #e0e0e0;border-radius:6px;border-collapse:collapse">
          <tr style="background:#f9f9f9"><td style="color:#888;font-size:13px;width:140px;border-bottom:1px solid #e0e0e0">Nome</td><td style="border-bottom:1px solid #e0e0e0"><strong><?= h($name ?? '—') ?></strong></td></tr>
          <tr><td style="color:#888;font-size:13px;border-bottom:1px solid #e0e0e0">Email</td><td style="border-bottom:1px solid #e0e0e0"><?= h($email ?? '—') ?></td></tr>
          <tr style="background:#f9f9f9"><td style="color:#888;font-size:13px;border-bottom:1px solid #e0e0e0">ID tessera</td><td style="border-bottom:1px solid #e0e0e0"><?= h($mbrid ?? '—') ?></td></tr>
          <tr><td style="color:#888;font-size:13px;border-bottom:1px solid #e0e0e0">Data/ora</td><td style="border-bottom:1px solid #e0e0e0"><?= h($created_at ?? '') ?></td></tr>
          <tr style="background:#f9f9f9"><td style="color:#888;font-size:13px">IP</td><td><?= h($ip ?? '—') ?></td></tr>
        </table>
        <p style="margin:20px 0 0;color:#888;font-size:13px;line-height:1.5">
          Verifica i dati nel pannello di amministrazione e attiva l'account se appropriato.
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
