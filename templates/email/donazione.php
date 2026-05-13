<!doctype html>
<html lang="it">
<head><meta charset="utf-8"><title>Richiesta donazione</title></head>
<body style="font-family:system-ui,sans-serif;color:#222;max-width:600px;margin:0 auto;padding:1.5rem">
<h2 style="color:#b00">Nuova richiesta di donazione</h2>
<table style="border-collapse:collapse;width:100%">
  <tr><th style="text-align:left;padding:.4rem .6rem;background:#f5f5f5;width:130px">Tipo</th>
      <td style="padding:.4rem .6rem;border-bottom:1px solid #eee"><?= h($don_type_label ?? '') ?></td></tr>
  <tr><th style="text-align:left;padding:.4rem .6rem;background:#f5f5f5">Nome</th>
      <td style="padding:.4rem .6rem;border-bottom:1px solid #eee"><?= h($don_name ?? '') ?></td></tr>
  <tr><th style="text-align:left;padding:.4rem .6rem;background:#f5f5f5">Email</th>
      <td style="padding:.4rem .6rem;border-bottom:1px solid #eee"><a href="mailto:<?= h($don_email ?? '') ?>"><?= h($don_email ?? '') ?></a></td></tr>
  <tr><th style="text-align:left;padding:.4rem .6rem;background:#f5f5f5;vertical-align:top">Messaggio</th>
      <td style="padding:.4rem .6rem"><?= nl2br(h($don_message ?? '')) ?></td></tr>
</table>
<hr style="margin:1.5rem 0;border:none;border-top:1px solid #e5e7eb">
<p style="font-size:.8rem;color:#6b7280">Biblioteca della Resistenza — ANPI Udine</p>
</body>
</html>
