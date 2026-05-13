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
- Soggetti: `marc_split_subject_string()` in `lib/marc_helpers.php` spacca su ` -- ` e `;`; usare ovunque

## Funzionalità implementate (completate)

- ✅ Email automatiche complete: registrazione, prestito, restituzione, sollecito scadenza, prenotazione disponibile, admin notifica nuova registrazione — `EmailService`, `EmailQueue`, `cron_email.php`, templates in `templates/email/`
- ✅ Rinominare "patron" → "utente" nel frontend (solo testo visibile, non variabili)
- ✅ Normalizzazione e split soggetti: `marc_split_subject_string()` in `marc_helpers.php`; pagina topics e home usano split
- ✅ Nuovo account staff: attivazione via link email con token monouso 48h (`pages/staff_activate.php`)
- ✅ Ricerca semplice estesa a note, abstract, serie, luogo pubblicazione (EXISTS su biblio_field)
- ✅ Ridondanze scheda item: rimosso campo "Pagine" duplicato nell'header (era stesso di "Descrizione fisica" da MARC 300)
- ✅ Stampa barcode massiva: `pages/staff_barcodes.php` (standalone, JsBarcode CDN, filtri, CSS print)
- ✅ Multi-copia alla creazione: `staff_catalog_new.php` permette di creare N copie in un'unica operazione
- ✅ Campi MARC aggiuntivi in staff_catalog_edit: pub_place, dewey, lingua, paese, serie, bid_sbn
- ✅ Donazioni: form online con invio email a staff via `pages/donazioni.php`
- ✅ Email prenotazione libro: `templates/email/patron/hold_confirm.php` inviata da `pages/item.php`
- ✅ Collocazione fisica obbligatoria: validazione PHP + JS in `staff_catalog_new.php`
- ✅ Bug SBN import (ISBN/pagine vuoti): fix `sbnOpenModal` → chiama `preview_record` (detail=full) invece dei dati di ricerca; fix `parseIsbn(mixed)` type hint; normalizzazione `lingua` array→stringa

---

## Backlog residuo

### 1. Welcome email al patron che si autoregistra
- `pages/user_register.php` notifica lo staff ma non invia email di benvenuto al patron
- Template da creare: `templates/email/patron/welcome.php` (o riusare `patron/invite.php` senza activation link)
- Dati da passare: nome, cognome, barcode tessera

### 2. Miglioramento UX maschera SBN (non-bug)
- Importazione multipla di più record in sequenza senza ricaricare la pagina
- Feedback visivo progress durante import batch
- Gestione casi edge: record SBN con dati mancanti

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
