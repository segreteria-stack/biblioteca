<?php
declare(strict_types=1);

/** @var \PDO $pdo */
/** @var array $cfg */

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['staff_user_id'])) {
    $baseUrl = function_exists('base_url') ? base_url() : '';
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=staff_sbn_enrich_title');
    exit;
}

$baseUrl    = function_exists('base_url') ? base_url() : '';
$sbnEnabled = !empty($cfg['sbn']['enabled']) 
           && !empty($cfg['sbn']['consumer_key']) 
           && !empty($cfg['sbn']['consumer_secret']);

const RECORDS_PER_PAGE = 50;
?>
<section class="page-section page-staff page-staff-sbn-enrich-title">

<header class="staff-header">
    <div class="staff-header-top">
        <div class="staff-header-main">
            <h1>Arricchimento per titolo da SBN</h1>
            <p class="staff-header-subtitle">
                Carica record senza codice SBN, cerca automaticamente e scegli il match corretto.
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
  /* Stats */
  .sbn-enrich-stats { display:flex; gap:1.2rem; margin:1.5rem 0; flex-wrap:wrap; }
  .sbn-enrich-stat { background:#f5f5f5; border-radius:8px; padding:.8rem 1.3rem; min-width:130px; text-align:center; }
  .sbn-enrich-stat-n { font-size:1.9rem; font-weight:700; color:#b00; }
  .sbn-enrich-stat-l { font-size:.78rem; color:#555; margin-top:.1rem; }

  /* Filtri */
  .sbn-filters { display:flex; gap:.7rem; align-items:center; flex-wrap:wrap; margin:1.2rem 0; padding:1rem; background:#f8f8f8; border-radius:8px; }
  .sbn-filters input, .sbn-filters select {
    padding:.4rem .6rem; border:1px solid #ccc; border-radius:4px; font-size:.88rem;
  }
  .sbn-filters input[type="text"] { min-width:220px; }
  .sbn-filters label { font-size:.85rem; color:#555; }

  /* Controlli */
  .sbn-enrich-ctrl { display:flex; gap:.7rem; align-items:center; flex-wrap:wrap; margin:1.2rem 0; }
  #sbn-btn-load { background:#444; color:#fff; padding:.5rem 1rem; border:none; border-radius:5px; cursor:pointer; font-size:.88rem; font-weight:600; }
  #sbn-btn-load:disabled { opacity:.45; cursor:not-allowed; }
  #sbn-btn-skip-all { background:#555; color:#fff; padding:.5rem 1rem; border:none; border-radius:5px; cursor:pointer; font-size:.88rem; font-weight:600; display:none; }
  #sbn-btn-clear-skipped { background:#888; color:#fff; padding:.4rem .8rem; border:none; border-radius:5px; cursor:pointer; font-size:.8rem; }

  /* Progress */
  #sbn-progress { height:7px; background:#e0e0e0; border-radius:4px; margin-bottom:.8rem; overflow:hidden; }
  #sbn-bar { height:100%; background:#b00; border-radius:4px; width:0; transition:width .4s; }
  #sbn-summary { font-size:.92rem; font-weight:600; margin-bottom:1rem; min-height:1.3em; }
  #sbn-log { font-size:.85rem; margin-bottom:100px; }

  /* Record */
  .sbn-record { border:1px solid #ddd; border-radius:8px; margin-bottom:1rem; overflow:hidden; }
  .sbn-record-hdr { background:#f8f8f8; padding:.6rem .9rem; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; }
  .sbn-record-hdr strong { font-size:.95rem; }
  .sbn-record-hdr span { color:#666; font-size:.82rem; }
  .sbn-record-body { padding:.8rem .9rem; }
  .sbn-results { background:#fafafa; border:1px solid #e0e0e0; border-radius:6px; padding:.8rem; margin-top:.5rem; }
  .sbn-results table { width:100%; font-size:.82rem; border-collapse:collapse; }
  .sbn-results th { text-align:left; padding:.3rem .4rem; background:#f0f0f0; font-weight:600; }
  .sbn-results td { padding:.3rem .4rem; border-bottom:1px solid #eee; }
  .sbn-results tr:hover td { background:#f5f5f5; }
  .sbn-enrich-btn { background:#b00; color:#fff; border:none; border-radius:4px; padding:.25rem .6rem; cursor:pointer; font-size:.8rem; }
  .sbn-enrich-btn:disabled { opacity:.5; }
  .sbn-match-ok { background:#e8f4e8; border:1px solid #4a9; border-radius:5px; padding:.6rem; color:#1a7a1a; font-size:.9rem; margin-top:.5rem; }
  .sbn-no-match { color:#c00; font-size:.9rem; margin-top:.5rem; }
  .sbn-searching { color:#666; font-style:italic; }
  .sbn-skipped-badge { background:#ffe0b2; color:#e65100; padding:.15rem .4rem; border-radius:3px; font-size:.75rem; margin-left:.5rem; }
  .sbn-skip-one-btn { background:#fff; color:#666; border:1px solid #ccc; border-radius:4px; padding:.2rem .5rem; cursor:pointer; font-size:.75rem; margin-left:.5rem; }
  .sbn-skip-one-btn:hover { background:#f5f5f5; }

  /* Sticky nav */
  .sbn-sticky-nav {
    position: fixed; bottom: 0; left: 0; right: 0;
    background: #fff; border-top: 2px solid #ddd;
    padding: .8rem 1rem; display: flex;
    justify-content: center; align-items: center; gap: 1rem;
    z-index: 1000; box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
  }
  .sbn-sticky-nav button {
    padding: .5rem 1.2rem; border: 1px solid #ccc;
    background: #fff; border-radius: 5px; cursor: pointer;
    font-size: .9rem; font-weight: 600;
  }
  .sbn-sticky-nav button:disabled { opacity: .4; cursor: not-allowed; }
  .sbn-sticky-nav #sbn-sticky-next { background: #b00; color: #fff; border-color: #b00; }
  .sbn-sticky-nav #sbn-sticky-next:disabled { background: #ccc; border-color: #ccc; }
  .sbn-sticky-nav span { font-size: .9rem; color: #555; min-width: 180px; text-align: center; }
  .sbn-sticky-skip { background: #888 !important; color: #fff !important; }

  /* Jump to page */
  .sbn-jump { display:flex; align-items:center; gap:.4rem; }
  .sbn-jump input { width:60px; padding:.3rem; border:1px solid #ccc; border-radius:4px; text-align:center; }
</style>

<div class="sbn-enrich-stats">
    <div class="sbn-enrich-stat">
        <div class="sbn-enrich-stat-n" id="sbn-total">—</div>
        <div class="sbn-enrich-stat-l">Record senza SBN</div>
    </div>
    <div class="sbn-enrich-stat">
        <div class="sbn-enrich-stat-n" id="sbn-session-enriched">0</div>
        <div class="sbn-enrich-stat-l">Arricchiti sessione</div>
    </div>
    <div class="sbn-enrich-stat">
        <div class="sbn-enrich-stat-n" id="sbn-session-skipped">0</div>
        <div class="sbn-enrich-stat-l">Saltati sessione</div>
    </div>
    <div class="sbn-enrich-stat">
        <div class="sbn-enrich-stat-n" id="sbn-current-page">1</div>
        <div class="sbn-enrich-stat-l">Pagina corrente</div>
    </div>
</div>

<!-- Filtri -->
<div class="sbn-filters">
    <label>🔍 Cerca:</label>
    <input type="text" id="sbn-filter-text" placeholder="Titolo, autore o BIBID..." />
    <label>ISBN:</label>
    <select id="sbn-filter-isbn">
        <option value="">Tutti</option>
        <option value="yes">Con ISBN</option>
        <option value="no">Senza ISBN</option>
    </select>
    <button id="sbn-btn-apply-filter" style="background:#444;color:#fff;padding:.4rem 1rem;border:none;border-radius:4px;cursor:pointer;font-size:.85rem;">Applica filtro</button>
    <button id="sbn-btn-clear-filter" style="background:#888;color:#fff;padding:.4rem 1rem;border:none;border-radius:4px;cursor:pointer;font-size:.85rem;">Azzera</button>
    <button id="sbn-btn-clear-skipped">🗑 Azzera saltati</button>
</div>

<div class="sbn-enrich-ctrl">
    <button id="sbn-btn-load" <?= !$sbnEnabled ? 'disabled' : '' ?>>📂 Carica e cerca automaticamente</button>
    <button id="sbn-btn-skip-all">⏭ Salta tutto il batch</button>
</div>

<div id="sbn-progress"><div id="sbn-bar"></div></div>
<div id="sbn-summary"></div>
<div id="sbn-log"></div>

<!-- Sticky footer -->
<div class="sbn-sticky-nav">
    <button id="sbn-sticky-prev" disabled>← Prec</button>
    <span id="sbn-sticky-info">Pronti</span>
    <div class="sbn-jump">
        <input type="number" id="sbn-jump-input" min="1" placeholder="Pag." />
        <button id="sbn-jump-btn">Vai</button>
    </div>
    <button id="sbn-sticky-next" disabled>Succ →</button>
    <button id="sbn-sticky-skip" class="sbn-sticky-skip" style="display:none;">⏭ Salta batch</button>
</div>

<script>
(function() {
    const baseUrl = '<?= h($baseUrl) ?>';
    const RECORDS_PER_PAGE = <?= RECORDS_PER_PAGE ?>;

    const btnLoad = document.getElementById('sbn-btn-load');
    const btnSkipAll = document.getElementById('sbn-btn-skip-all');
    const btnStickyPrev = document.getElementById('sbn-sticky-prev');
    const btnStickyNext = document.getElementById('sbn-sticky-next');
    const btnStickySkip = document.getElementById('sbn-sticky-skip');
    const stickyInfo = document.getElementById('sbn-sticky-info');
    const btnApplyFilter = document.getElementById('sbn-btn-apply-filter');
    const btnClearFilter = document.getElementById('sbn-btn-clear-filter');
    const btnClearSkipped = document.getElementById('sbn-btn-clear-skipped');
    const filterText = document.getElementById('sbn-filter-text');
    const filterIsbn = document.getElementById('sbn-filter-isbn');
    const jumpInput = document.getElementById('sbn-jump-input');
    const jumpBtn = document.getElementById('sbn-jump-btn');
    const logDiv  = document.getElementById('sbn-log');
    const summDiv = document.getElementById('sbn-summary');
    const bar     = document.getElementById('sbn-bar');

    let running      = false;
    let sessionEnriched = 0;
    let sessionSkipped = 0;
    let currentOffset = 0;
    let totalNoBid   = 0;
    let serverHasMore = false;
    let activeFilter = '';
    let activeHasIsbn = '';
    let skippedBibids = [];
    let useFiltered = false;

    function setRunning(val) {
        running = val;
        btnLoad.disabled = val;
        updateStickyNav();
    }

    function addEnriched(n) {
        sessionEnriched += n;
        document.getElementById('sbn-session-enriched').textContent = sessionEnriched;
    }

    function addSkipped(n) {
        sessionSkipped += n;
        document.getElementById('sbn-session-skipped').textContent = sessionSkipped;
    }

    async function loadSkipped() {
        try {
            const res = await fetch(baseUrl + '/ajax_sbn_enrich.php?action=get_skipped');
            const data = await res.json();
            if (data.ok) {
                skippedBibids = data.skipped || [];
                sessionSkipped = skippedBibids.length;
                document.getElementById('sbn-session-skipped').textContent = sessionSkipped;
            }
        } catch(e) { console.error('Load skipped error:', e); }
    }

    async function updateTotal() {
        try {
            const res = await fetch(baseUrl + '/ajax_sbn_enrich.php?action=stats_no_bid');
            const data = await res.json();
            if (data.ok) {
                totalNoBid = parseInt(data.total) || 0;
                document.getElementById('sbn-total').textContent = totalNoBid.toLocaleString('it');
                updateStickyNav();
            }
        } catch(e) { console.error('Stats error:', e); }
    }

    function updateStickyNav() {
        const hasPrev = currentOffset >= RECORDS_PER_PAGE;
        const currentPage = Math.floor(currentOffset / RECORDS_PER_PAGE) + 1;
        const totalPages = Math.ceil(totalNoBid / RECORDS_PER_PAGE);

        btnStickyPrev.disabled = !hasPrev || running;
        btnStickyNext.disabled = !serverHasMore || running;

        const start = totalNoBid > 0 ? currentOffset + 1 : 0;
        const end = Math.min(currentOffset + RECORDS_PER_PAGE, totalNoBid);

        let infoText = totalNoBid > 0 
            ? `Pag ${currentPage}/${totalPages > 0 ? totalPages : 1} — ${start}-${end} di ${totalNoBid}`
            : 'Nessun record';

        if (useFiltered) {
            infoText += ' (filtrati)';
        }

        stickyInfo.textContent = infoText;
        document.getElementById('sbn-current-page').textContent = currentPage;

        // Aggiorna jump input
        jumpInput.max = totalPages > 0 ? totalPages : 1;
        jumpInput.placeholder = `1-${totalPages > 0 ? totalPages : 1}`;
    }

    // Inizializzazione
    (async function init() {
        await updateTotal();
        await loadSkipped();
    })();

    // ========== CARICAMENTO RECORD ==========
    async function loadAndSearchRecords(offset = 0, options = {}) {
        setRunning(true);
        summDiv.textContent = 'Caricamento record...';
        logDiv.innerHTML = '';
        btnSkipAll.style.display = 'none';
        btnStickySkip.style.display = 'none';

        try {
            await updateTotal();

            let url;
            let totalFiltered = null;

            if (useFiltered && (activeFilter || activeHasIsbn)) {
                url = baseUrl + '/ajax_sbn_enrich.php?action=list_no_bid_filtered'
                    + '&limit=' + RECORDS_PER_PAGE 
                    + '&offset=' + offset
                    + '&filter=' + encodeURIComponent(activeFilter)
                    + '&has_isbn=' + encodeURIComponent(activeHasIsbn);
            } else {
                url = baseUrl + '/ajax_sbn_enrich.php?action=list_no_bid'
                    + '&limit=' + RECORDS_PER_PAGE 
                    + '&offset=' + offset;
            }

            const res = await fetch(url);
            const data = await res.json();

            if (!data.ok) { 
                summDiv.textContent = 'Errore: ' + (data.error || ''); 
                return; 
            }

            currentOffset = offset;

            if (data.total_filtered !== undefined) {
                totalNoBid = data.total_filtered;
            }

            serverHasMore = data.rows.length === RECORDS_PER_PAGE;

            if (data.rows.length === 0) {
                summDiv.textContent = offset === 0 
                    ? 'Nessun record senza codice SBN.' 
                    : 'Fine dei record.';
                updateStickyNav();
                return;
            }

            renderRecords(data.rows);
            summDiv.textContent = `Caricati ${data.rows.length} record. Ricerca SBN in corso...`;
            updateStickyNav();

            window.scrollTo({ top: 0, behavior: 'smooth' });

            for (let i = 0; i < data.rows.length; i++) {
                const row = data.rows[i];
                const pct = ((i + 1) / data.rows.length) * 100;
                bar.style.width = pct + '%';

                await searchSbnForRecord(row.bibid, row.title);
                await new Promise(r => setTimeout(r, 600));
            }

            bar.style.width = '0';
            summDiv.textContent = `Ricerca completata. Scegli i match corretti o salta il batch.`;

            const pendingRecords = document.querySelectorAll('.sbn-record:not(.sbn-done)');
            if (pendingRecords.length > 0) {
                btnSkipAll.style.display = 'inline-block';
                btnStickySkip.style.display = 'inline-block';
            }

        } catch (e) {
            summDiv.textContent = 'Errore di rete: ' + e.message;
        } finally { setRunning(false); }
    }

    // ========== RENDER RECORD ==========
    function renderRecords(rows) {
        let html = '';
        for (const r of rows) {
            const isSkipped = skippedBibids.includes(parseInt(r.bibid));
            const skipBadge = isSkipped ? '<span class="sbn-skipped-badge">⏭ Saltato</span>' : '';

            html += `
            <div class="sbn-record ${isSkipped ? 'sbn-done' : ''}" id="record-${r.bibid}" data-bibid="${r.bibid}">
                <div class="sbn-record-hdr">
                    <div>
                        <strong>BIBID ${r.bibid}</strong> — ${escapeHtml(r.title || '—')}${skipBadge}
                        <br><span>Autore: ${escapeHtml(r.author || '—')} | ISBN: ${escapeHtml(r.isbn || '—')}</span>
                    </div>
                    ${!isSkipped ? `<button class="sbn-skip-one-btn" onclick="skipOneRecord(${r.bibid})">⏭ Salta</button>` : ''}
                </div>
                <div class="sbn-record-body">
                    <div id="sbn-results-${r.bibid}" class="sbn-searching">🔍 Ricerca su SBN in corso...</div>
                </div>
            </div>`;
        }
        logDiv.innerHTML = html;
    }

    // ========== RICERCA SBN PER RECORD ==========
    async function searchSbnForRecord(bibid, title) {
        const resultsDiv = document.getElementById('sbn-results-' + bibid);
        if (!resultsDiv) return;

        // Se il record è saltato, non cercare
        const recordDiv = document.getElementById('record-' + bibid);
        if (recordDiv && recordDiv.classList.contains('sbn-done')) {
            resultsDiv.innerHTML = '<div class="sbn-no-match">⏭ Record saltato</div>';
            return;
        }

        try {
            const res = await fetch(baseUrl + '/ajax_sbn_enrich.php?action=search_sbn&q=' + encodeURIComponent(title) + '&type=titolo');
            const data = await res.json();

            if (!data.ok || data.total === 0) {
                resultsDiv.innerHTML = '<div class="sbn-no-match">❌ Nessun risultato su SBN</div>';
                return;
            }

            let html = '<div class="sbn-results"><table>';
            html += '<tr><th>Codice SBN</th><th>ISBN</th><th>Titolo</th><th>Autore</th><th>Editore</th><th>Anno</th><th>Azione</th></tr>';

            for (const r of data.results.slice(0, 5)) {
                const displayBid = r.bid_sbn ? r.bid_sbn.replace(/IT\\ICCU\\/g, '').replace(/\\/g, '') : '—';
                const jsBid = (r.bid_sbn || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                const displayIsbn = r.isbn || '—';

                html += `<tr>
                    <td><code>${escapeHtml(displayBid)}</code></td>
                    <td><code>${escapeHtml(displayIsbn)}</code></td>
                    <td>${escapeHtml(r.titolo || '—')}</td>
                    <td>${escapeHtml(r.autore || '—')}</td>
                    <td>${escapeHtml(r.editore || '—')}</td>
                    <td>${escapeHtml(r.anno || '—')}</td>
                    <td>
                        <button class="sbn-enrich-btn" onclick="enrichRecord(${bibid}, '${jsBid}')">✅ Arricchisci</button>
                    </td>
                </tr>`;
            }

            html += '</table></div>';
            resultsDiv.innerHTML = html;

        } catch (e) {
            resultsDiv.innerHTML = '<div class="sbn-no-match">❌ Errore ricerca</div>';
        }
    }

    // ========== ARRICCHISCI RECORD ==========
    window.enrichRecord = async function(bibid, bid) {
        const btn = event.target;
        btn.disabled = true;
        btn.textContent = 'Arricchisco...';

        try {
            const res = await fetch(baseUrl + '/ajax_sbn_enrich.php?action=enrich_by_bid&bibid=' + bibid + '&bid=' + encodeURIComponent(bid));
            const data = await res.json();

            if (data.ok) {
                addEnriched(1);
                const recordDiv = document.getElementById('record-' + bibid);
                if (recordDiv) {
                    recordDiv.classList.add('sbn-done');
                    const displayBid = bid.replace(/IT\\ICCU\\/g, '').replace(/\\/g, '');
                    const hasIsbn = data.inserted && data.inserted.includes('isbn');
                    const isbnMsg = hasIsbn ? ' + ISBN' : '';
                    recordDiv.innerHTML = '<div class="sbn-match-ok">✓ Arricchito! Codice SBN: ' + escapeHtml(displayBid) + isbnMsg + ' — Campi: ' + escapeHtml(data.inserted.join(', ')) + '</div>';
                }

                // Rimuovi dai saltati se presente
                skippedBibids = skippedBibids.filter(id => id !== bibid);
                addSkipped(0); // refresh count

                await updateTotal();

            } else {
                alert('Errore: ' + (data.error || 'sconosciuto'));
                btn.disabled = false;
                btn.textContent = '✅ Arricchisci';
            }
        } catch (e) {
            alert('Errore di rete: ' + e.message);
            btn.disabled = false;
            btn.textContent = '✅ Arricchisci';
        }
    };

    // ========== SALTA UN SINGOLO RECORD ==========
    window.skipOneRecord = async function(bibid) {
        try {
            const res = await fetch(baseUrl + '/ajax_sbn_enrich.php?action=mark_skipped&bibid=' + bibid);
            const data = await res.json();
            if (data.ok) {
                if (!skippedBibids.includes(bibid)) {
                    skippedBibids.push(bibid);
                }
                addSkipped(1);

                const recordDiv = document.getElementById('record-' + bibid);
                if (recordDiv) {
                    recordDiv.classList.add('sbn-done');
                    const title = recordDiv.querySelector('strong').textContent;
                    recordDiv.innerHTML = '<div class="sbn-no-match">⏭ Saltato — ' + escapeHtml(title) + '</div>';
                }
            }
        } catch (e) {
            console.error('Skip error:', e);
        }
    };

    // ========== SALTA TUTTO IL BATCH ==========
    function skipAllPending() {
        const pendingRecords = document.querySelectorAll('.sbn-record:not(.sbn-done)');
        let skippedCount = 0;

        pendingRecords.forEach(rec => {
            const bibid = parseInt(rec.dataset.bibid);
            if (bibid && !skippedBibids.includes(bibid)) {
                skippedBibids.push(bibid);
                skippedCount++;
            }
            rec.classList.add('sbn-done');
            rec.style.display = 'none';
        });

        addSkipped(skippedCount);
        btnSkipAll.style.display = 'none';
        btnStickySkip.style.display = 'none';
        summDiv.textContent = `Saltati ${skippedCount} record. Clicca "Succ" per continuare.`;

        // Salva sul server
        pendingRecords.forEach(rec => {
            const bibid = parseInt(rec.dataset.bibid);
            if (bibid) {
                fetch(baseUrl + '/ajax_sbn_enrich.php?action=mark_skipped&bibid=' + bibid).catch(() => {});
            }
        });
    }

    // ========== EVENT LISTENERS ==========
    btnLoad.addEventListener('click', () => loadAndSearchRecords(0));

    btnStickyPrev.addEventListener('click', () => {
        if (currentOffset >= RECORDS_PER_PAGE) loadAndSearchRecords(currentOffset - RECORDS_PER_PAGE);
    });

    btnStickyNext.addEventListener('click', () => {
        if (serverHasMore && !running) {
            loadAndSearchRecords(currentOffset + RECORDS_PER_PAGE);
        }
    });

    btnSkipAll.addEventListener('click', skipAllPending);
    btnStickySkip.addEventListener('click', skipAllPending);

    // Jump to page
    jumpBtn.addEventListener('click', () => {
        const page = parseInt(jumpInput.value);
        if (page && page > 0) {
            const newOffset = (page - 1) * RECORDS_PER_PAGE;
            loadAndSearchRecords(newOffset);
        }
    });

    jumpInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') jumpBtn.click();
    });

    // Filtri
    btnApplyFilter.addEventListener('click', () => {
        activeFilter = filterText.value.trim();
        activeHasIsbn = filterIsbn.value;
        useFiltered = true;
        loadAndSearchRecords(0);
    });

    btnClearFilter.addEventListener('click', () => {
        filterText.value = '';
        filterIsbn.value = '';
        activeFilter = '';
        activeHasIsbn = '';
        useFiltered = false;
        loadAndSearchRecords(0);
    });

    // Azzera saltati
    btnClearSkipped.addEventListener('click', async () => {
        if (!confirm('Sei sicuro di voler azzerare la lista dei record saltati?')) return;
        try {
            const res = await fetch(baseUrl + '/ajax_sbn_enrich.php?action=clear_skipped');
            const data = await res.json();
            if (data.ok) {
                skippedBibids = [];
                sessionSkipped = 0;
                document.getElementById('sbn-session-skipped').textContent = '0';
                summDiv.textContent = 'Lista saltati azzerata. Ricarica per vedere tutti i record.';
            }
        } catch (e) {
            console.error('Clear skipped error:', e);
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