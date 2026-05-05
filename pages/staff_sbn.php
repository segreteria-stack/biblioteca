<?php
declare(strict_types=1);

/** @var \PDO $pdo */
/** @var array $cfg */

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['staff_user_id'])) {
    $baseUrl = function_exists('base_url') ? base_url() : '';
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=staff_sbn');
    exit;
}

$baseUrl    = function_exists('base_url') ? base_url() : '';
$sbnEnabled = !empty($cfg['sbn']['enabled']) 
           && !empty($cfg['sbn']['consumer_key']) 
           && !empty($cfg['sbn']['consumer_secret']);
?>
<section class="page-section page-staff page-staff-sbn">

<header class="staff-header">
    <div class="staff-header-top">
        <div class="staff-header-main">
            <h1>Arricchimento da OPAC SBN</h1>
            <p class="staff-header-subtitle">
                Recupera metadati (autore, editore, anno, BID) dal Catalogo Unico SBN per i record con ISBN.
                <?= $sbnEnabled 
                    ? '<span style="color:#1a7a1a">✓ API SBN attiva</span>' 
                    : '<span style="color:#c80">⚠ Credenziali non configurate</span>' ?>.
            </p>
        </div>
        <div>
            <a href="<?= h($baseUrl) ?>/index.php?page=staff" style="font-size:.88rem;color:#555;text-decoration:none;">
                ← Torna allo staff
            </a>
        </div>
    </div>
</header>

<style>
  .sbn-stats      { display:flex; gap:1.2rem; margin:1.5rem 0; flex-wrap:wrap; }
  .sbn-stat       { background:#f5f5f5; border-radius:8px; padding:.8rem 1.3rem; min-width:130px; text-align:center; }
  .sbn-stat-n     { font-size:1.9rem; font-weight:700; color:#b00; }
  .sbn-stat-l     { font-size:.78rem; color:#555; margin-top:.1rem; }
  .sbn-ctrl       { display:flex; gap:.7rem; align-items:center; flex-wrap:wrap; margin:1.2rem 0; }
  .sbn-ctrl label { font-size:.88rem; font-weight:600; }
  .sbn-ctrl input[type="number"] { width:70px; padding:.4rem .5rem; border:1px solid #ccc; border-radius:4px; }
  .sbn-ctrl button { padding:.5rem 1rem; border:none; border-radius:5px; cursor:pointer; font-size:.88rem; font-weight:600; }
  #sbn-btn-preview  { background:#eee; color:#333; }
  #sbn-btn-run      { background:#b00; color:#fff; }
  #sbn-btn-all      { background:#444; color:#fff; }
  #sbn-btn-test     { background:#1a7a1a; color:#fff; }
  button:disabled   { opacity:.45; cursor:not-allowed; }
  #sbn-progress     { height:7px; background:#e0e0e0; border-radius:4px; margin-bottom:.8rem; overflow:hidden; }
  #sbn-bar          { height:100%; background:#b00; border-radius:4px; width:0; transition:width .4s; }
  #sbn-summary      { font-size:.92rem; font-weight:600; margin-bottom:1rem; min-height:1.3em; }
  #sbn-log table    { width:100%; border-collapse:collapse; font-size:.83rem; }
  #sbn-log th,
  #sbn-log td       { text-align:left; padding:.4rem .6rem; border-bottom:1px solid #eee; }
  #sbn-log th       { background:#f9f9f9; font-weight:600; }
  #sbn-log tr:hover td { background:#fafafa; }
  .sbn-ok           { color:#1a7a1a; font-weight:600; }
  .sbn-notfound     { color:#aaa; }
  .sbn-skip         { color:#c80; }
  .sbn-error        { color:#c00; font-weight:600; }
  .sbn-nonew        { color:#888; }
  code              { font-size:.8rem; background:#f0f0f0; padding:.1rem .3rem; border-radius:3px; }
  .sbn-form-single  { background:#fafafa; border:1px solid #e0e0e0; border-radius:8px; padding:1.2rem; margin:1rem 0; }
  .sbn-form-row     { display:flex; gap:.7rem; align-items:center; flex-wrap:wrap; }
  .sbn-test-result  { margin:1rem 0; padding:.8rem; border-radius:5px; font-size:.9rem; }
  .sbn-test-ok      { background:#e8f4e8; border:1px solid #4a9; color:#1a7a1a; }
  .sbn-test-err     { background:#ffe8e8; border:1px solid #c00; color:#c00; }
  
  /* Ricerca singolo libro */
  .sbn-search-book  { background:#fafafa; border:1px solid #e0e0e0; border-radius:8px; padding:1.2rem; margin:1rem 0; }
  .sbn-search-row   { display:flex; gap:.7rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:.8rem; }
  .sbn-search-row label { display:block; font-size:.88rem; font-weight:600; margin-bottom:.3rem; }
  .sbn-search-row input { padding:.5rem .7rem; border:1px solid #ccc; border-radius:4px; font-size:.9rem; width:280px; }
  .sbn-search-row select { padding:.5rem .7rem; border:1px solid #ccc; border-radius:4px; font-size:.9rem; background:#fff; }
  .sbn-search-row button { padding:.55rem 1.2rem; border:none; border-radius:5px; cursor:pointer; font-size:.9rem; font-weight:600; background:#b00; color:#fff; }
  .sbn-book-result  { margin-top:1rem; }
  .sbn-book-result table { width:100%; border-collapse:collapse; font-size:.9rem; }
  .sbn-book-result th, .sbn-book-result td { text-align:left; padding:.5rem .7rem; border-bottom:1px solid #eee; }
  .sbn-book-result th { background:#f9f9f9; font-weight:600; }
  .sbn-book-result tr:hover td { background:#fafafa; }
  .sbn-btn-enrich   { background:#1a7a1a; color:#fff; border:none; border-radius:4px; padding:.3rem .7rem; cursor:pointer; font-size:.85rem; }
  .sbn-btn-enrich:disabled { opacity:.5; cursor:not-allowed; }
</style>

<div class="sbn-stats">
    <div class="sbn-stat">
        <div class="sbn-stat-n" id="sbn-total">—</div>
        <div class="sbn-stat-l">Record totali</div>
    </div>
    <div class="sbn-stat">
        <div class="sbn-stat-n" id="sbn-with-isbn">—</div>
        <div class="sbn-stat-l">Con ISBN</div>
    </div>
    <div class="sbn-stat">
        <div class="sbn-stat-n" id="sbn-to-enrich">—</div>
        <div class="sbn-stat-l">Da arricchire</div>
    </div>
    <div class="sbn-stat">
        <div class="sbn-stat-n" id="sbn-session-saved">0</div>
        <div class="sbn-stat-l">Arricchiti questa sessione</div>
    </div>
</div>

<?php if (!$sbnEnabled): ?>
<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:5px;padding:1rem;margin-bottom:1rem;color:#856404;">
    <strong>⚠ Attenzione:</strong> Credenziali SBN non configurate. Imposta <code>$cfg['sbn']['consumer_key']</code> e <code>$cfg['sbn']['consumer_secret']</code> nel file di configurazione.
</div>
<?php endif; ?>

<!-- Test connessione -->
<div class="sbn-ctrl">
    <button id="sbn-btn-test" <?= !$sbnEnabled ? 'disabled' : '' ?>>🔌 Test connessione SBN</button>
    <span id="sbn-test-status"></span>
</div>

<!-- Ricerca singolo libro -->
<div class="sbn-search-book">
    <h3 style="margin-top:0;font-size:1rem;">🔍 Ricerca singolo libro su SBN</h3>
    <div class="sbn-search-row">
        <div>
            <label for="sbn-book-q">ISBN, titolo o autore</label>
            <input type="text" id="sbn-book-q" placeholder="Es. 9788807492938 o 'Cosacchi contro partigiani'">
        </div>
        <div>
            <label for="sbn-book-type">Cerca per</label>
            <select id="sbn-book-type">
                <option value="isbn" selected>ISBN</option>
                <option value="titolo">Titolo</option>
                <option value="autore">Autore</option>
                <option value="any">Tutti i campi</option>
            </select>
        </div>
        <button id="sbn-btn-book-search" <?= !$sbnEnabled ? 'disabled' : '' ?>>Cerca su SBN</button>
    </div>
    <div id="sbn-book-result" class="sbn-book-result"></div>
</div>

<!-- Ciclo automatico -->
<div class="sbn-ctrl">
    <label>Record per ciclo:
        <input type="number" id="sbn-limit" value="20" min="1" max="50">
    </label>
    <label>Offset:
        <input type="number" id="sbn-offset" value="0" min="0">
    </label>
    <button id="sbn-btn-preview" <?= !$sbnEnabled ? 'disabled' : '' ?>>👁 Anteprima</button>
    <button id="sbn-btn-run" <?= !$sbnEnabled ? 'disabled' : '' ?>>▶ Ciclo singolo</button>
    <button id="sbn-btn-all" <?= !$sbnEnabled ? 'disabled' : '' ?>>⏩ Lancia tutti</button>
</div>

<div id="sbn-progress"><div id="sbn-bar"></div></div>
<div id="sbn-summary"></div>
<div id="sbn-log"></div>

<!-- Ricerca singola manuale per BIBID -->
<div class="sbn-form-single">
    <h3 style="margin-top:0;font-size:1rem;">Ricerca manuale per BIBID</h3>
    <div class="sbn-form-row">
        <label>BIBID:
            <input type="number" id="sbn-bibid" value="1" min="1">
        </label>
        <button id="sbn-btn-search" <?= !$sbnEnabled ? 'disabled' : '' ?>>🔍 Cerca su SBN</button>
    </div>
    <div id="sbn-single-result"></div>
</div>

<script>
(function() {
    const baseUrl = '<?= h($baseUrl) ?>';
    const btnRun   = document.getElementById('sbn-btn-run');
    const btnAll   = document.getElementById('sbn-btn-all');
    const btnPrev  = document.getElementById('sbn-btn-preview');
    const btnTest  = document.getElementById('sbn-btn-test');
    const btnSearch= document.getElementById('sbn-btn-search');
    const btnBookSearch = document.getElementById('sbn-btn-book-search');
    const logDiv   = document.getElementById('sbn-log');
    const summDiv  = document.getElementById('sbn-summary');
    const bar      = document.getElementById('sbn-bar');
    const singleDiv= document.getElementById('sbn-single-result');
    const bookResultDiv = document.getElementById('sbn-book-result');
    const testStatus = document.getElementById('sbn-test-status');

    let running      = false;
    let sessionSaved = 0;
    let stopRequested = false;

    const getLimit  = () => parseInt(document.getElementById('sbn-limit').value)  || 20;
    const getOffset = () => parseInt(document.getElementById('sbn-offset').value) || 0;
    const setOffset = v  => document.getElementById('sbn-offset').value = v;

    function setRunning(val) {
        running = val;
        [btnRun, btnAll, btnPrev].forEach(b => b.disabled = val);
    }

    function addSaved(n) {
        sessionSaved += n;
        document.getElementById('sbn-session-saved').textContent = sessionSaved;
    }

    function setToEnrich(n) {
        document.getElementById('sbn-to-enrich').textContent = Math.max(0, n);
    }

    function renderTable(results) {
        let html = `<table>
            <tr><th>BIBID</th><th>Titolo</th><th>ISBN</th><th>Stato</th><th>Dettaglio</th></tr>`;
        for (const r of results) {
            let stato, css, detail;
            if (r.status === 'ok') {
                stato = '✅ arricchito'; 
                css = 'sbn-ok';
                detail = r.inserted ? r.inserted.join(', ') : '';
            } else if (r.status === 'no_new_data') {
                stato = '→ già completo'; 
                css = 'sbn-nonew';
                detail = r.detail || 'Tutti i campi già presenti';
            } else if (r.status === 'not_found') {
                stato = '— non trovato'; 
                css = 'sbn-notfound';
                detail = r.detail || 'ISBN non trovato su SBN';
            } else if (r.status === 'skip') {
                stato = '⚠ skip'; 
                css = 'sbn-skip';
                detail = r.detail || r.reason || 'ISBN invalido';
            } else {
                stato = '❌ ' + (r.reason || 'errore'); 
                css = 'sbn-error';
                detail = r.detail || 'Errore sconosciuto';
            }
            html += `<tr>
                <td>${r.bibid}</td>
                <td>${r.title}</td>
                <td><code>${r.isbn}</code></td>
                <td class="${css}">${stato}</td>
                <td style="font-size:.8rem;color:#666;max-width:300px">${detail}</td>
            </tr>`;
        }
        logDiv.innerHTML = html + '</table>';
    }

    async function apiCall(action, limit, offset) {
        const url = baseUrl + '/ajax_sbn_enrich.php?action=' + action + '&limit=' + limit + '&offset=' + offset;
        const res = await fetch(url, { headers: { 'Accept': 'application/json' }});
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('Risposta non valida: ' + text.substring(0, 200));
        }
    }

    // Carica stats
    (async function loadStats() {
        try {
            const res = await fetch(baseUrl + '/ajax_sbn_enrich.php?action=stats');
            const data = await res.json();
            if (data.ok) {
                document.getElementById('sbn-total').textContent = data.total.toLocaleString('it');
                document.getElementById('sbn-with-isbn').textContent = data.with_isbn.toLocaleString('it');
                document.getElementById('sbn-to-enrich').textContent = data.to_enrich.toLocaleString('it');
            }
        } catch(e) { console.error('Stats error:', e); }
    })();

    // Test connessione
    btnTest.addEventListener('click', async () => {
        testStatus.innerHTML = 'Test in corso...';
        try {
            const res = await fetch(baseUrl + '/ajax_sbn_enrich.php?action=test_connection');
            const data = await res.json();
            if (data.ok) {
                testStatus.innerHTML = '<span class="sbn-test-ok">✓ ' + data.msg + ' (' + data.token + ')</span>';
            } else {
                testStatus.innerHTML = '<span class="sbn-test-err">✗ ' + (data.error || 'Errore') + '</span>';
            }
        } catch (e) {
            testStatus.innerHTML = '<span class="sbn-test-err">✗ Errore di rete: ' + e.message + '</span>';
        }
    });

    // Ricerca singolo libro su SBN
    btnBookSearch.addEventListener('click', async () => {
        const q = document.getElementById('sbn-book-q').value.trim();
        const type = document.getElementById('sbn-book-type').value;

        if (!q) return alert('Inserisci un termine di ricerca');

        bookResultDiv.innerHTML = '<p>Ricerca in corso su SBN...</p>';

        try {
            const res = await fetch(baseUrl + '/ajax_sbn_enrich.php?action=search_sbn&q=' + encodeURIComponent(q) + '&type=' + type);
            const data = await res.json();

            if (!data.ok) {
                bookResultDiv.innerHTML = '<p class="sbn-error">❌ ' + (data.error || 'Errore server') + '</p>';
                return;
            }

            if (data.total === 0) {
                bookResultDiv.innerHTML = '<p>⚠ Nessun risultato trovato su SBN per: <code>' + escapeHtml(q) + '</code></p>';
                return;
            }

            let html = '<table><tr><th>BID</th><th>Titolo</th><th>Autore</th><th>Editore</th><th>Anno</th><th>ISBN</th><th>Azioni</th></tr>';

            for (const r of data.results) {
                html += `<tr>
                    <td><code>${escapeHtml(r.bid_sbn || '—')}</code></td>
                    <td>${escapeHtml(r.titolo || '—')}</td>
                    <td>${escapeHtml(r.autore || '—')}</td>
                    <td>${escapeHtml(r.editore || '—')}</td>
                    <td>${escapeHtml(r.anno || '—')}</td>
                    <td>${escapeHtml(r.isbn || '—')}</td>
                    <td>
                        ${r.bid_sbn 
                            ? `<button class="sbn-btn-enrich" onclick="enrichByBid('${escapeHtml(r.bid_sbn)}')">✏️ Arricchisci</button>` 
                            : '—'}
                    </td>
                </tr>`;
            }

            html += '</table>';
            bookResultDiv.innerHTML = html;

        } catch (e) {
            bookResultDiv.innerHTML = '<p class="sbn-error">Errore di rete: ' + escapeHtml(e.message) + '</p>';
        }
    });

    // Arricchisci record esistente per BID SBN
    window.enrichByBid = async function(bid) {
        const bibid = prompt('Inserisci il BIBID del record da arricchire con BID SBN ' + bid + ':');
        if (!bibid || isNaN(bibid)) return;

        bookResultDiv.innerHTML = '<p>Arricchimento in corso...</p>';

        try {
            const res = await fetch(baseUrl + '/ajax_sbn_enrich.php?action=enrich_by_bid&bibid=' + encodeURIComponent(bibid) + '&bid=' + encodeURIComponent(bid));
            const data = await res.json();

            if (data.ok) {
                bookResultDiv.innerHTML = '<p class="sbn-ok">✓ Record BIBID ' + bibid + ' arricchito! Campi inseriti: ' + (data.inserted ? data.inserted.join(', ') : 'nessuno') + '</p>';
                addSaved(1);
            } else {
                bookResultDiv.innerHTML = '<p class="sbn-error">❌ Errore: ' + (data.error || 'Errore sconosciuto') + '</p>';
            }
        } catch (e) {
            bookResultDiv.innerHTML = '<p class="sbn-error">Errore di rete: ' + escapeHtml(e.message) + '</p>';
        }
    };

    btnPrev.addEventListener('click', async () => {
        setRunning(true);
        summDiv.textContent = 'Caricamento anteprima…';
        try {
            const data = await apiCall('run_batch', getLimit(), getOffset());
            if (!data.ok) { summDiv.textContent = 'Errore: ' + (data.error || ''); return; }
            renderTable(data.results);
            summDiv.textContent = `${data.results.length} record in anteprima (offset ${getOffset()}).`;
        } catch (e) {
            summDiv.textContent = 'Errore di rete: ' + e.message;
        } finally { setRunning(false); }
    });

    btnRun.addEventListener('click', async () => {
        if (running) return;
        setRunning(true);
        bar.style.width = '15%';
        summDiv.textContent = 'Elaborazione in corso…';
        try {
            const data = await apiCall('run_batch', getLimit(), getOffset());
            bar.style.width = '100%';
            if (!data.ok) { summDiv.textContent = 'Errore server: ' + (data.error || ''); return; }
            renderTable(data.results);
            setToEnrich(data.remaining);
            addSaved(data.saved);
            setOffset(getOffset() + getLimit());
            summDiv.textContent = `✅ Ciclo completato: ${data.saved} arricchiti su ${data.total} elaborati. Rimanenti: ${data.remaining}.`;
        } catch (e) {
            bar.style.width = '0';
            summDiv.textContent = 'Errore di rete: ' + e.message;
        } finally { setRunning(false); }
    });

    btnAll.addEventListener('click', async () => {
        if (running) return;
        setRunning(true);
        stopRequested = false;
        let offset = getOffset();
        const limit = getLimit();
        let cycles = 0;
        const maxCycles = 1000;

        try {
            while (cycles < maxCycles && !stopRequested) {
                cycles++;
                bar.style.width = '10%';
                summDiv.textContent = `Ciclo #${cycles} a offset ${offset} — caricamento…`;

                const data = await apiCall('run_batch', limit, offset);
                
                console.log('DEBUG Ciclo #' + cycles, 'offset:', offset, 'total:', data.total, 'remaining:', data.remaining, 'saved:', data.saved);
                
                if (!data.ok) { 
                    summDiv.textContent = '❌ Errore server al ciclo #' + cycles + ': ' + (data.error || ''); 
                    break; 
                }

                renderTable(data.results);
                addSaved(data.saved);
                setToEnrich(data.remaining);
                offset += limit;
                setOffset(offset);
                bar.style.width = '80%';

                summDiv.textContent = `Ciclo #${cycles} — elaborati: ${data.total}, salvati: ${data.saved}, rimanenti: ${data.remaining}, prossimo offset: ${offset}`;

                // USCITA: se non ci sono più record da arricchire
                if (data.remaining === 0 || data.total === 0) {
                    bar.style.width = '100%';
                    summDiv.textContent = `🎉 Completato in ${cycles} cicli! Arricchiti questa sessione: ${sessionSaved}.`;
                    break;
                }
                
                await new Promise(r => setTimeout(r, 500));
            }
            
            if (cycles >= maxCycles) {
                summDiv.textContent = `⚠ Raggiunto limite massimo cicli (${maxCycles}). Arricchiti: ${sessionSaved}.`;
            }
            
        } catch (e) {
            bar.style.width = '0';
            summDiv.textContent = '❌ Errore di rete al ciclo #' + cycles + ': ' + e.message;
        } finally { 
            setRunning(false); 
            bar.style.width = '100%';
        }
    });

    // Ricerca singola per BIBID
    btnSearch.addEventListener('click', async () => {
        const bibid = document.getElementById('sbn-bibid').value;
        if (!bibid) return alert('Inserisci un BIBID');

        singleDiv.innerHTML = '<p>Ricerca in corso...</p>';
        try {
            const res = await fetch(baseUrl + '/ajax_sbn_enrich.php?action=enrich_single&bibid=' + bibid);
            const data = await res.json();

            if (!data.ok) {
                singleDiv.innerHTML = '<p class="sbn-error">❌ Errore: ' + (data.error || '') + 
                                      (data.detail ? '<br><small>' + data.detail + '</small>' : '') + '</p>';
                return;
            }

            let html = '<table style="width:100%;border-collapse:collapse;font-size:.9rem;margin-top:.5rem;">';
            html += '<tr><th>Campo</th><th>Originale</th><th>Da SBN</th><th>Stato</th></tr>';
            html += `<tr><td>Titolo</td><td>${data.original.titolo || '—'}</td><td>${data.enriched.titolo || '—'}</td><td>—</td></tr>`;
            html += `<tr><td>Autore</td><td>${data.original.autore || '—'}</td><td>${data.enriched.autore || '—'}</td><td>${data.inserted.includes('autore_marc') ? '<span class="sbn-ok">✓ aggiunto</span>' : '—'}</td></tr>`;
            html += `<tr><td>Editore</td><td>—</td><td>${data.enriched.editore || '—'}</td><td>${data.inserted.includes('editore') ? '<span class="sbn-ok">✓ aggiunto</span>' : '—'}</td></tr>`;
            html += `<tr><td>Anno</td><td>—</td><td>${data.enriched.anno || '—'}</td><td>${data.inserted.includes('anno') ? '<span class="sbn-ok">✓ aggiunto</span>' : '—'}</td></tr>`;
            html += `<tr><td>BID SBN</td><td>—</td><td>${data.enriched.bid_sbn || '—'}</td><td>${data.inserted.includes('bid_sbn') ? '<span class="sbn-ok">✓ aggiunto</span>' : '—'}</td></tr>`;
            html += '</table>';

            if (data.sbn_link) {
                html += `<p style="margin-top:.5rem;"><a href="${data.sbn_link}" target="_blank" style="color:#b00;font-weight:600;">→ Vedi su OPAC SBN</a></p>`;
            }

            singleDiv.innerHTML = html;

        } catch (e) {
            singleDiv.innerHTML = '<p class="sbn-error">Errore di rete: ' + e.message + '</p>';
        }
    });

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }
})();
</script>

</section>