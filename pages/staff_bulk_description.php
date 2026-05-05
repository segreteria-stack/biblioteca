<?php
declare(strict_types=1);

/**
 * staff_bulk_description.php
 *
 * Pagina staff: interfaccia per arricchimento riassunti.
 * Le chiamate AJAX vanno a ajax_bulk_description.php (standalone).
 */

/** @var \PDO   $pdo */
/** @var array  $cfg */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['staff_user_id'])) {
    $baseUrl  = function_exists('base_url') ? base_url() : '';
    $redirect = 'staff_bulk_description';
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=' . urlencode($redirect));
    exit;
}

$baseUrl    = function_exists('base_url') ? base_url() : '';
$apiKey     = $cfg['google_books']['api_key'] ?? '';
$apiEnabled = !empty($cfg['google_books']['enabled']) && $apiKey !== '';

/* ---------------------------------------------------------------
 * Query DB per statistiche
 * --------------------------------------------------------------- */
function bkd_countCandidates(\PDO $pdo): int
{
    return (int)$pdo->query("
        SELECT COUNT(DISTINCT isbn_f.bibid)
        FROM biblio_field isbn_f
        WHERE isbn_f.tag = 20
          AND isbn_f.subfield_cd = 'a'
          AND isbn_f.field_data IS NOT NULL
          AND isbn_f.field_data != ''
          AND NOT EXISTS (
              SELECT 1 FROM biblio_field desc_f
              WHERE desc_f.bibid = isbn_f.bibid
                AND desc_f.tag = 520
                AND desc_f.subfield_cd = 'a'
          )
    ")->fetchColumn();
}

function bkd_countTotal(\PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM biblio")->fetchColumn();
}

function bkd_countWithDesc(\PDO $pdo): int
{
    return (int)$pdo->query(
        "SELECT COUNT(DISTINCT bibid) FROM biblio_field WHERE tag = 520 AND subfield_cd = 'a'"
    )->fetchColumn();
}

$total      = bkd_countTotal($pdo);
$withDesc   = bkd_countWithDesc($pdo);
$candidates = bkd_countCandidates($pdo);
?>
<section class="page-section page-staff page-staff-bulk-desc">

<header class="staff-header">
    <div class="staff-header-top">
        <div class="staff-header-main">
            <h1>Arricchimento riassunti</h1>
            <p class="staff-header-subtitle">
                Recupera automaticamente il riassunto (MARC 520 $a) per i record con ISBN
                che non hanno ancora una descrizione.
                Fonte: Google Books API<?= $apiEnabled ? ' <span style="color:#1a7a1a">✓ API key attiva</span>' : ' <span style="color:#c80">⚠ API key non configurata</span>' ?>.
            </p>
        </div>
        <div>
            <a href="<?= h($baseUrl) ?>/index.php?page=staff"
               style="font-size:.88rem;color:#555;text-decoration:none;">
                ← Torna allo staff
            </a>
        </div>
    </div>
</header>

<style>
  .bkd-stats  { display:flex; gap:1.2rem; margin:1.5rem 0; flex-wrap:wrap; }
  .bkd-stat   { background:#f5f5f5; border-radius:8px; padding:.8rem 1.3rem; min-width:130px; }
  .bkd-stat-n { font-size:1.9rem; font-weight:700; color:#b00; }
  .bkd-stat-l { font-size:.78rem; color:#555; margin-top:.1rem; }
  .bkd-ctrl   { display:flex; gap:.7rem; align-items:center; flex-wrap:wrap; margin-bottom:1.2rem; }
  .bkd-ctrl label { font-size:.88rem; }
  .bkd-ctrl input[type=number] { width:70px; padding:.35rem .5rem; border:1px solid #ccc; border-radius:4px; }
  .bkd-ctrl button { padding:.45rem 1rem; border:none; border-radius:5px; cursor:pointer; font-size:.88rem; font-weight:600; }
  #bkd-btn-preview { background:#eee; color:#333; }
  #bkd-btn-run     { background:#b00; color:#fff; }
  #bkd-btn-all     { background:#444; color:#fff; }
  button:disabled  { opacity:.45; cursor:not-allowed; }
  #bkd-progress    { height:7px; background:#e0e0e0; border-radius:4px; margin-bottom:.8rem; overflow:hidden; }
  #bkd-bar         { height:100%; background:#b00; border-radius:4px; width:0; transition:width .4s; }
  #bkd-summary     { font-size:.92rem; font-weight:600; margin-bottom:1rem; min-height:1.3em; }
  #bkd-log table   { width:100%; border-collapse:collapse; font-size:.83rem; }
  #bkd-log th,
  #bkd-log td      { text-align:left; padding:.38rem .55rem; border-bottom:1px solid #eee; }
  #bkd-log th      { background:#f9f9f9; font-weight:600; }
  tr:hover td      { background:#fafafa; }
  .bkd-ok          { color:#1a7a1a; font-weight:600; }
  .bkd-notfound    { color:#aaa; }
  .bkd-skip        { color:#c80; }
  .bkd-error       { color:#c00; font-weight:600; }
  code             { font-size:.8rem; background:#f0f0f0; padding:.1rem .3rem; border-radius:3px; }
</style>

<div class="bkd-stats">
    <div class="bkd-stat">
        <div class="bkd-stat-n"><?= $total ?></div>
        <div class="bkd-stat-l">Record totali</div>
    </div>
    <div class="bkd-stat">
        <div class="bkd-stat-n"><?= $withDesc ?></div>
        <div class="bkd-stat-l">Con riassunto (520)</div>
    </div>
    <div class="bkd-stat">
        <div class="bkd-stat-n" id="bkd-candidates"><?= $candidates ?></div>
        <div class="bkd-stat-l">Da arricchire</div>
    </div>
    <div class="bkd-stat">
        <div class="bkd-stat-n" id="bkd-session-saved">0</div>
        <div class="bkd-stat-l">Salvati questa sessione</div>
    </div>
</div>

<div class="bkd-ctrl">
    <label>Record per ciclo:
        <input type="number" id="bkd-limit" value="50" min="1" max="100">
    </label>
    <label>Offset:
        <input type="number" id="bkd-offset" value="0" min="0">
    </label>
    <button id="bkd-btn-preview" <?= !$apiEnabled ? 'disabled' : '' ?>>👁 Anteprima</button>
    <button id="bkd-btn-run" <?= !$apiEnabled ? 'disabled' : '' ?>>▶ Ciclo singolo</button>
    <button id="bkd-btn-all" <?= !$apiEnabled ? 'disabled' : '' ?>>⏩ Lancia tutti</button>
</div>

<?php if (!$apiEnabled): ?>
<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:5px;padding:1rem;margin-bottom:1rem;color:#856404;">
    <strong>⚠ Attenzione:</strong> Google Books API non configurata. Imposta <code>$cfg['google_books']['api_key']</code> nel file di configurazione per abilitare l'arricchimento.
</div>
<?php endif; ?>

<div id="bkd-progress"><div id="bkd-bar"></div></div>
<div id="bkd-summary"></div>
<div id="bkd-log"></div>

<script>
(function () {
    const btnRun  = document.getElementById('bkd-btn-run');
    const btnAll  = document.getElementById('bkd-btn-all');
    const btnPrev = document.getElementById('bkd-btn-preview');
    const logDiv  = document.getElementById('bkd-log');
    const summDiv = document.getElementById('bkd-summary');
    const bar     = document.getElementById('bkd-bar');

    let running      = false;
    let sessionSaved = 0;

    const getLimit  = () => parseInt(document.getElementById('bkd-limit').value)  || 50;
    const getOffset = () => parseInt(document.getElementById('bkd-offset').value) || 0;
    const setOffset = v  => document.getElementById('bkd-offset').value = v;

    function setRunning(val) {
        running = val;
        [btnRun, btnAll, btnPrev].forEach(b => b.disabled = val);
    }

    function addSaved(n) {
        sessionSaved += n;
        document.getElementById('bkd-session-saved').textContent = sessionSaved;
    }

    function setCandidates(n) {
        document.getElementById('bkd-candidates').textContent = Math.max(0, n);
    }

    function renderTable(results) {
        let html = `<table>
            <tr><th>BIBID</th><th>Titolo</th><th>ISBN</th><th>Stato</th><th>Anteprima riassunto</th></tr>`;
        for (const r of results) {
            let stato;
            if (r.status === 'ok') {
                stato = '<span class="bkd-ok">✅ salvato</span>';
            } else if (r.status === 'not_found') {
                stato = '<span class="bkd-notfound">— non trovato</span>';
            } else if (r.status === 'skip') {
                stato = '<span class="bkd-skip">⚠ skip</span>';
            } else {
                stato = '<span class="bkd-error">❌ errore</span>';
            }
            html += `<tr>
                <td>${r.bibid}</td>
                <td>${r.title}</td>
                <td><code>${r.isbn}</code></td>
                <td>${stato}</td>
                <td style="color:#555;font-style:italic">${r.preview || ''}</td>
            </tr>`;
        }
        logDiv.innerHTML = html + '</table>';
    }

    // ================================================================
    // CHIAMATA AJAX all'endpoint standalone (stesso pattern di cover_save.php)
    // ================================================================
    async function apiCall(action, limit, offset) {
        const url = `<?= h($baseUrl) ?>/ajax_bulk_description.php?action=${action}&limit=${limit}&offset=${offset}`;
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json' }
        });
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}: ${res.statusText}`);
        }
        return res.json();
    }

    btnPrev.addEventListener('click', async () => {
        setRunning(true);
        summDiv.textContent = 'Caricamento anteprima…';
        try {
            const data = await apiCall('preview', getLimit(), getOffset());
            if (!data.ok) {
                summDiv.textContent = 'Errore: ' + (data.error || 'sconosciuto');
                return;
            }
            let html = '<table><tr><th>BIBID</th><th>Titolo</th><th>ISBN</th></tr>';
            for (const r of data.rows) {
                html += `<tr><td>${r.bibid}</td><td>${r.title}</td><td><code>${r.isbn}</code></td></tr>`;
            }
            logDiv.innerHTML = html + '</table>';
            summDiv.textContent = `${data.rows.length} record in anteprima (offset ${getOffset()}).`;
        } catch (e) {
            summDiv.textContent = 'Errore di rete: ' + e.message;
        } finally {
            setRunning(false);
        }
    });

    btnRun.addEventListener('click', async () => {
        if (running) return;
        setRunning(true);
        bar.style.width = '15%';
        summDiv.textContent = 'Elaborazione in corso…';
        try {
            const data = await apiCall('run', getLimit(), getOffset());
            bar.style.width = '100%';
            if (!data.ok) {
                summDiv.textContent = 'Errore server: ' + (data.error || '');
                return;
            }
            renderTable(data.results);
            setCandidates(data.remaining);
            addSaved(data.saved);
            setOffset(getOffset() + getLimit());
            summDiv.textContent =
                `✅ Ciclo completato: ${data.saved} salvati su ${data.total} elaborati. Rimanenti: ${data.remaining}.`;
        } catch (e) {
            bar.style.width = '0';
            summDiv.textContent = 'Errore di rete: ' + e.message;
        } finally {
            setRunning(false);
        }
    });

    btnAll.addEventListener('click', async () => {
        if (running) return;
        setRunning(true);
        let offset = getOffset();
        const limit = getLimit();
        let cycles = 0;
        const maxCycles = 1000;

        try {
            while (cycles < maxCycles) {
                cycles++;
                bar.style.width = '20%';
                summDiv.textContent = `Ciclo #${cycles} a offset ${offset} — salvati questa sessione: ${sessionSaved}…`;

                const data = await apiCall('run', limit, offset);
                if (!data.ok) {
                    summDiv.textContent = 'Errore server: ' + (data.error || '');
                    break;
                }

                renderTable(data.results);
                addSaved(data.saved);
                setCandidates(data.remaining);
                offset += limit;
                setOffset(offset);
                bar.style.width = '80%';

                if (data.remaining === 0 || data.total === 0) break;

                await new Promise(r => setTimeout(r, 1000));
            }

            if (cycles >= maxCycles) {
                summDiv.textContent = `⚠ Interrotto dopo ${maxCycles} cicli per sicurezza. Offset attuale: ${offset}.`;
            } else {
                bar.style.width = '100%';
                summDiv.textContent = `🎉 Completato! Riassunti salvati questa sessione: ${sessionSaved}.`;
            }
        } catch (e) {
            bar.style.width = '0';
            summDiv.textContent = 'Errore di rete: ' + e.message;
        } finally {
            setRunning(false);
        }
    });
})();
</script>

</section>