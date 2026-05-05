-- Migration 001 — Fix schema per supporto bcrypt e integrità dati
-- Compatibile con MariaDB 10.11 (IF NOT EXISTS su ADD COLUMN / ADD KEY).
-- MODIFY è idempotente: può essere rieseguito senza errori.
-- Da eseguire UNA VOLTA sul database di produzione prima del deploy.

-- ============================================================
-- 1. staff.pwd: char(32) → varchar(255)
--    char(32) tronca silenziosamente i 60 caratteri del bcrypt,
--    rendendo impossibile ogni login successivo all'upgrade.
-- ============================================================
ALTER TABLE `staff`
  MODIFY `pwd` varchar(255) NOT NULL;

-- ============================================================
-- 2. member.pass_user: char(32) → varchar(255)
--    Colonna legacy (patron_area ora scrive in patron_auth),
--    ma va allargata per coerenza con l'upgrade automatico MD5→bcrypt.
-- ============================================================
ALTER TABLE `member`
  MODIFY `pass_user` varchar(255) DEFAULT NULL;

-- ============================================================
-- 3. biblio_status_hist: aggiungi PRIMARY KEY auto-increment
--    La tabella non aveva PK: ammetteva righe duplicate.
--    I dati esistenti vengono preservati con histid assegnato
--    automaticamente da MariaDB in ordine sequenziale.
-- ============================================================
ALTER TABLE `biblio_status_hist`
  ADD COLUMN IF NOT EXISTS `histid` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
  ADD PRIMARY KEY IF NOT EXISTS (`histid`);

-- ============================================================
-- 4. member.barcode_nmbr: aggiungi indice UNIQUE
--    Usato per lookup ad ogni login patron e checkout prestito.
--    Senza indice → full table scan su ogni operazione.
-- ============================================================
ALTER TABLE `member`
  ADD UNIQUE KEY IF NOT EXISTS `uq_member_barcode` (`barcode_nmbr`);
