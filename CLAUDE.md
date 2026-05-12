# Biblioteca della Resistenza — ANPI Udine
## Contesto progetto

Sistema OPAC + area staff per la Biblioteca della Resistenza del Comitato Provinciale ANPI di Udine.

- **Stack**: PHP 8.3+, MySQL/MariaDB 10.11 (PDO), CSS puro, no framework JS
- **Root**: `/home/user/biblioteca/`
- **Branch di sviluppo**: `claude/restore-homepage-assets-ooN9j` → PR → merge in `main`
- **URL base pubblico**: configurato in `config.php` → `$cfg['app']['base_url']`

## Struttura

```
config.php          configurazione globale (DB, costanti)
index.php           router principale (GET ?page=)
lib/                classi PHP (PatronAuth, DB, ecc.)
pages/              una pagina per file (search.php, item.php, patron_area.php, ecc.)
templates/          header.php, footer.php
public/             webroot (assets, css, ajax_*.php)
sql/                schema DB di riferimento (anpiudine-or1d94_2.sql)
```

## DB — note critiche

- `biblio_copy`: **MyISAM**, PK composita `(bibid, copyid)`, `copyid AUTO_INCREMENT` per gruppo
  - INSERT senza copyid → MySQL assegna atomicamente per bibid; usare `lastInsertId()`
  - Barcode format: `str_pad(bibid,5,'0') . str_pad(copyid,2,'0')` → 7 chars
  - Unicità barcode garantita da `UNIQUE KEY uq_barcode (barcode_nmbr)`; `barcode_index` è ridondante
- `biblio_status_dm`: InnoDB, codici stato. **Tabella spesso vuota** → il codice PHP ha sempre una fallback map
- `patron_auth`: autenticazione utenti pubblici (separata da `member`)
- `member`: anagrafica soci; `barcode_nmbr` ha UNIQUE key

## Convenzioni codice

- `h()` per escape HTML output ovunque
- Disponibilità copie: `search_fetch_availability_map()` in `search.php` e `search_advanced.php`
  - Carica label da `biblio_status_dm`; fallback PHP map: `in→Disponibile, ln→In prestito, crt→Da reintegrare, ...`
  - Stato CSS: `available`, `unavailable`, `reserved`, `other`, `unknown`
- Default risultati per pagina: **10** (hardcoded, ignora `PAGE_SIZE` in config.php)
- CSS: `public/css/style.css` (file principale, include badge disponibilità); `public/css/item.css` (solo pagina item)

---

## Prossime sessioni — backlog prioritario

### 1. Email automatiche (utenti e admin)
- Trigger eventi: registrazione patron, prestito, restituzione, sollecito scadenza, prenotazione disponibile
- Admin: notifica nuova registrazione, sollecito batch per scaduti
- Infrastruttura: `email_queue` e `email_log` già presenti in DB → usarle
- Verificare `lib/` per eventuali classi mailer già presenti
- Configurazione SMTP in `config.php`

### 2. Rinominare "patron" → "utente" nel frontend
- Tutte le pagine pubbliche: `pages/patron_*.php`, `templates/header.php`
- Label visibili: "Accedi / Registrati", "Il mio account", "Connesso: …", "Esci"
- **Non rinominare** variabili PHP interne, chiavi sessione, nomi file — solo testo visibile all'utente
- Verificare anche messaggi di errore e form

### 3. Normalizzazione visualizzazione soggetti
- Decidere il formato: tag MARC usati (650, 600, 610, ecc.), separatore, ordinamento
- Verificare come sono salvati in `biblio_field` (tag + subfield_cd)
- Applicare coerentemente in: `pages/item.php`, risultati search, eventuale filtro per soggetto
- Valutare se linkare i soggetti a una ricerca per soggetto

### 4. Nuovo account staff: attivazione via link email
- Flusso: admin crea account → email con token → staff clicca link → imposta password
- Tabella `staff` (o equivalente): verificare struttura esistente
- Token: colonna `activation_token` + `token_expires_at` da aggiungere se non presenti
- Pagina pubblica: `pages/staff_activate.php` (nuova)
- Sicurezza: token monouso, scadenza 48h, HTTPS only

### 5. Ricerca full-text su tutti i campi SQL
- Attualmente la ricerca copre solo alcuni campi (titolo, autore, ecc.)
- Estendere a tutti i campi rilevanti di `biblio` e `biblio_field` (soggetti, abstract, note, ecc.)
- Valutare FULLTEXT index MySQL vs LIKE su campi concatenati
- Verificare impatto su performance con il volume dati attuale

### 7. Eliminare ridondanze nella scheda item
- Rivedere `pages/item.php`: identificare campi duplicati o visualizzati più volte
- Verificare coerenza con i dati reali in `biblio` e `biblio_field` (stessi dati esposti tramite tag MARC diversi)
- Obiettivo: scheda pulita, senza ripetizioni, leggibile dall'utente finale

### 6. Miglioramento maschera importazione da SBN
- Rivedere UX del form in `pages/staff_catalog_new.php` e `public/ajax_sbn_enrich.php`
- Possibili aree di miglioramento: feedback visivo durante import, gestione errori, anteprima record prima del salvataggio, importazione multipla
- Verificare casi edge: record SBN con dati mancanti, duplicati, MARCXML malformato

---

## SQL utili da applicare sul DB live (opzionali/pendenti)

```sql
-- Pulizia indice ridondante
ALTER TABLE biblio_copy DROP KEY barcode_index;

-- Traduzione "Para Reponer" (solo se biblio_status_dm ha dati)
UPDATE biblio_status_dm SET description='Da reintegrare' WHERE code='crt';

-- Pulizia disclaimer ACNP negli abstract (fare prima SELECT COUNT(*) per verifica)
DELETE FROM biblio_field
WHERE tag = 520 AND subfield_cd = 'a'
  AND field_data LIKE '%ACNP participating libraries%';
```
