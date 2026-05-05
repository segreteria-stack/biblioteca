<?php
declare(strict_types=1);

/** @var \PDO $pdo */
/** @var array $cfg */

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['staff_user_id'])) {
    $baseUrl = function_exists('base_url') ? base_url() : '';
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=staff_sbn_import');
    exit;
}

$baseUrl    = function_exists('base_url') ? base_url() : '';
$sbnEnabled = !empty($cfg['sbn']['enabled']) 
           && !empty($cfg['sbn']['consumer_key']) 
           && !empty($cfg['sbn']['consumer_secret']);
?>
<section class="page-section page-staff page-staff-sbn-import">

<header class="staff-header">
    <div class="staff-header-top">
        <div class="staff-header-main">
            <h1>Importazione da OPAC SBN</h1>
            <p class="staff-header-subtitle">
                Cerca per ISBN, modifica i metadati e importa nuovi record nel catalogo.
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
  .sbn-import-form { background:#fafafa; border:1px solid #e0e0e0; border-radius:8px; padding:1.5rem; margin:1.5rem 0; }
  .sbn-import-row  { display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:1rem; }
  .sbn-import-row label { display:block; font-size:.88rem; color:#333; margin-bottom:.3rem; font-weight:600; }
  .sbn-import-row input { padding:.5rem .7rem; border:1px solid #ccc; border-radius:4px; font-size:.9rem; width:320px; }
  .sbn-import-row select { padding:.5rem .7rem; border:1px solid #ccc; border-radius:4px; font-size:.9rem; background:#fff; }
  .sbn-import-row button { padding:.55rem 1.2rem; border:none; border-radius:5px; cursor:pointer; font-size:.9rem; font-weight:600; background:#b00; color:#fff; }
  .sbn-import-row button:disabled { opacity:.5; cursor:not-allowed; }
  #sbn-results { margin-top:1.5rem; }
  #sbn-results table { width:100%; border-collapse:collapse; font-size:.9rem; }
  #sbn-results th, #sbn-results td { text-align:left; padding:.5rem .7rem; border-bottom:1px solid #eee; vertical-align:top; }
  #sbn-results th { background:#f9f9f9; font-weight:600; }
  #sbn-results tr:hover td { background:#fafafa; }
  .sbn-btn-preview { background:#444; color:#fff; border:none; border-radius:4px; padding:.3rem .7rem; cursor:pointer; font-size:.85rem; margin-right:.3rem; }
  .sbn-btn-import { background:#1a7a1a; color:#fff; border:none; border-radius:4px; padding:.3rem .7rem; cursor:pointer; font-size:.85rem; }
  .sbn-btn-import:disabled, .sbn-btn-preview:disabled { opacity:.5; cursor:not-allowed; }
  .sbn-import-ok { background:#e8f4e8; border:1px solid #4a9; border-radius:5px; padding:1rem; margin:1rem 0; color:#1a7a1a; }
  .sbn-import-err { background:#ffe8e8; border:1px solid #c00; border-radius:5px; padding:1rem; margin:1rem 0; color:#c00; }
  .sbn-import-warn { background:#fff3cd; border:1px solid #ffc107; border-radius:5px; padding:1rem; margin:1rem 0; color:#856404; }

  /* Modal preview editabile */
  .sbn-modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.5); z-index:1000; justify-content:center; align-items:center; }
  .sbn-modal-overlay.active { display:flex; }
  .sbn-modal { background:#fff; border-radius:8px; max-width:800px; width:95%; max-height:90vh; overflow-y:auto; padding:1.5rem; box-shadow:0 4px 20px rgba(0,0,0,.2); }
  .sbn-modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; border-bottom:1px solid #eee; padding-bottom:.5rem; }
  .sbn-modal-header h3 { margin:0; font-size:1.1rem; }
  .sbn-modal-close { background:none; border:none; font-size:1.5rem; cursor:pointer; color:#666; }
  .sbn-modal-close:hover { color:#000; }

  .sbn-edit-grid { display:grid; grid-template-columns:130px 1fr; gap:.4rem 1rem; font-size:.9rem; align-items:start; }
  .sbn-edit-label { font-weight:600; color:#555; padding-top:.35rem; }
  .sbn-edit-field input, .sbn-edit-field textarea, .sbn-edit-field select { width:100%; padding:.35rem .5rem; border:1px solid #ccc; border-radius:4px; font-size:.9rem; font-family:inherit; background:#fff; }
  .sbn-edit-field select { cursor: pointer; }
  .sbn-edit-field input:focus, .sbn-edit-field textarea:focus { border-color:#b00; outline:none; }
  .sbn-edit-field textarea { min-height:60px; resize:vertical; }
  .sbn-edit-field code { background:#f0f0f0; padding:.1rem .3rem; border-radius:3px; font-size:.85rem; }
  .sbn-edit-readonly { background:#f5f5f5; padding:.35rem .5rem; border-radius:4px; color:#666; }
  .sbn-edit-section { grid-column:1 / -1; font-weight:700; color:#b00; margin-top:.8rem; padding-top:.5rem; border-top:1px solid #eee; }

  .sbn-modal-actions { margin-top:1.5rem; display:flex; gap:.7rem; justify-content:flex-end; padding-top:1rem; border-top:1px solid #eee; }
  .sbn-modal-actions button { padding:.5rem 1rem; border:none; border-radius:5px; cursor:pointer; font-size:.9rem; font-weight:600; }
  .sbn-modal-import { background:#1a7a1a; color:#fff; }
  .sbn-modal-cancel { background:#eee; color:#333; }
  .sbn-modal-import:disabled { opacity:.5; }

  /* Messaggio di successo nel modal */
  .sbn-success-banner { background:#e8f4e8; border:2px solid #1a7a1a; border-radius:8px; padding:1.2rem; margin-bottom:1rem; text-align:center; }
  .sbn-success-banner h4 { margin:0 0 .5rem; color:#1a7a1a; font-size:1.1rem; }
  .sbn-success-banner p { margin:0 0 .8rem; color:#333; }
  .sbn-success-banner a { display:inline-block; background:#1a7a1a; color:#fff; padding:.5rem 1.2rem; border-radius:5px; text-decoration:none; font-weight:600; }
  .sbn-success-banner a:hover { background:#146314; }
  .sbn-success-banner .sbn-close-hint { font-size:.8rem; color:#666; margin-top:.5rem; }
</style>

<div class="sbn-import-form">
    <div class="sbn-import-row">
        <div>
            <label for="sbn-search-q">ISBN, titolo o autore</label>
            <input type="text" id="sbn-search-q" placeholder="Es. 9788807492938 o 'Cosacchi contro partigiani'">
        </div>
        <div>
            <label for="sbn-search-type">Cerca per</label>
            <select id="sbn-search-type">
                <option value="isbn" selected>ISBN</option>
                <option value="titolo">Titolo</option>
                <option value="autore">Autore</option>
                <option value="any">Tutti i campi</option>
            </select>
        </div>
        <button id="sbn-search-btn" <?= !$sbnEnabled ? 'disabled' : '' ?>>🔍 Cerca su SBN</button>
    </div>
</div>

<div id="sbn-results"></div>

<!-- Modal Preview Editabile -->
<div class="sbn-modal-overlay" id="sbn-preview-modal">
    <div class="sbn-modal">
        <div class="sbn-modal-header">
            <h3>📋 Modifica e importa record SBN</h3>
            <button class="sbn-modal-close" onclick="closePreview()">&times;</button>
        </div>
        <div id="sbn-preview-content"></div>
        <div class="sbn-modal-actions">
            <button class="sbn-modal-cancel" onclick="closePreview()">Annulla</button>
            <button class="sbn-modal-import" id="sbn-modal-import-btn" onclick="importFromPreview()">📥 Importa nel catalogo</button>
        </div>
    </div>
</div>

<script>
(function() {
    const baseUrl = '<?= h($baseUrl) ?>';
    const resultsDiv = document.getElementById('sbn-results');
    const modal = document.getElementById('sbn-preview-modal');
    const previewContent = document.getElementById('sbn-preview-content');
    const importBtn = document.getElementById('sbn-modal-import-btn');

    let searchResults = [];
    let currentRecord = null;

    document.getElementById('sbn-search-btn').addEventListener('click', async () => {
        const q = document.getElementById('sbn-search-q').value.trim();
        const type = document.getElementById('sbn-search-type').value;

        if (!q) return alert('Inserisci un termine di ricerca');

        resultsDiv.innerHTML = '<p>Ricerca in corso su SBN...</p>';

        try {
            const res = await fetch(baseUrl + '/ajax_sbn_enrich.php?action=search_sbn&q=' + encodeURIComponent(q) + '&type=' + type);
            const data = await res.json();

            if (!data.ok) {
                resultsDiv.innerHTML = '<div class="sbn-import-err">❌ ' + escapeHtml(data.error || 'Errore server') + '</div>';
                return;
            }

            if (data.total === 0) {
                resultsDiv.innerHTML = '<div class="sbn-import-warn">⚠ Nessun risultato trovato su SBN per: <code>' + escapeHtml(q) + '</code> (' + escapeHtml(type) + ')</div>';
                return;
            }

            searchResults = data.results;

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
                            ? `<button class="sbn-btn-preview" data-bid="${escapeHtml(r.bid_sbn)}">✏️ Modifica & Importa</button>` 
                            : '—'}
                    </td>
                </tr>`;
            }

            html += '</table>';
            resultsDiv.innerHTML = html;

        } catch (e) {
            resultsDiv.innerHTML = '<div class="sbn-import-err">Errore di rete: ' + escapeHtml(e.message) + '</div>';
        }
    });

    // Event delegation
    resultsDiv.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-bid]');
        if (!btn) return;
        const bid = btn.dataset.bid;
        if (btn.classList.contains('sbn-btn-preview')) {
            openPreview(bid);
        }
    });

    // Apre modal con form editabile
    window.openPreview = function(bid) {
        currentRecord = searchResults.find(r => r.bid_sbn === bid);
        if (!currentRecord) {
            alert('Record non trovato');
            return;
        }

        importBtn.disabled = false;
        importBtn.textContent = '📥 Importa nel catalogo';
        importBtn.style.display = '';
        previewContent.innerHTML = renderEditForm(currentRecord);
        modal.classList.add('active');
    };

    // FIX: raccoglie dati dal form e li invia a import_record_with_data via POST
    window.importFromPreview = async function() {
        if (!currentRecord) return;

        importBtn.disabled = true;
        importBtn.textContent = 'Importo...';

        // Raccoglie tutti i dati modificati dal form
        const editedData = collectFormData();
        editedData.bid_sbn = currentRecord.bid_sbn;

        try {
            const res = await fetch(
                baseUrl + '/ajax_sbn_enrich.php?action=import_record_with_data',
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(editedData),
                }
            );
            const data = await res.json();

            if (data.ok) {
                // Sostituisce il contenuto del modal con messaggio di successo
                const detailUrl = baseUrl + '/index.php?page=item&bibid=' + encodeURIComponent(data.bibid);
                previewContent.innerHTML = `
                    <div class="sbn-success-banner">
                        <h4>✓ Record importato con successo!</h4>
                        <p>BIBID <strong>${escapeHtml(String(data.bibid))}</strong> — Copia <strong>${escapeHtml(String(data.copyid || '—'))}</strong></p>
                        <a href="${escapeHtml(detailUrl)}" target="_blank">→ Vedi scheda record</a>
                        <p class="sbn-close-hint">La finestra si chiuderà automaticamente tra 4 secondi...</p>
                    </div>
                `;
                importBtn.style.display = 'none';
                
                // Chiude automaticamente dopo 4 secondi e pulisce la lista risultati
                setTimeout(() => {
                    closePreview();
                    // Pulisce i risultati della ricerca per evitare re-import
                    resultsDiv.innerHTML = '';
                    searchResults = [];
                }, 4000);

            } else {
                previewContent.insertAdjacentHTML('afterbegin', 
                    `<div class="sbn-import-err" style="margin-bottom:1rem;">❌ ${escapeHtml(data.error || 'Errore')}</div>`
                );
                importBtn.disabled = false;
                importBtn.textContent = '📥 Importa nel catalogo';
            }
        } catch (e) {
            previewContent.insertAdjacentHTML('afterbegin', 
                `<div class="sbn-import-err" style="margin-bottom:1rem;">Errore di rete: ${escapeHtml(e.message)}</div>`
            );
            importBtn.disabled = false;
            importBtn.textContent = '📥 Importa nel catalogo';
        }
    };

    window.closePreview = function() {
        modal.classList.remove('active');
        currentRecord = null;
        // Resetta il bottone per la prossima volta
        importBtn.style.display = '';
        importBtn.disabled = false;
        importBtn.textContent = '📥 Importa nel catalogo';
    };

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closePreview();
    });

    modal.addEventListener('click', (e) => {
        if (e.target === modal) closePreview();
    });

    function renderEditForm(r) {
        const fields = [
            { section: 'Dati principali' },
            { label: 'BID SBN', key: 'bid_sbn', readonly: true },
            { label: 'Titolo', key: 'titolo', type: 'text' },
            { label: 'Autore', key: 'autore', type: 'text' },
            { label: 'Editore', key: 'editore', type: 'text' },
            { label: 'Luogo', key: 'luogo', type: 'text' },
            { label: 'Anno', key: 'anno', type: 'text' },
            { label: 'ISBN', key: 'isbn', type: 'text', readonly: true },

            { section: 'Classificazione' },
            { label: 'Dewey', key: 'dewey_code', type: 'text' },
            { label: 'Descr. Dewey', key: 'dewey_des', type: 'text' },
            { label: 'Lingua', key: 'lingua', type: 'text' },
            { label: 'Paese', key: 'paese', type: 'text' },

            { section: 'Contenuto' },
            { label: 'Titolo uniforme', key: 'titolo_uniforme', type: 'text' },
            { label: 'Collezione', key: 'collezione', type: 'text' },
            { label: 'Note', key: 'note', type: 'textarea' },
            { label: 'Abstract', key: 'abstract', type: 'textarea' },
            { label: 'Indice', key: 'indice', type: 'textarea' },
            { label: 'Bibliografia', key: 'bibliografia', type: 'textarea' },
            { label: 'Dimensioni', key: 'dimensioni', type: 'text' },
            { label: 'Illustrazioni', key: 'illustrazioni', type: 'text' },
            { label: 'Soggetti', key: 'soggetti', type: 'textarea', 
              value: Array.isArray(r.soggetti) ? r.soggetti.join('; ') : (r.soggetti || ''),
              placeholder: 'Separati da punto e virgola: es. Resistenza; Storia d\'Italia' },

            { section: 'Tipo materiale e collezione' },
            { label: 'Tipo materiale', key: 'material_cd', type: 'select', 
              options: [
                { value: '1', label: 'Nastri audio' },
                { value: '2', label: 'Libro' },
                { value: '3', label: 'Cd audio' },
                { value: '4', label: 'Cd ROM' },
                { value: '6', label: 'Periodici' },
                { value: '7', label: 'Mappe' },
                { value: '8', label: 'Video/DVD' },
                { value: '9', label: 'Libro Digitale' },
                { value: '10', label: 'Opuscolo' }
              ], value: '2' },
            { label: 'Collezione', key: 'collection_cd', type: 'select',
              options: [
                { value: '1',  label: 'Narrativa (21 gg)' },
                { value: '2',  label: 'Saggistica (30 gg)' },
                { value: '3',  label: 'Cassette e nastri (7 gg)' },
                { value: '4',  label: 'Compact disc (7 gg)' },
                { value: '7',  label: 'Narrativa per ragazzi (30 gg)' },
                { value: '8',  label: 'Saggistica per ragazzi (30 gg)' },
                { value: '10', label: 'Periodici (14 gg)' },
                { value: '12', label: 'Video e DVDs (15 gg)' },
                { value: '13', label: 'Ebook (3 gg)' },
                { value: '14', label: 'Poesia (7 gg)' },
                { value: '15', label: 'Fumetti graphic novel (30 gg)' },
                { value: '16', label: 'Internati Militari Italiani (30 gg)' },
                { value: '17', label: 'Teatro (30 gg)' }
              ], value: '1' },

            { section: 'Collocazione fisica (obbligatorio)' },
            { label: 'Segnatura / Collocazione', key: 'call_nmbr1', type: 'text', 
              placeholder: 'Es. 910.019 VAN' },
            { label: '2ª collocazione', key: 'call_nmbr2', type: 'text' },
            { label: '3ª collocazione', key: 'call_nmbr3', type: 'text' },
            { label: 'Barcode copia', key: 'barcode', type: 'text', 
              value: 'SBN-' + (r.bid_sbn || '').replace(/\\/g, '').replace(/ITICCU/, '') + '-001' },
            { label: 'Stato copia', key: 'status_cd', type: 'select',
              options: [
                { value: 'in',  label: 'Disponibile (in)' },
                { value: 'out', label: 'In prestito (out)' },
                { value: 'ln',  label: 'Prestito interbib (ln)' },
                { value: 'mnd', label: 'Mancante/danneggiato (mnd)' }
              ], value: 'in' },
        ];

        let html = '<div class="sbn-edit-grid">';

        for (const f of fields) {
            if (f.section) {
                html += `<div class="sbn-edit-section">${escapeHtml(f.section)}</div>`;
                continue;
            }

            const val = f.value !== undefined ? f.value : (r[f.key] || '');
            const displayVal = Array.isArray(val) ? val.join('; ') : String(val);

            html += `<div class="sbn-edit-label">${escapeHtml(f.label)}</div>`;

            if (f.readonly) {
                html += `<div class="sbn-edit-readonly"><code>${escapeHtml(displayVal)}</code></div>`;
            } else if (f.type === 'textarea') {
                html += `<div class="sbn-edit-field"><textarea id="sbn-field-${f.key}" rows="2" placeholder="${escapeHtml(f.placeholder || '')}">${escapeHtml(displayVal)}</textarea></div>`;
            } else if (f.type === 'select') {
                html += `<div class="sbn-edit-field"><select id="sbn-field-${f.key}">`;
                for (const opt of (f.options || [])) {
                    const selected = (opt.value === String(f.value || '')) ? ' selected' : '';
                    html += `<option value="${escapeHtml(opt.value)}"${selected}>${escapeHtml(opt.label)}</option>`;
                }
                html += `</select></div>`;
            } else {
                html += `<div class="sbn-edit-field"><input type="text" id="sbn-field-${f.key}" value="${escapeHtml(displayVal)}" placeholder="${escapeHtml(f.placeholder || '')}"></div>`;
            }
        }

        html += '</div>';

        if (r.sbn_link || r.opac_link) {
            const link = r.sbn_link || r.opac_link;
            html += `<p style="margin-top:1rem;"><a href="${escapeHtml(link)}" target="_blank" style="color:#b00;font-weight:600;">→ Vedi su OPAC SBN</a></p>`;
        }

        return html;
    }

    function collectFormData() {
        const keys = ['titolo','autore','editore','luogo','anno','dewey_code','dewey_des',
                      'lingua','paese','titolo_uniforme','collezione','note','abstract',
                      'indice','bibliografia','dimensioni','illustrazioni','isbn'];
        const data = {};
        for (const k of keys) {
            const el = document.getElementById('sbn-field-' + k);
            if (el) data[k] = el.value.trim();
        }

        // Soggetti: splitta per ";" e pulisce
        const sogEl = document.getElementById('sbn-field-soggetti');
        if (sogEl) {
            data.soggetti = sogEl.value
                .split(';')
                .map(s => s.trim())
                .filter(s => s !== '');
        }

        // Tipo materiale e collezione
        const mat = document.getElementById('sbn-field-material_cd');
        if (mat) data.material_cd = mat.value;
        const col = document.getElementById('sbn-field-collection_cd');
        if (col) data.collection_cd = col.value;

        // Collocazione fisica
        const call1 = document.getElementById('sbn-field-call_nmbr1');
        if (call1) data.call_nmbr1 = call1.value.trim();
        const call2 = document.getElementById('sbn-field-call_nmbr2');
        if (call2) data.call_nmbr2 = call2.value.trim();
        const call3 = document.getElementById('sbn-field-call_nmbr3');
        if (call3) data.call_nmbr3 = call3.value.trim();
        const barcode = document.getElementById('sbn-field-barcode');
        if (barcode) data.barcode = barcode.value.trim();
        const status = document.getElementById('sbn-field-status_cd');
        if (status) data.status_cd = status.value;

        return data;
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }
})();
</script>

</section>