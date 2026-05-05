<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><title>Prestito in scadenza</title></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:32px 0">
  <tr><td align="center">
    <table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;border:1px solid #e0e0e0">
      <tr><td style="background:#c0392b;padding:24px 32px">
        <h1 style="margin:0;color:#fff;font-size:22px">Biblioteca della Resistenza</h1>
      </td></tr>
      <tr><td style="padding:32px">
        <h2 style="margin:0 0 16px;font-size:18px;color:#222">
          <?= (int)($daysLeft ?? 0) <= 0 ? 'Prestito scaduto' : 'Prestito in scadenza' ?>
        </h2>
        <p style="margin:0 0 16px;color:#444;line-height:1.6">
          Gentile <?= h($patronName ?? 'utente') ?>,
        </p>
        <?php if ((int)($daysLeft ?? 0) <= 0): ?>
        <p style="margin:0 0 16px;color:#c0392b;line-height:1.6">
          Il prestito del libro <strong><?= h($title ?? '') ?></strong>
          <?php if (!empty($author)): ?>di <?= h($author) ?><?php endif; ?>
          è <strong>scaduto</strong>. Ti preghiamo di restituirlo al più presto.
        </p>
        <?php else: ?>
        <p style="margin:0 0 16px;color:#444;line-height:1.6">
          Il prestito del libro <strong><?= h($title ?? '') ?></strong>
          <?php if (!empty($author)): ?>di <?= h($author) ?><?php endif; ?>
          scade fra <strong><?= (int)$daysLeft ?> <?= (int)$daysLeft === 1 ? 'giorno' : 'giorni' ?></strong>
          (<?= h($dueDate ?? '') ?>).
        </p>
        <?php endif; ?>
        <p style="margin:0 0 16px;color:#444;line-height:1.6">
          Per rinnovare il prestito o per maggiori informazioni, rivolgiti allo staff in biblioteca.
        </p>
        <?php if (!empty($opacUrl)): ?>
        <p style="text-align:center;margin:24px 0">
          <a href="<?= h($opacUrl) ?>"
             style="display:inline-block;background:#c0392b;color:#fff;padding:14px 28px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:15px">
            Vai all'area personale
          </a>
        </p>
        <?php endif; ?>
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
